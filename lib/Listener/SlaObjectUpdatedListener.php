<?php

/**
 * Pipelinq SlaObjectUpdatedListener.
 *
 * Listens on OpenRegister `ObjectUpdatedEvent` and applies pause /
 * resume / re-evaluation / escalation to the embedded `slaStatus`.
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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SlaEngineService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * React to tracked-object updates: handle pause/resume, status
 * re-evaluation and escalation firing.
 *
 * ADR-078: the update is already stored when this runs. Policy loading,
 * target re-evaluation, escalation firing and the `slaStatus` write now
 * happen in {@see DeferredObjectListenerJob} under the acting user rather than
 * inside the update request.
 *
 * ⚠️ THIS LISTENER RE-ENTERS ITSELF, AND ONLY THE GUARD STOPS IT.
 * It reacts to `ObjectUpdatedEvent` and unconditionally writes
 * `slaStatus.lastEvaluatedAt = now` — a value that differs on every pass. Its
 * own `persist()` therefore raises another `ObjectUpdatedEvent` carrying an
 * object that satisfies every one of its entry conditions, with no idempotency
 * check anywhere that can stop it. Inline, that recursed on one request's
 * stack. Deferred, each turn would enqueue a fresh job, and since `cron.php`
 * runs one job per web call, that job would starve every other job on the
 * instance indefinitely.
 *
 * {@see DeferredWorkGuard} closes it: the deferred pass marks
 * `(sla-object-updated, uuid)` in flight, the write it makes re-enters
 * `handle()`, `handle()` sees the mark and returns without deferring.
 *
 * @implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bridges the SLA engine,
 *  schema map, OR ObjectService, deferral service, app-config and logger — an
 *  irreducible set of collaborators for the update-time SLA lifecycle.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-attainment-reporting
 */
class SlaObjectUpdatedListener implements IEventListener, DeferredObjectWork {

	/**
	 * Identifies this listener's entries in the deferral job.
	 *
	 * @var string
	 */
	public const HANDLER_KEY = 'sla-object-updated';

	private const TRACKED_TYPES = ['request', 'complaint', 'complaint', 'callback'];

	/**
	 * Statuses commonly used to indicate "resolved" — used to short-circuit
	 * the resolution-target as `met`. Customisable via app-config
	 * `sla_resolved_statuses` (comma-separated).
	 *
	 * @var array<int, string>
	 */
	private const DEFAULT_RESOLVED_STATUSES = ['resolved', 'completed', 'closed', 'handled'];

	/**
	 * Constructor.
	 *
	 * @param SlaEngineService $engine SLA engine.
	 * @param SchemaMapService $schemaMapService Schema → entity-type map.
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param ListenerDeferralService $deferral The actor-forwarding deferral service.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private SlaEngineService $engine,
		private SchemaMapService $schemaMapService,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ListenerDeferralService $deferral,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a dispatched event.
	 *
	 * Does no SLA work: filters and queues the re-evaluation.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
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

		try {
			$entity = $event->getNewObject();
			$schemaId = (string)$entity->getSchema();
			$type = $this->schemaMapService->resolveEntityType($schemaId);
			if (in_array($type, self::TRACKED_TYPES, true) === false) {
				return;
			}

			$data = $entity->getObject();
			$slaStatus = ($data['slaStatus'] ?? null);
			if (is_array($slaStatus) === false || ($slaStatus['policyId'] ?? '') === '') {
				return;
			}

			$uuid = (string)$entity->getUuid();
			if ($uuid === '' || $schemaId === '') {
				return;
			}

			// See the class docblock — without this the deferred write re-enters
			// here and enqueues a job that re-enters again, for ever.
			if (DeferredWorkGuard::isRunning(key: DeferredWorkGuard::key(handler: self::HANDLER_KEY, uuid: $uuid)) === true) {
				return;
			}

			$this->deferral->defer(
				jobClass: DeferredObjectListenerJob::class,
				entry: [
					'handler' => self::HANDLER_KEY,
					'uuid' => $uuid,
					'schema' => $schemaId,
					'type' => (string)$type,
				],
				dedupeKey: self::HANDLER_KEY . '|' . $uuid
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaObjectUpdatedListener: SLA update could not be queued (non-blocking)',
				['error' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Re-evaluate the SLA envelope against the object's current state.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function runDeferredWork(array $entry): void {
		$uuid = (string)($entry['uuid'] ?? '');
		$schemaId = (string)($entry['schema'] ?? '');
		$type = (string)($entry['type'] ?? '');
		if ($uuid === '' || $schemaId === '' || $type === '') {
			return;
		}

		$data = $this->fetch(uuid: $uuid, schemaId: $schemaId);
		if ($data === null) {
			// Object gone since the update. Stale entry (ADR-078 Rule 7).
			return;
		}

		$slaStatus = ($data['slaStatus'] ?? null);
		if (is_array($slaStatus) === false || ($slaStatus['policyId'] ?? '') === '') {
			return;
		}

		$policy = $this->loadPolicy(policyId: (string)$slaStatus['policyId']);
		if ($policy === null) {
			$this->logger->debug(
				'SlaObjectUpdatedListener: policy not found',
				['policyId' => $slaStatus['policyId']]
			);
			return;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$status = (string)($data['status'] ?? '');

		$slaStatus = $this->applyPauseResume(slaStatus: $slaStatus, policy: $policy, status: $status, now: $now);
		$slaStatus = $this->applyResolution(slaStatus: $slaStatus, status: $status, now: $now);

		// Re-evaluate target statuses and fire escalations.
		$slaStatus['targets'] = $this->engine->evaluateTargets(($slaStatus['targets'] ?? []), $policy, $now);

		$slaStatus = $this->applyEscalations(
			slaStatus: $slaStatus,
			type: $type,
			uuid: $uuid,
			policy: $policy,
		);

		$slaStatus['lastEvaluatedAt'] = $now->format(DateTimeInterface::ATOM);
		$data['slaStatus'] = $slaStatus;
		$this->persist(uuid: $uuid, schemaId: $schemaId, data: $data);
	}//end runDeferredWork()

	/**
	 * Fire due escalations for an unpaused timer and merge breach-event IDs.
	 *
	 * No-op when the timer is currently paused.
	 *
	 * @param array<string, mixed> $slaStatus Current slaStatus.
	 * @param string $type Resolved entity type.
	 * @param string $uuid UUID of the tracked object.
	 * @param array<string, mixed> $policy Policy.
	 *
	 * @return array<string, mixed> Updated slaStatus.
	 */
	private function applyEscalations(
		array $slaStatus,
		string $type,
		string $uuid,
		array $policy,
	): array {
		if (($slaStatus['pausedAt'] ?? null) !== null) {
			return $slaStatus;
		}

		$alreadyFired = (int)($slaStatus['currentEscalationLevel'] ?? 0);
		$matchType = $type;
		if ($type === 'complaint') {
			$matchType = 'complaint';
		}

		$result = $this->engine->executeEscalations(
			$policy,
			$matchType,
			$uuid,
			$slaStatus['targets'],
			$slaStatus['targets'],
			$alreadyFired,
		);
		if ($result['eventIds'] !== []) {
			foreach ($slaStatus['targets'] as $idx => $target) {
				$slaStatus['targets'][$idx]['breachEventIds'] = array_values(
					array_unique(
						array_merge(
							(array)($target['breachEventIds'] ?? []),
							$result['eventIds']
						)
					)
				);
			}
		}

		$slaStatus['currentEscalationLevel'] = $result['level'];

		return $slaStatus;
	}//end applyEscalations()

