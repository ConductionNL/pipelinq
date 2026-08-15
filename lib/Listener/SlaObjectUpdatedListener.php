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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\AppInfo\Application;
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
 * @implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bridges the SLA engine,
 *  schema map, OR ObjectService, app-config and logger — an irreducible
 *  set of collaborators for the update-time SLA lifecycle.
 */
class SlaObjectUpdatedListener implements IEventListener {
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
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private SlaEngineService $engine,
		private SchemaMapService $schemaMapService,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a dispatched event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$entity = $event->getNewObject();
			$type = $this->schemaMapService->resolveEntityType($entity->getSchema());
			if (in_array($type, self::TRACKED_TYPES, true) === false) {
				return;
			}

			$data = $entity->getObject();
			$slaStatus = $data['slaStatus'] ?? null;
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
			$slaStatus['targets'] = $this->engine->evaluateTargets($slaStatus['targets'] ?? [], $policy, $now);

			$slaStatus = $this->applyEscalations(
				slaStatus: $slaStatus,
				type: $type,
				entity: $entity,
				policy: $policy,
			);

			$slaStatus['lastEvaluatedAt'] = $now->format(DateTimeInterface::ATOM);
			$data['slaStatus'] = $slaStatus;
			$this->persist(entity: $entity, data: $data);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaObjectUpdatedListener: SLA update failed (non-blocking)',
				['error' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Fire due escalations for an unpaused timer and merge breach-event IDs.
	 *
	 * No-op when the timer is currently paused.
	 *
	 * @param array<string, mixed> $slaStatus Current slaStatus.
	 * @param string $type Resolved entity type.
	 * @param object $entity Object entity from the event.
	 * @param array<string, mixed> $policy Policy.
	 *
	 * @return array<string, mixed> Updated slaStatus.
	 */
	private function applyEscalations(
		array $slaStatus,
		string $type,
		object $entity,
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
			(string)$matchType,
			(string)$entity->getUuid(),
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
	 * Persist the mutated object data back to OpenRegister.
	 *
	 * @param object $entity Object entity from the event.
	 * @param array<string, mixed> $data Mutated data including slaStatus.
	 *
	 * @return void
	 */
	private function persist(object $entity, array $data): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = (string)$entity->getSchema();
		$uuid = (string)$entity->getUuid();
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
		}
	}//end persist()
}//end class
