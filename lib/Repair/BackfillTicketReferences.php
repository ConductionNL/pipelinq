<?php

/**
 * Pipelinq BackfillTicketReferences repair step.
 *
 * Gives every request and complaint stored before the `reference` property
 * existed its reference number.
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
 * @spec openspec/changes/archive/2026-11-26-ticket-leaf-closed-reference-assignee/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\SaveActor;
use OCA\Pipelinq\Kernel\Contracts\TicketLeafProviderInterface;
use OCA\Pipelinq\Kernel\Contracts\TicketReferenceSequenceInterface;
use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;
use OCA\Pipelinq\Util\FirstAdminUser;
use OCA\Pipelinq\Util\JsonSerializableToArray;
use OCP\IGroupManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Walks the tickets in batches and draws a reference number for every request
 * and complaint that has none, from the same yearly sequence new tickets use,
 * so a backfilled number is never handed out twice. Idempotent: a ticket that
 * already has a reference, or is not a request or complaint, is left alone, so
 * a second run writes nothing.
 *
 * @spec openspec/changes/archive/2026-11-26-ticket-leaf-closed-reference-assignee/tasks.md#task-3.1
 */
class BackfillTicketReferences implements IRepairStep {
	use FirstAdminUser;
	use JsonSerializableToArray;

	/**
	 * Tickets read per batch.
	 */
	public const BATCH_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param TicketServiceInterface           $ticketService Resolver for the unified `ticket` schema.
	 * @param TicketReferenceSequenceInterface $sequence      Allocates the reference numbers.
	 * @param IGroupManager                    $groupManager  Resolves an admin to act as while saving.
	 * @param LoggerInterface                  $logger        PSR logger.
	 */
	public function __construct(
		private readonly TicketServiceInterface $ticketService,
		private readonly TicketReferenceSequenceInterface $sequence,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-11-26-ticket-leaf-closed-reference-assignee/tasks.md#task-3.1
	 */
	#[Override]
	public function getName(): string {
		return 'Give every request and complaint without one its reference number';
	}//end getName()

	/**
	 * Run the repair.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-11-26-ticket-leaf-closed-reference-assignee/tasks.md#task-3.1
	 */
	#[Override]
	public function run(IOutput $output): void {
		if ($this->ticketService->isConfigured() === false) {
			$output->info('Ticket surface not configured — skipping the ticket reference backfill.');
			return;
		}

		$tally  = ['numbered' => 0, 'unchanged' => 0, 'failed' => 0];
		$offset = 0;
		do {
			$rows = $this->batch(offset: $offset);
			$read = count($rows);
			foreach ($rows as $row) {
				$tally[$this->backfill(row: $row)]++;
			}

			$offset += self::BATCH_SIZE;
		} while ($read === self::BATCH_SIZE);

		$output->info(
			sprintf(
				'Ticket references: %d numbered, %d unchanged, %d failed.',
				$tally['numbered'],
				$tally['unchanged'],
				$tally['failed']
			)
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
			$this->logger->error('BackfillTicketReferences: reading tickets failed', ['exception' => $e->getMessage()]);
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
	 * Number one ticket when it is a request or complaint without a reference.
	 *
	 * @param array<string,mixed> $row The ticket.
	 *
	 * @return string `numbered`, `unchanged` or `failed`.
	 */
	private function backfill(array $row): string {
		$uuid = (string) ($row['id'] ?? ($row['uuid'] ?? ''));
		if ($uuid === '' || $this->needsReference(ticket: $row) === false) {
			return 'unchanged';
		}

		try {
			$row['reference'] = $this->sequence->next();
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
				'BackfillTicketReferences: failed to number ticket',
				['uuid' => $uuid, 'exception' => $e->getMessage()]
			);
			return 'failed';
		}

		return 'numbered';
	}//end backfill()

	/**
	 * Whether the ticket is a request or complaint that has no reference yet.
	 *
	 * @param array<string,mixed> $ticket The ticket.
	 *
	 * @return bool True when a reference must be drawn.
	 */
	private function needsReference(array $ticket): bool {
		$numbered = in_array((string) ($ticket['ticketType'] ?? ''), TicketLeafProviderInterface::TICKET_TYPES, true);
		return $numbered === true && trim((string) ($ticket['reference'] ?? '')) === '';
	}//end needsReference()
}//end class
