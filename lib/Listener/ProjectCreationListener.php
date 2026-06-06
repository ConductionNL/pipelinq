<?php

/**
 * Pipelinq ProjectCreationListener.
 *
 * Dispatches a newly created project to the Shillinq project ledger and records
 * the outcome on the project's ledgerSyncStatus / ledgerSyncedAt fields.
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
 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
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
 * Listener that dispatches a new project to the Shillinq ledger.
 *
 * Filtered to the project schema. Idempotent: a project already marked
 * ledgerSyncStatus = synced is skipped so a re-fired creation event cannot
 * create a duplicate ledger entry.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-001
 */
class ProjectCreationListener implements IEventListener
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
     * Handle an object-created event.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-001-01
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatedEvent) === false) {
            return;
        }

        $entity = $event->getObject();
        if ($this->isProject(entity: $entity) === false) {
            return;
        }

        if ($this->ledgerService->shouldDispatch() === false) {
            return;
        }

        $data = $entity->getObject();
        $uuid = (string) $entity->getUuid();

        // Idempotency: never re-dispatch an already-synced project (REQ-PLG-003-04).
        if (($data['ledgerSyncStatus'] ?? null) === 'synced') {
            return;
        }

        // Mark pending and persist before the dispatch begins (REQ-PLG-001-02).
        $data['ledgerSyncStatus'] = 'pending';
        $this->persist(uuid: $uuid, data: $data);

        $success = $this->ledgerService->dispatchProjectEvent(project: $data, eventType: 'created');

        if ($success === true) {
            $data['ledgerSyncStatus'] = 'synced';
            $data['ledgerSyncedAt']   = $this->ledgerService->now();
            $this->persist(uuid: $uuid, data: $data);
            return;
        }

        // Dispatch failed after retries (REQ-PLG-003-01): mark failed and notify admins.
        $data['ledgerSyncStatus'] = 'failed';
        $this->persist(uuid: $uuid, data: $data);
        $this->notifier->notifyFailure(
            projectName: (string) ($data['name'] ?? ''),
            eventType: 'created',
            uuid: $uuid
        );
    }//end handle()

    /**
     * Whether the entity belongs to the project schema.
     *
     * @param object $entity The object entity.
     *
     * @return bool True when the entity is a project.
     */
    private function isProject(object $entity): bool
    {
        $entityType = $this->schemaMapService->resolveEntityType(schemaId: $entity->getSchema());
        return $entityType === 'project';
    }//end isProject()

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
