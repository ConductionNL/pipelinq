<?php

/**
 * Pipelinq BackfillConversationFields.
 *
 * Two backfills the conversation config reads:
 * - a ticket answered before answers were kept in `portalAnswers` gets its
 *   `customerMessage` there, so the thread shows it;
 * - a WhatsApp or SMS message gets the `clientId` of its contact, so a client
 *   page lists it.
 *
 * Idempotent and non-destructive: a ticket whose message is already an answer
 * and a message that already names a client are left alone.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Listener\ChannelMessageClientListener;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Backfills `portalAnswers` on tickets and `clientId` on messages.
 *
 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md#requirement-the-thread-shows-an-answer-given-before-answers-were-kept
 */
class BackfillConversationFields implements IRepairStep {

	/**
	 * Upper bound on records read per schema.
	 *
	 * @var int
	 */
	private const BATCH_LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface       $objectService OpenRegister object service.
	 * @param IAppConfig                   $appConfig     App config, source of the register and schema ids.
	 * @param ChannelMessageClientListener $clients       Finds the client of a contact.
	 * @param LoggerInterface              $logger        PSR logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly ChannelMessageClientListener $clients,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md#requirement-the-thread-shows-an-answer-given-before-answers-were-kept
	 */
	public function getName(): string {
		return 'Backfill ticket answers and message clients for the conversation (idempotent, non-destructive)';
	}//end getName()

	/**
	 * Run both backfills.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md#requirement-the-thread-shows-an-answer-given-before-answers-were-kept
	 */
	public function run(IOutput $output): void {
		$fixed = 0;
		foreach ($this->rows(schemaKey: 'ticket_schema') as $ticket) {
			$fixed += $this->patch(schemaKey: 'ticket_schema', id: (string)$ticket['id'], patch: self::answerPatch(ticket: $ticket));
		}

		foreach ($this->rows(schemaKey: 'message_schema') as $message) {
			$patch = [];
			if (trim((string)($message['clientId'] ?? '')) === '' && trim((string)($message['contactId'] ?? '')) !== '') {
				$patch = ['clientId' => $this->clients->clientOf(contactId: (string)$message['contactId'])];
				if ($patch['clientId'] === '') {
					$patch = [];
				}
			}

			$fixed += $this->patch(schemaKey: 'message_schema', id: (string)$message['id'], patch: $patch);
		}

		$output->info(sprintf('BackfillConversationFields: %d record(s) backfilled', $fixed));
	}//end run()

	/**
	 * The patch that puts a ticket's legacy answer in `portalAnswers`.
	 *
	 * @param array<string, mixed> $ticket The ticket.
	 *
	 * @return array<string, mixed> The patch, [] when nothing is missing.
	 *
	 * @spec openspec/changes/answer-and-saved-replies-on-the-server/specs/dossier-questions/spec.md#requirement-the-thread-shows-an-answer-given-before-answers-were-kept
	 */
	public static function answerPatch(array $ticket): array {
		$current = trim((string)($ticket['customerMessage'] ?? ''));
		if ($current === '') {
			return [];
		}

		$answers = ($ticket['portalAnswers'] ?? []);
		if (is_array($answers) === false) {
			$answers = [];
		}
		foreach ($answers as $answer) {
			if (is_array($answer) === true && trim((string)($answer['message'] ?? '')) === $current) {
				return [];
			}
		}

		$answers[] = ['message' => $current, 'createdAt' => (string)($ticket['@self']['updated'] ?? '')];
		return ['portalAnswers' => $answers];
	}//end answerPatch()

	/**
	 * Save a patch as the system.
	 *
	 * @param string               $schemaKey The schema config key.
	 * @param string               $id        The object uuid.
	 * @param array<string, mixed> $patch     The fields to set.
	 *
	 * @return int 1 when saved, 0 otherwise.
	 */
	private function patch(string $schemaKey, string $id, array $patch): int {
		if ($patch === [] || $id === '') {
			return 0;
		}

		try {
			$this->objectService->patchObject(
				objectId: $id,
				data: $patch,
				register: $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
				schema: $this->appConfig->getValueString(Application::APP_ID, $schemaKey, ''),
				_rbac: false,
				_multitenancy: false
			);
			return 1;
		} catch (Throwable $e) {
			$this->logger->warning('BackfillConversationFields: a record could not be saved', ['id' => $id, 'error' => $e->getMessage()]);
			return 0;
		}
	}//end patch()

	/**
	 * Every record of one schema, with its id.
	 *
	 * @param string $schemaKey The schema config key.
	 *
	 * @return array<int, array<string, mixed>> The records.
	 */
	private function rows(string $schemaKey): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->objectService->findAll(
				config: ['filters' => ['register' => $register, 'schema' => $schema], 'limit' => self::BATCH_LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('BackfillConversationFields: records could not be read', ['schema' => $schemaKey, 'error' => $e->getMessage()]);
			return [];
		}

		$out = [];
		foreach ($rows as $row) {
			$out[] = $this->rowData(row: $row);
		}

		return $out;
	}//end rows()

	/**
	 * A findAll row as data with its id.
	 *
	 * @param mixed $row An array or an object entity.
	 *
	 * @return array<string, mixed> The data.
	 */
	private function rowData(mixed $row): array {
		if (is_array($row) === true) {
			$row['id'] = (string)($row['id'] ?? '');
			return $row;
		}

		$data = (array)$row->getObject();
		$data['id'] = (string)($data['id'] ?? $row->getUuid());
		return $data;
	}//end rowData()
}//end class
