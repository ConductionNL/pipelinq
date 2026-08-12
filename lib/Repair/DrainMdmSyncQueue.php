<?php

/**
 * Pipelinq DrainMdmSyncQueue repair step.
 *
 * One-time drain of in-flight `syncQueueItem` rows through OpenRegister's
 * WebhookService, part of retiring the app-side MDM sync queue (ADR-045
 * forbids parallel queue subsystems). Every non-terminal row (queued /
 * sending / failed) is dispatched exactly once with the original
 * targetSystem/changeType/masterEntity/payload envelope — from there OR's
 * webhook logs and WebhookRetryJob own the delivery outcome. Drained rows are
 * marked terminal but never deleted; rows that fail to hand off stay
 * non-terminal and are reported, so a re-run picks up exactly the remainder.
 *
 * Terminal drain marker: the syncQueueItem status enum predates this change
 * (queued/sending/sent/acknowledged/failed/dead-letter — no `drained` value),
 * so a drained row is encoded schema-valid as `status: sent` with
 * `acknowledgmentReference: drained:<timestamp>`; `sent` was never written by
 * the retired SyncQueueService, making the encoding unambiguous.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-014-one-time-drain-of-in-flight-queue-rows
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Service\Mdm\MdmObjectRepository;
use OCP\EventDispatcher\Event;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Drains in-flight MDM sync-queue rows through OR WebhookService once.
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-014-one-time-drain-of-in-flight-queue-rows
 */
class DrainMdmSyncQueue implements IRepairStep {
	/**
	 * The retired queue's schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA = 'syncQueueItem';

	/**
	 * CloudEvent name webhooks subscribe to for MDM sync delivery (mirrors
	 * ObjectsMergedSyncListener::EVENT_SYNC — kept literal so the drain stays
	 * correct even after the listener evolves).
	 *
	 * @var string
	 */
	private const EVENT_SYNC = 'pipelinq.mdm.sync';

	/**
	 * Statuses considered in-flight (everything else is terminal).
	 *
	 * @var array<int, string>
	 */
	private const NON_TERMINAL = ['queued', 'sending', 'failed'];

	/**
	 * Constructor.
	 *
	 * @param MdmObjectRepository $repository The MDM object repository.
	 * @param ContainerInterface $container The DI container (lazy OR WebhookService resolve).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly MdmObjectRepository $repository,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable repair step name.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Drain in-flight Pipelinq MDM sync-queue rows through OpenRegister WebhookService (retire-mdm-sync-queue)';
	}//end getName()

	/**
	 * Dispatch every non-terminal queue row once and mark it drained.
	 *
	 * Idempotent: a re-run only sees rows still non-terminal (i.e. rows whose
	 * hand-off failed last time); everything drained or already terminal is
	 * skipped. Installs without the register/schema (fresh installs — the
	 * schema is no longer provisioned) are a logged no-op.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-014-one-time-drain-of-in-flight-queue-rows
	 */
	public function run(IOutput $output): void {
		try {
			$items = $this->repository->findAll(self::SCHEMA);
		} catch (\Throwable $e) {
			// Fresh install / unprovisioned register / schema already gone.
			$output->info('Pipelinq MDM sync-queue drain: no queue schema present, nothing to drain (' . $e->getMessage() . ').');
			return;
		}

		if ($items === []) {
			$output->info('Pipelinq MDM sync-queue drain: queue is empty, nothing to drain.');
			return;
		}

		$webhookService = $this->resolveWebhookService();
		if ($webhookService === null) {
			// Rows stay untouched; the next upgrade re-runs the drain.
			$output->warning('Pipelinq MDM sync-queue drain: OpenRegister WebhookService unavailable — rows left in place for a later run.');
			return;
		}

		$drained = 0;
		$skipped = 0;
		$failed = 0;

		foreach ($items as $item) {
			$status = (string)($item['status'] ?? '');
			if (in_array($status, self::NON_TERMINAL, true) === false) {
				$skipped++;
				continue;
			}

			$id = (string)($item['id'] ?? ($item['uuid'] ?? ''));

			try {
				$webhookService->dispatchEvent(
					_event: new Event(),
					eventName: self::EVENT_SYNC,
					payload: [
						'targetSystem' => ($item['targetSystem'] ?? ''),
						'changeType' => ($item['changeType'] ?? ''),
						'masterEntity' => ($item['masterEntity'] ?? ''),
						'payload' => ($item['payload'] ?? []),
					]
				);

				$item['status'] = 'sent';
				$item['errorMessage'] = '';
				$item['acknowledgmentReference'] = 'drained:' . $this->repository->now();
				$this->repository->save(self::SCHEMA, $item, $this->nullableId(id: $id));
				$drained++;
			} catch (\Throwable $e) {
				// Hand-off failed: leave the row non-terminal and report it.
				$failed++;
				$this->logger->error(
					'Pipelinq MDM sync-queue drain: hand-off failed; row left in place',
					['item' => $id, 'target' => ($item['targetSystem'] ?? ''), 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		$summary = sprintf(
			'Pipelinq MDM sync-queue drain: %d drained, %d skipped (terminal), %d failed (left non-terminal).',
			$drained,
			$skipped,
			$failed,
		);
		$output->info($summary);
		$this->logger->info($summary);

		if ($failed > 0) {
			$output->warning('Pipelinq MDM sync-queue drain: ' . $failed . ' row(s) could not be handed off — they remain for the next run.');
		}
	}//end run()

	/**
	 * Normalise an empty id to null so save() generates a fresh uuid.
	 *
	 * @param string $id The candidate id.
	 *
	 * @return string|null The id, or null when empty.
	 */
	private function nullableId(string $id): ?string {
		if ($id === '') {
			return null;
		}

		return $id;
	}//end nullableId()

	/**
	 * Lazily resolve OpenRegister's WebhookService, or null when OR is absent.
	 *
	 * @return object|null The WebhookService, or null.
	 */
	private function resolveWebhookService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\WebhookService');
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveWebhookService()
}//end class
