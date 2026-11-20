<?php

/**
 * Pipelinq BackfillTicketPhoneE164 repair step.
 *
 * Fills `phoneE164` on tickets stored before the field existed.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;
use OCA\Pipelinq\Kernel\Contracts\TicketPhoneNumberInterface;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCA\Pipelinq\Util\FirstAdminUser;
use OCA\Pipelinq\Util\JsonSerializableToArray;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks the tickets in batches and stores the number each is about in E.164
 * (design D2). Idempotent: a ticket whose `phoneE164` already holds that
 * number, or that names no number that normalises, is left alone, so a second
 * run writes nothing.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.3
 */
class BackfillTicketPhoneE164 implements IRepairStep {
	use FirstAdminUser;
	use JsonSerializableToArray;

	/**
	 * Tickets read per batch.
	 */
	public const BATCH_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param TicketServiceInterface     $ticketService Resolver for the unified `ticket` schema.
	 * @param TicketPhoneNumberInterface $phoneNumber   Reads and normalises a ticket's number.
	 * @param IGroupManager     $groupManager  Resolves an admin to act as while saving.
	 * @param LoggerInterface   $logger        PSR logger.
	 */
	public function __construct(
		private readonly TicketServiceInterface $ticketService,
		private readonly TicketPhoneNumberInterface $phoneNumber,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.3
	 */
	public function getName(): string {
		return 'Store the phone number of every ticket in E.164 for the phone search';
	}//end getName()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.3
	 */
	public function run(IOutput $output): void {
		if ($this->ticketService->isConfigured() === false) {
			$output->info('Ticket surface not configured — skipping the ticket phone backfill.');
			return;
		}

		$tally  = ['updated' => 0, 'unchanged' => 0];
		$offset = 0;
		do {
			$rows = $this->batch(offset: $offset);
			foreach ($rows as $row) {
				$tally[$this->backfill(row: $row)]++;
			}

			$offset += self::BATCH_SIZE;
		} while (count($rows) === self::BATCH_SIZE);

		$output->info(
			sprintf('Ticket phone numbers: %d stored in E.164, %d unchanged.', $tally['updated'], $tally['unchanged'])
		);
	}//end run()

	/**
	 * One batch of tickets, read with RBAC off: a repair step has no session,
	 * and an RBAC-filtered read would see only what an anonymous caller may.
	 *
	 * @param int $offset Rows to skip.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function batch(int $offset): array {
		try {
			$rows = $this->ticketService->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $this->ticketService->getRegisterId(),
						'schema' => $this->ticketService->getSchemaId(),
					],
					'limit' => self::BATCH_SIZE,
					'offset' => $offset,
				],
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->error('BackfillTicketPhoneE164: reading tickets failed', ['exception' => $e->getMessage()]);
			return [];
		}

		$batch = [];
		foreach ($rows as $row) {
			$data = $this->toArray(row: $row);
			if ($data !== null) {
				$batch[] = $data;
			}
		}

		return $batch;
	}//end batch()

	/**
	 * Store one ticket's number when it differs from what is stored.
	 *
	 * @param array<string,mixed> $row The ticket.
	 *
	 * @return string `updated` or `unchanged`.
	 */
	private function backfill(array $row): string {
		$e164 = $this->phoneNumber->e164(ticket: $row);
		$uuid = (string) ($row['id'] ?? ($row['uuid'] ?? ''));
		if ($e164 === null || $uuid === '' || ($row[TicketPhoneNumberInterface::FIELD] ?? null) === $e164) {
			return 'unchanged';
		}

		$row[TicketPhoneNumberInterface::FIELD] = $e164;

		try {
			$this->ticketService->getObjectService()->saveObject(
				object: $this->ticketService->sanitizeForSave(payload: $row),
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(
							register: $this->ticketService->getRegisterId(),
							schema: $this->ticketService->getSchemaId()
						),
					uuid: $uuid,
					actor: new SaveActor(currentUser: $this->actingAdmin())
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'BackfillTicketPhoneE164: failed to save ticket',
				['uuid' => $uuid, 'exception' => $e->getMessage()]
			);
			return 'unchanged';
		}

		return 'updated';
	}//end backfill()
}//end class