	/**
	 * Apply pause / resume according to the policy's pause-conditions.
	 *
	 * @param array<string, mixed> $slaStatus Current slaStatus.
	 * @param array<string, mixed> $policy Policy.
	 * @param string $status New object status.
	 * @param DateTimeInterface $now Now.
	 *
	 * @return array<string, mixed> Updated slaStatus.
	 */
	private function applyPauseResume(
		array $slaStatus,
		array $policy,
		string $status,
		DateTimeInterface $now,
	): array {
		$pauseConditions = (array)($policy['pauseConditions'] ?? []);
		$isPauseStatus = in_array($status, $pauseConditions, true);
		$currentlyPaused = (($slaStatus['pausedAt'] ?? null) !== null);

		if ($isPauseStatus === true && $currentlyPaused === false) {
			return $this->engine->pauseTimer($slaStatus, $now);
		}

		if ($isPauseStatus === false && $currentlyPaused === true) {
			return $this->engine->resumeTimer($slaStatus, $policy, $now);
		}

		return $slaStatus;
	}//end applyPauseResume()

	/**
	 * Detect resolution status and mark the relevant target(s) as met.
	 *
	 * @param array<string, mixed> $slaStatus Current slaStatus.
	 * @param string $status Object status.
	 * @param DateTimeInterface $now Now.
	 *
	 * @return array<string, mixed> Updated slaStatus.
	 */
	private function applyResolution(
		array $slaStatus,
		string $status,
		DateTimeInterface $now,
	): array {
		if ($status === '') {
			return $slaStatus;
		}

		$resolvedRaw = $this->appConfig->getValueString(
			Application::APP_ID,
			'sla_resolved_statuses',
			implode(',', self::DEFAULT_RESOLVED_STATUSES),
		);

		$resolved = array_filter(array_map('trim', explode(',', $resolvedRaw)));
		if (in_array($status, $resolved, true) === false) {
			return $slaStatus;
		}

		// Mark resolution target met; acknowledgement/firstResponse are
		// updated by application-level transitions (out of engine scope).
		return $this->engine->markTargetMet($slaStatus, 'resolution', $now);
	}//end applyResolution()

	/**
	 * Load the resolved policy by identity (UUID or slug fall-back).
	 *
	 * @param string $policyId Policy identity.
	 *
	 * @return ?array<string, mixed> Policy data, or null.
	 */
	private function loadPolicy(string $policyId): ?array {
		$policies = $this->engine->loadActivePolicies();
		foreach ($policies as $policy) {
			if ($this->engine->policyIdentity($policy) === $policyId) {
				return $policy;
			}
		}

		return null;
	}//end loadPolicy()

	/**
	 * Read the tracked object's current data.
	 *
	 * @param string $uuid Object UUID.
	 * @param string $schemaId Schema identity the object lives in.
	 *
	 * @return array<string, mixed>|null Object data, or null when it is gone.
	 */
	private function fetch(string $uuid, string $schemaId): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($register === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(id: $uuid, register: $register, schema: $schemaId);
			if ($object === null) {
				return null;
			}

			return $object->getObject();
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaObjectUpdatedListener: re-read failed (non-blocking)',
				['error' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}//end try
	}//end fetch()

	/**
	 * Persist the mutated object data back to OpenRegister.
	 *
	 * @param string $uuid Object UUID.
	 * @param string $schemaId Schema identity the object lives in.
	 * @param array<string, mixed> $data Mutated data including slaStatus.
	 *
	 * @return void
	 */
	private function persist(string $uuid, string $schemaId, array $data): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($register === '' || $schemaId === '' || $uuid === '') {
			return;
		}

		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schemaId,
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaObjectUpdatedListener: persist failed (non-blocking)',
				['error' => $e->getMessage(), 'uuid' => $uuid]
			);
		}//end try
	}//end persist()
}//end class
