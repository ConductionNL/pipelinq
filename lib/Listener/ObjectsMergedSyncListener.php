<?php

/**
 * Pipelinq ObjectsMergedSyncListener.
 *
 * Subscribes to OpenRegister's `ObjectsMergedEvent` — fired after OR's merge
 * engine executes or reverses a Master Entity merge — and dispatches one
 * downstream sync webhook per target system directly through OpenRegister's
 * WebhookService (queueing, per-delivery logging, and retries are OR's job:
 * webhook logs + WebhookRetryJob). This replaces the retired app-side sync
 * queue (`SyncQueueService` + `syncQueueItem` rows), the parallel-queue
 * anti-pattern ADR-045 forbids. The listener also projects the survivor's
 * golden record onto its Pipelinq OR schema instance event-driven, replacing
 * the retired hourly MdmOpenRegisterSyncJob poller.
 *
 * Per ADR-041 cross-app propagation is an EVENT subscription, never a phantom
 * RPC into OpenRegister internals; the WebhookService is resolved lazily so
 * pipelinq still boots without OpenRegister.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-006--downstream-sync-queue-with-retries-and-confirmation
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\Pipelinq\Service\Mdm\MdmObjectRepository;
use OCA\Pipelinq\Service\Mdm\OpenRegisterSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Dispatches downstream sync webhooks for each OpenRegister merge / reversal.
 *
 * @implements IEventListener<Event>
 */
class ObjectsMergedSyncListener implements IEventListener
{
    /**
     * CloudEvent name webhooks subscribe to for MDM sync delivery.
     *
     * @var string
     */
    public const EVENT_SYNC = 'pipelinq.mdm.sync';

    /**
     * Downstream apps notified on every merge / reversal (mirrors the systems
     * the retired MergeService fanned out to).
     *
     * @var array<int, string>
     */
    private const DOWNSTREAM_SYSTEMS = ['shillinq', 'procest', 'scholiq', 'opencatalogi', 'decidesk'];

    /**
     * Constructor.
     *
     * @param ContainerInterface      $container  The DI container (lazy OR WebhookService resolve).
     * @param MdmObjectRepository     $repository The MDM object repository (survivor read).
     * @param OpenRegisterSyncService $orSync     Golden-record → OR schema projection.
     * @param LoggerInterface         $logger     The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private MdmObjectRepository $repository,
        private OpenRegisterSyncService $orSync,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an OpenRegister ObjectsMergedEvent.
     *
     * Dispatch failures never block the merge save: every leg is wrapped so a
     * Throwable is logged instead of escaping into OR's merge transaction.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/specs/master-data-management/spec.md#requirement-req-mdm-006--downstream-sync-queue-with-retries-and-confirmation
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectsMergedEvent) === false) {
            return;
        }

        $survivorUuid    = $event->getSurvivorUuid();
        $mergedFromUuids = $event->getMergedFromUuids();
        $isReversal      = $event->isReversal();

        $changeType = 'merge';
        if ($isReversal === true) {
            $changeType = 'reverse-merge';
        }

        // Read the survivor's OR-materialised golden record for the payload;
        // best-effort — an unavailable survivor still dispatches the change.
        $goldenRecord = [];
        try {
            $survivor = $this->repository->findMasterEntity($survivorUuid);
            if (is_array($survivor) === true) {
                $goldenRecord = ($survivor['goldenRecord'] ?? []);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq MDM: could not read survivor golden record for merge sync',
                ['survivor' => $survivorUuid, 'exception' => $e->getMessage()]
            );
        }

        $payload = [
            'mergedInto'       => $survivorUuid,
            'mergedFrom'       => $mergedFromUuids,
            'mergeOperationId' => $event->getMergeOperationId(),
            'isReversal'       => $isReversal,
            'goldenRecord'     => $goldenRecord,
        ];

        $this->dispatchDownstream(
            event: $event,
            survivorUuid: $survivorUuid,
            changeType: $changeType,
            payload: $payload
        );

        // Event-driven golden-record projection (replaces the retired hourly
        // MdmOpenRegisterSyncJob poller) — a merge or reversal is exactly the
        // moment the survivor's golden record changed.
        try {
            $this->orSync->syncMasterToRegister($survivorUuid);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq MDM: golden-record projection failed after merge',
                ['survivor' => $survivorUuid, 'exception' => $e->getMessage()]
            );
        }
    }//end handle()

    /**
     * Dispatch one sync webhook per downstream system via OR WebhookService.
     *
     * Delivery, per-target webhook logs, and retries are owned by OpenRegister
     * (WebhookRetryJob); pipelinq keeps zero queue rows and zero retry code.
     *
     * @param Event                $event        The originating merge event.
     * @param string               $survivorUuid The surviving master entity uuid.
     * @param string               $changeType   `merge` or `reverse-merge`.
     * @param array<string, mixed> $payload      The merge payload envelope body.
     *
     * @return void
     */
    private function dispatchDownstream(
        Event $event,
        string $survivorUuid,
        string $changeType,
        array $payload,
    ): void {
        $webhookService = $this->resolveWebhookService();
        if ($webhookService === null) {
            // OR absent: logged no-op — the merge save is never blocked.
            $this->logger->warning(
                'Pipelinq MDM: OpenRegister WebhookService unavailable; downstream merge sync skipped',
                ['survivor' => $survivorUuid, 'changeType' => $changeType]
            );
            return;
        }

        foreach (self::DOWNSTREAM_SYSTEMS as $system) {
            try {
                $webhookService->dispatchEvent(
                    _event: $event,
                    eventName: self::EVENT_SYNC,
                    payload: [
                        'targetSystem' => $system,
                        'changeType'   => $changeType,
                        'masterEntity' => $survivorUuid,
                        'payload'      => $payload,
                    ]
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Pipelinq MDM: downstream merge sync dispatch failed',
                    ['survivor' => $survivorUuid, 'target' => $system, 'exception' => $e->getMessage()]
                );
            }//end try
        }//end foreach
    }//end dispatchDownstream()

    /**
     * Lazily resolve OpenRegister's WebhookService, or null when OR is absent.
     *
     * @return object|null The WebhookService, or null.
     */
    private function resolveWebhookService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\WebhookService');
        } catch (\Throwable $e) {
            return null;
        }
    }//end resolveWebhookService()
}//end class
