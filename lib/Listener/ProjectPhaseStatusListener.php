<?php

/**
 * Pipelinq ProjectPhaseStatusListener.
 *
 * Dispatches a Shillinq ledger status-change event when a project's status
 * changes, or when a project phase's status changes (resolved in the parent
 * project's context), and records the outcome on the project.
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
 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\LedgerSyncNotifier;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\ShillinqLedgerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Listener that dispatches project status changes to the Shillinq ledger.
 *
 * Filtered to the project and projectPhase schemas. A status change on a
 * project dispatches directly; a status change on a phase is resolved to its
 * parent project, whose ledger sync status is then updated. Updates that do
 * not change status are ignored.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-002
 */
class ProjectPhaseStatusListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param SchemaMapService      $schemaMapService The schema map service.
     * @param ShillinqLedgerService $ledgerService    The Shillinq ledger service.
     * @param LedgerSyncNotifier    $notifier         The admin failure notifier.
     * @param ContainerInterface    $container        The DI container (OpenRegister ObjectService lookup).
     * @param IAppConfig            $appConfig        The app configuration.
     * @param LoggerInterface       $logger           The logger.
     */
    public function __construct(
        private SchemaMapService $schemaMapService,
        private ShillinqLedgerService $ledgerService,
        private LedgerSyncNotifier $notifier,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an object-updated event.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-002-01
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectUpdatedEvent) === false) {
            return;
        }

        $newEntity = $event->getNewObject();
        $oldEntity = $event->getOldObject();
        if ($oldEntity === null) {
            return;
        }

        $entityType = $this->schemaMapService->resolveEntityType(schemaId: $newEntity->getSchema());
        if ($entityType !== 'project' && $entityType !== 'projectPhase') {
            return;
        }

        if ($this->ledgerService->shouldDispatch() === false) {
            return;
        }

        $newData   = $newEntity->getObject();
        $oldData   = $oldEntity->getObject();
        $oldStatus = (string) ($oldData['status'] ?? '');
        $newStatus = (string) ($newData['status'] ?? '');

        // Only act on an actual status change (REQ-PLG-002-01).
        if ($oldStatus === $newStatus) {
            return;
        }

        if ($entityType === 'projectPhase') {
            // Resolve the parent project and dispatch in its context (REQ-PLG-002-05).
            $project = $this->fetchProject(uuid: (string) ($newData['project'] ?? ''));
            if ($project === null) {
                return;
            }

            $projectUuid = (string) ($project['id'] ?? $project['uuid'] ?? '');
            $this->dispatchAndRecord(
                project: $project,
                projectUuid: $projectUuid,
                oldStatus: $oldStatus,
                newStatus: $newStatus
            );
            return;
        }

        // Direct project status change.
        $this->dispatchAndRecord(
            project: $newData,
            projectUuid: (string) $newEntity->getUuid(),
            oldStatus: $oldStatus,
            newStatus: $newStatus
        );
    }//end handle()

    /**
     * Dispatch a status-change ledger event and record the outcome on the project.
     *
     * Resets ledgerSyncStatus to pending before the dispatch, then resolves it to
     * synced or failed (REQ-PLG-002-03).
     *
     * @param array<string, mixed> $project     The project object data.
     * @param string               $projectUuid The project UUID.
     * @param string               $oldStatus   The previous status value.
     * @param string               $newStatus   The new status value.
     *
     * @return void
     */
    private function dispatchAndRecord(array $project, string $projectUuid, string $oldStatus, string $newStatus): void
    {
        if ($projectUuid === '') {
            return;
        }

        $project['ledgerSyncStatus'] = 'pending';
        $this->persist(uuid: $projectUuid, data: $project);

        $success = $this->ledgerService->dispatchPhaseChangeEvent(
            project: $project,
            oldStatus: $oldStatus,
            newStatus: $newStatus
        );

        if ($success === true) {
            $project['ledgerSyncStatus'] = 'synced';
            $project['ledgerSyncedAt']   = $this->ledgerService->now();
            $this->persist(uuid: $projectUuid, data: $project);
            return;
        }

        $project['ledgerSyncStatus'] = 'failed';
        $this->persist(uuid: $projectUuid, data: $project);
        $this->notifier->notifyFailure(
            projectName: (string) ($project['name'] ?? ''),
            eventType: 'status-changed',
            uuid: $projectUuid
        );
    }//end dispatchAndRecord()

    /**
     * Fetch a project object's data by UUID.
     *
     * @param string $uuid The project UUID.
     *
     * @return array<string, mixed>|null The project data, or null when not found.
     */
    private function fetchProject(string $uuid): ?array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'project_schema', '');
        if ($register === '' || $schema === '' || $uuid === '') {
            return null;
        }

        try {
            $objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
            $object        = $objectService->find(id: $uuid, register: $register, schema: $schema);
            if ($object === null) {
                return null;
            }

            return $object->getObject();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: failed to resolve parent project for phase ledger sync',
                ['exception' => $e->getMessage(), 'uuid' => $uuid]
            );
            return null;
        }//end try
    }//end fetchProject()

    /**
     * Persist the mutated project data back to OpenRegister.
     *
     * @param string               $uuid The project UUID.
     * @param array<string, mixed> $data The mutated project data.
     *
     * @return void
     */
    private function persist(string $uuid, array $data): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'project_schema', '');
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
                'Pipelinq: failed to persist project ledger sync status',
                ['exception' => $e->getMessage(), 'uuid' => $uuid]
            );
        }//end try
    }//end persist()
}//end class
