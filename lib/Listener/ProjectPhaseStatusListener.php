<?php

/**
 * Pipelinq ProjectPhaseStatusListener.
 *
 * Dispatches project / phase status changes to the Shillinq ledger.
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
 * Listener that dispatches project status changes to the Shillinq ledger.
 *
 * Filtered to the project and projectPhase schemas. A status change on a
 * project dispatches directly; a status change on a phase is resolved to its
 * parent project, whose ledger sync status is then updated. Updates that do
 * not change status are ignored.
 *
 * ADR-078: the status change is already stored when this runs, so the
 * dispatch — two `saveObject()` calls around an outbound HTTP call with
 * retries, plus a parent-project lookup for the phase case — no longer happens
 * inside the update request. It runs in {@see DeferredObjectListenerJob} under
 * the acting user.
 *
 * THE OLD AND NEW STATUS TRAVEL IN THE ENTRY. They are the ledger event's
 * payload and the only part a later read cannot reconstruct; everything else
 * (the project body, whether the webhook is still configured) is re-read in the
 * job so the dispatch reflects current state.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-002
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Measured 13, threshold 13.
 *  An ADR-078 deferred listener necessarily names both halves of the contract —
 *  the event and entity types it reacts to, and the deferral/job types it hands
 *  work to. The count is the contract's width, not an accumulation of
 *  responsibilities.
 */
class ProjectPhaseStatusListener implements IEventListener, DeferredObjectWork {

	/**
	 * Identifies this listener's entries in the deferral job.
	 *
	 * @var string
	 */
	public const HANDLER_KEY = 'project-phase-status';

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
	 * Handle an object-updated event.
	 *
	 * Does no ledger work: detects the status change and queues the dispatch.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-002-01
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DeferredWorkGuard is a process-scoped
	 *  re-entrancy guard: its `$inFlight` map MUST be shared across every listener
	 *  instance in the request, which is exactly what an injected per-instance
	 *  service cannot give. Static is the mechanism, not an accident.
	 */
	public function handle(Event $event): void {
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

		$newData = $newEntity->getObject();
		$oldData = $oldEntity->getObject();
		$oldStatus = (string)($oldData['status'] ?? '');
		$newStatus = (string)($newData['status'] ?? '');

		// Only act on an actual status change (REQ-PLG-002-01).
		if ($oldStatus === $newStatus) {
			return;
		}

		// A phase change is attributed to its parent project; the lookup itself
		// is a read and belongs in the job, so only the reference travels.
		$subjectUuid = $this->resolveSubjectUuid(
			entityType: $entityType,
			ownUuid: (string)$newEntity->getUuid(),
			newData: $newData
		);
		if ($subjectUuid === '') {
			return;
		}

		// Our own status writes re-enter this listener; deferring again from
		// inside the deferred pass would be a cron loop.
		if (DeferredWorkGuard::isRunning(key: DeferredWorkGuard::key(handler: self::HANDLER_KEY, uuid: $subjectUuid)) === true) {
			return;
		}

		$this->deferral->defer(
			jobClass: DeferredObjectListenerJob::class,
			entry: [
				'handler' => self::HANDLER_KEY,
				'uuid' => $subjectUuid,
				'oldStatus' => $oldStatus,
				'newStatus' => $newStatus,
			],
			dedupeKey: self::HANDLER_KEY . '|' . $subjectUuid . '|' . $oldStatus . '|' . $newStatus
		);
	}//end handle()

	/**
	 * Resolve the uuid the status change is attributed to.
	 *
	 * A `projectPhase` change is attributed to its parent project, so the phase's
	 * own uuid is replaced by the `project` reference. Returns '' when there is
	 * nothing dispatchable — a phase with no parent reference, or a missing uuid.
	 *
	 * Extracted from handle() to bring that method back under the phpmd
	 * thresholds it had crossed (Cyclomatic 11 > 10, NPath 576 > 200); the guard
	 * chain reads the same, it just no longer all lives in one method.
	 *
	 * @param string $entityType The resolved entity type.
	 * @param string $ownUuid The changed object's own uuid.
	 * @param array<string, mixed> $newData The changed object's payload.
	 *
	 * @return string The subject uuid, or '' when not dispatchable.
	 */
	private function resolveSubjectUuid(string $entityType, string $ownUuid, array $newData): string {
		if ($entityType === 'projectPhase') {
			return (string)($newData['project'] ?? '');
		}

		return $ownUuid;
	}//end resolveSubjectUuid()

	/**
	 * Dispatch the status change to the ledger and record the outcome.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipelinq-project-to-shillinq-ledger/specs.md#REQ-PLG-002-01
	 */
	public function runDeferredWork(array $entry): void {
		$projectUuid = (string)($entry['uuid'] ?? '');
		if ($projectUuid === '' || $this->ledgerService->shouldDispatch() === false) {
			return;
		}

		$project = $this->fetchProject(uuid: $projectUuid);
		if ($project === null) {
			// Project gone since the status change. Stale entry (ADR-078 Rule 7).
			return;
		}

		$this->dispatchAndRecord(
			project: $project,
			projectUuid: $projectUuid,
			oldStatus: (string)($entry['oldStatus'] ?? ''),
			newStatus: (string)($entry['newStatus'] ?? '')
		);
	}//end runDeferredWork()

	/**
	 * Dispatch a status-change ledger event and record the outcome on the project.
	 *
	 * Resets ledgerSyncStatus to pending before the dispatch, then resolves it to
	 * synced or failed (REQ-PLG-002-03).
	 *
	 * @param array<string, mixed> $project The project object data.
	 * @param string $projectUuid The project UUID.
	 * @param string $oldStatus The previous status value.
	 * @param string $newStatus The new status value.
	 *
	 * @return void
	 */
	private function dispatchAndRecord(array $project, string $projectUuid, string $oldStatus, string $newStatus): void {
		$project['ledgerSyncStatus'] = 'pending';
		$this->persist(uuid: $projectUuid, data: $project);

		$success = $this->ledgerService->dispatchPhaseChangeEvent(
			project: $project,
			oldStatus: $oldStatus,
			newStatus: $newStatus
		);

		if ($success === true) {
			$project['ledgerSyncStatus'] = 'synced';
			$project['ledgerSyncedAt'] = $this->ledgerService->now();
			$this->persist(uuid: $projectUuid, data: $project);
			return;
		}

		$project['ledgerSyncStatus'] = 'failed';
		$this->persist(uuid: $projectUuid, data: $project);
		$this->notifier->notifyFailure(
			projectName: (string)($project['name'] ?? ''),
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
	private function fetchProject(string $uuid): ?array {
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
				'Pipelinq: failed to resolve project for ledger status dispatch',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}//end try
	}//end fetchProject()

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
