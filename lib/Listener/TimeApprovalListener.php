<?php

/**
 * Pipelinq TimeApprovalListener.
 *
 * Dispatches an approved time entry to the Shillinq Work-In-Progress (WIP)
 * ledger and records the outcome on the entry's wipSyncStatus /
 * wipSyncedAt fields.
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
 * @spec openspec/changes/pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Event\TimeEntryApprovedEvent;
use OCA\Pipelinq\Service\ShillinqWipService;
use OCA\Pipelinq\Service\WipSyncNotifier;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Listener that dispatches an approved time entry to the Shillinq WIP ledger.
 *
 * Idempotent: a time entry already marked wipSyncStatus = synced is skipped
 * so a re-fired approval event cannot create a duplicate WIP entry.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001
 */
class TimeApprovalListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param ShillinqWipService $wipService The Shillinq WIP dispatch service.
     * @param WipSyncNotifier    $notifier   The admin failure notifier.
     * @param ContainerInterface $container  The DI container (OpenRegister ObjectService lookup).
     * @param IAppConfig         $appConfig  The app configuration.
     * @param LoggerInterface    $logger     The logger.
     */
    public function __construct(
        private ShillinqWipService $wipService,
        private WipSyncNotifier $notifier,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle a TimeEntryApprovedEvent.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/pipelinq-time-to-shillinq-wip/specs/pipelinq-time-to-shillinq-wip/spec.md#REQ-WIP-001
     */
    public function handle(Event $event): void
    {
        if (($event instanceof TimeEntryApprovedEvent) === false) {
            return;
        }

        if ($this->wipService->shouldDispatch() === false) {
            // REQ-WIP-001 missing-webhook-URL scenario: no dispatch, no notification.
            return;
        }

        $uuid = $event->getTimeEntryUuid();
        $data = $event->getTimeEntry();

        // Idempotency: never re-dispatch an already-synced entry (REQ-WIP-001 idempotent scenario).
        if (($data['wipSyncStatus'] ?? null) === 'synced') {
            return;
        }

        // Mark pending and persist before the dispatch begins (REQ-WIP-002).
        $data['wipSyncStatus'] = 'pending';
        $this->persist(uuid: $uuid, data: $data);

        $success = $this->wipService->dispatchWipEvent(
            timeEntry: $data,
            approvedBy: $event->getApprovedBy(),
            approvedAt: $event->getApprovedAt()
        );

        if ($success === true) {
            $data['wipSyncStatus'] = 'synced';
            $data['wipSyncedAt']   = $this->wipService->now();
            $this->persist(uuid: $uuid, data: $data);
            return;
        }

        // Dispatch failed after retries (REQ-WIP-003): mark failed and notify admins.
        $data['wipSyncStatus'] = 'failed';
        $this->persist(uuid: $uuid, data: $data);
        $this->notifier->notifyFailure(
            title: (string) ($data['title'] ?? ''),
            uuid: $uuid
        );
    }//end handle()

    /**
     * Persist the mutated time entry data back to OpenRegister.
     *
     * No-op when the timeEntry register/schema config keys are unset so the
     * listener is safe to install before the schema is bound.
     *
     * @param string               $uuid The time entry UUID.
     * @param array<string, mixed> $data The mutated time entry data.
     *
     * @return void
     */
    private function persist(string $uuid, array $data): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'timeEntry_schema', '');
        if ($register === '' || $schema === '' || $uuid === '') {
            return;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $objectService->saveObject(
                object: $data,
                extend: [],
                register: $register,
                schema: $schema,
                uuid: $uuid
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: failed to persist time entry WIP sync status',
                ['exception' => $e->getMessage(), 'uuid' => $uuid]
            );
        }//end try
    }//end persist()
}//end class
