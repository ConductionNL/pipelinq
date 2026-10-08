<?php

/**
 * Pipelinq WidenContactMomentCaseReference.
 *
 * Turns the single case reference on an existing contact moment into the
 * one-element ordered set `one-contact-moment-on-several-cases` introduced,
 * and names that one case as the primary.
 *
 * The step is idempotent: a row that already carries a non-empty
 * `caseReferences` is left untouched, so a second run rewrites nothing. A
 * contact moment with no case at all is left alone as well: the set has a
 * minimum of one and this step is a migration, not a place to invent a case.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\TicketService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: widen one case reference into a one-element set.
 *
 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
 */
class WidenContactMomentCaseReference implements IRepairStep {
	/**
	 * Upper bound on rows fetched in one pass.
	 *
	 * @var int
	 */
	private const BATCH_LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param TicketService $ticketService Resolver for the unified `ticket` supertype.
	 * @param IGroupManager $groupManager Group manager, used to resolve an acting admin.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private TicketService $ticketService,
		private IGroupManager $groupManager,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair step name.
	 *
	 * @return string Name.
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	public function getName(): string {
		return 'Widen a contact moment\'s single case reference into a one-element set';
	}//end getName()

	/**
	 * The fields to write on a stored contact moment, or null to leave it.
	 *
	 * Returning null on a row that already carries a set is what makes the
	 * step idempotent, and returning null on a row with no case at all is what
	 * keeps it a migration rather than a guess.
	 *
	 * @param array<string, mixed> $data The stored contact moment.
	 *
	 * @return array<string, mixed>|null The fields to write, or null.
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	public function widenedFields(array $data): ?array {
		$set = ($data['caseReferences'] ?? null);
		if (is_array($set) === true && $set !== []) {
			return null;
		}

		$single = trim((string)($data['caseReference'] ?? ''));
		if ($single === '') {
			return null;
		}

		return [
			'caseReferences' => [$single],
			'primaryCaseReference' => $single,
		];
	}//end widenedFields()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-contact-moment-on-several-cases/specs/contactmomenten/spec.md#requirement-a-contact-moment-references-a-case-semantically-req-cmd-002
	 */
	public function run(IOutput $output): void {
		if ($this->ticketService->isConfigured() === false) {
			$output->info('Ticket surface not configured — skipping the case reference widening.');
			return;
		}

		$objectService = $this->ticketService->getObjectService();
		$register = $this->ticketService->getRegisterId();
		$schema = $this->ticketService->getSchemaId();
		$actingAdmin = $this->actingAdmin();

		$widened = 0;
		$untouched = 0;

		$rows = $objectService->findAll(
			config: [
				'filters' => [
					'register' => $register,
					'schema' => $schema,
					'ticketType' => TicketService::TYPE_CONTACTMOMENT,
				],
				'limit' => self::BATCH_LIMIT,
			],
			_rbac: false,
			_multitenancy: false,
		);

		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data === null) {
				continue;
			}

			$fields = $this->widenedFields(data: $data);
			if ($fields === null) {
				$untouched++;
				continue;
			}

			$data = $this->ticketService->sanitizeForSave(payload: array_merge($data, $fields));

			try {
				$objectService->saveObject(
					object: $data,
					extend: [],
					register: $register,
					schema: $schema,
					uuid: (string)($data['id'] ?? ''),
					_rbac: false,
					_multitenancy: false,
					currentUser: $actingAdmin,
				);
				$widened++;
			} catch (Throwable $e) {
				$this->logger->error(
					'WidenContactMomentCaseReference: failed to save contact moment',
					['uuid' => ($data['id'] ?? ''), 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		$output->info(
			sprintf('Contact moment case sets: %d widened, %d already a set.', $widened, $untouched)
		);
	}//end run()

	/**
	 * Resolve an admin to act as while saving.
	 *
	 * @return IUser|null The first admin, or null when none exists.
	 */
	private function actingAdmin(): ?IUser {
		$admins = $this->groupManager->get('admin')?->getUsers() ?? [];

		return (array_values($admins)[0] ?? null);
	}//end actingAdmin()

	/**
	 * Coerce an OpenRegister row into a plain array.
	 *
	 * @param mixed $row The row as returned by the object service.
	 *
	 * @return array<string, mixed>|null The row data, or null when unusable.
	 */
	private function toArray(mixed $row): ?array {
		if (is_array($row) === true) {
			return $row;
		}

		if (($row instanceof \JsonSerializable) === true) {
			$data = $row->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end toArray()
}//end class
