<?php

/**
 * Pipelinq ObjectsMergedSyncListener.
 *
 * Subscribes to OpenRegister's `ObjectsMergedEvent` — fired after OR's merge
 * engine executes or reverses a Master Entity merge — and enqueues a downstream
 * sync item per target system. This replaces the retired app-side MergeService,
 * whose `executeMerge()` / `reverseMerge()` used to call the sync queue directly.
 * Per ADR-041 cross-app propagation is an EVENT subscription, never a phantom RPC
 * into OpenRegister internals; delivery still runs on OR's WebhookService via
 * SyncQueueService.
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
 * @spec openspec/changes/mdm-consume-or-surface-backend/specs/master-data-management/spec.md#REQ-MDM-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCA\Pipelinq\Service\Mdm\MdmObjectRepository;
use OCA\Pipelinq\Service\Mdm\SyncQueueService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Enqueues downstream sync for each OpenRegister merge / reversal.
 *
 * @implements IEventListener<Event>
 */
class ObjectsMergedSyncListener implements IEventListener
{
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
     * @param SyncQueueService    $syncQueue  The outbound sync queue service.
     * @param MdmObjectRepository $repository The MDM object repository (survivor read).
     * @param LoggerInterface     $logger     The logger.
     */
    public function __construct(
        private SyncQueueService $syncQueue,
        private MdmObjectRepository $repository,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an OpenRegister ObjectsMergedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/mdm-consume-or-surface-backend/specs/master-data-management/spec.md#REQ-MDM-004
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
        // best-effort — an unavailable survivor still enqueues the change.
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

        foreach (self::DOWNSTREAM_SYSTEMS as $system) {
            try {
                $this->syncQueue->enqueueSync(
                    masterEntityId: $survivorUuid,
                    targetSystem: $system,
                    changeType: $changeType,
                    payload: $payload
                );
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Pipelinq MDM: failed to enqueue downstream merge sync',
                    ['survivor' => $survivorUuid, 'target' => $system, 'exception' => $e->getMessage()]
                );
            }
        }
    }//end handle()
}//end class
