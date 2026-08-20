<?php

/**
 * Pipelinq ProjectCreationListener.
 *
 * Dispatches a newly created project to the Shillinq ledger.
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
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
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
 * ADR-078: this used to be the worst shape in the app — two `saveObject()`
 * calls with an OUTBOUND HTTP DISPATCH (with its own retries) between them, all
 * inside the user's create request. Shillinq's latency was the user's latency
 * and Shillinq's outage was the user's slow project creation. The whole
 * sequence now runs in {@see DeferredObjectListenerJob} under the acting user;
 * `handle()` keeps only the schema filter and the two cheap short-circuits.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-001
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Measured 14, threshold 13.
 *  An ADR-078 deferred listener necessarily names both halves of the contract —
 *  the event and entity types it reacts to, and the deferral/job types it hands
 *  work to. The count is the contract's width, not an accumulation of
 *  responsibilities.
 */
class ProjectCreationListener implements IEventListener, DeferredObjectWork {

	/**
	 * Identifies this listener's entries in the deferral job.
	 *
	 * @var string
	 */
	public const HANDLER_KEY = 'project-created';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapService $schemaMapService The schema map service.
	 * @param ShillinqLedgerService $ledgerService The Shillinq ledger service.
	 * @param LedgerSyncNotifier $notifier The admin failure notifier.
	 * @param ContainerInterface $container The DI container (OpenRegister ObjectService lookup).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ListenerDeferralService $deferral The actor-forwarding deferral service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private SchemaMapService $schemaMapService,
		private ShillinqLedgerService $ledgerService,
		private LedgerSyncNotifier $notifier,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ListenerDeferralService $deferral,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object-created event.
	 *
	 * Does no ledger work: filters, then queues.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-001-01
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DeferredWorkGuard is a process-scoped
	 *  re-entrancy guard: its `$inFlight` map MUST be shared across every listener
	 *  instance in the request, which is exactly what an injected per-instance
	 *  service cannot give. Static is the mechanism, not an accident.
	 */
	public function handle(Event $event): void {
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
		$uuid = (string)$entity->getUuid();
		if ($uuid === '') {
			return;
		}

		// Idempotency: never re-dispatch an already-synced project (REQ-PLG-003-04).
		if (($data['ledgerSyncStatus'] ?? null) === 'synced') {
			return;
		}

		// Our own status writes re-enter the project listeners; deferring again
		// from inside the deferred pass would be a cron loop.
		if (DeferredWorkGuard::isRunning(key: DeferredWorkGuard::key(handler: self::HANDLER_KEY, uuid: $uuid)) === true) {
			return;
		}

		$this->deferral->defer(
			jobClass: DeferredObjectListenerJob::class,
			entry: [
				'handler' => self::HANDLER_KEY,
				'uuid' => $uuid,
			],
			dedupeKey: self::HANDLER_KEY . '|' . $uuid
		);
	}//end handle()

	/**
	 * Dispatch the project to the Shillinq ledger and record the outcome.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-001-01
	 */
	public function runDeferredWork(array $entry): void {
		$uuid = (string)($entry['uuid'] ?? '');
		$data = $this->fetch(uuid: $uuid);
		if ($data === null) {
			// Project gone since creation. Stale entry (ADR-078 Rule 7).
			return;
		}

		// Re-checked here as well as at dispatch time: the webhook may have been
		// unconfigured, or the project already synced, in the interval.
		if ($this->ledgerService->shouldDispatch() === false
			|| ($data['ledgerSyncStatus'] ?? null) === 'synced'
		) {
			return;
		}

		// Mark pending and persist before the dispatch begins (REQ-PLG-001-02).
		$data['ledgerSyncStatus'] = 'pending';
		$this->persist(uuid: $uuid, data: $data);

		$success = $this->ledgerService->dispatchProjectEvent(project: $data, eventType: 'created');

		if ($success === true) {
			$data['ledgerSyncStatus'] = 'synced';
			$data['ledgerSyncedAt'] = $this->ledgerService->now();
			$this->persist(uuid: $uuid, data: $data);
			return;
		}

		// Dispatch failed after retries (REQ-PLG-003-01): mark failed and notify admins.
		$data['ledgerSyncStatus'] = 'failed';
		$this->persist(uuid: $uuid, data: $data);
		$this->notifier->notifyFailure(
			projectName: (string)($data['name'] ?? ''),
			eventType: 'created',
			uuid: $uuid
		);
	}//end runDeferredWork()

	/**
	 * Whether the entity belongs to the project schema.
	 *
	 * @param object $entity The object entity.
	 *
	 * @return bool True when the entity is a project.
	 */
	private function isProject(object $entity): bool {
		$entityType = $this->schemaMapService->resolveEntityType(schemaId: $entity->getSchema());
		return $entityType === 'project';
	}//end isProject()

	/**
	 * Read the project's current data.
	 *
	 * @param string $uuid The project UUID.
	 *
	 * @return array<string, mixed>|null The project data, or null when it is gone.
	 */
	private function fetch(string $uuid): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'project_schema', '');
		if ($register === '' || $schema === '' || $uuid === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(id: $uuid, register: $register, schema: $schema);
			if ($object === null) {
				return null;
			}

			return $object->getObject();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to re-read project for deferred ledger dispatch',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}//end try
	}//end fetch()

	/**
	 * Persist the mutated project data back to OpenRegister.
	 *
	 * @param string $uuid The project UUID.
	 * @param array<string, mixed> $data The mutated project data.
	 *
	 * @return void
	 */
	private function persist(string $uuid, array $data): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'project_schema', '');
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
