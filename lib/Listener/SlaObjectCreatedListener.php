<?php

/**
 * Pipelinq SlaObjectCreatedListener.
 *
 * Listens on OpenRegister `ObjectCreatedEvent` and initialises the SLA
 * tracking envelope (`slaStatus`) on tracked schemas (request, klacht
 * / complaint, callback).
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
use DateTimeZone;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
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
 * Initialise slaStatus on object creation.
 *
 * Filtered to tracked schemas (request / complaint / callback). Any
 * exception is logged and swallowed — the underlying object save MUST
 * succeed (REQ-007 fail-safe).
 *
 * ADR-078: the object is already written when this runs, so policy
 * resolution and the `slaStatus` write no longer happen inside the create
 * request. They run in {@see DeferredObjectListenerJob} under the acting
 * user. `slaStatus` is therefore populated shortly AFTER the create response,
 * bounded by the cron interval — the eventual consistency ADR-078 accepts for
 * post-event effects. The envelope's timestamps come from the deferred pass's
 * `now`, which is when the SLA clock is armed.
 *
 * @implements IEventListener<Event>
 */
class SlaObjectCreatedListener implements IEventListener, DeferredObjectWork {

	/**
	 * Identifies this listener's entries in the deferral job.
	 *
	 * @var string
	 */
	public const HANDLER_KEY = 'sla-object-created';

	private const TRACKED_TYPES = ['request', 'complaint', 'complaint', 'callback'];

	/**
	 * Constructor.
	 *
	 * @param SlaEngineService $engine SLA engine.
	 * @param SchemaMapService $schemaMapService Schema → entity-type map.
	 * @param ContainerInterface $container DI container (OR ObjectService).
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
	 * Does no SLA work: filters to tracked schemas and queues the init.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		try {
			$entity = $event->getObject();
			$schemaId = (string)$entity->getSchema();
			$type = $this->schemaMapService->resolveEntityType($schemaId);
			if (in_array($type, self::TRACKED_TYPES, true) === false) {
				return;
			}

			$data = $entity->getObject();

			// Already initialised? Don't recompute (REQ-001 immutability).
			if (isset($data['slaStatus']) === true && is_array($data['slaStatus']) === true
				&& ($data['slaStatus']['policyId'] ?? '') !== ''
			) {
				return;
			}

			$uuid = (string)$entity->getUuid();
			if ($uuid === '' || $schemaId === '') {
				return;
			}

			// Our own slaStatus write re-enters the SLA listeners; deferring
			// again from inside the deferred pass would be a cron loop.
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
				'SlaObjectCreatedListener: SLA init could not be queued (non-blocking)',
				['error' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * Resolve the policy and write the initial slaStatus envelope.
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
		$matchType = (string)($entry['type'] ?? '');
		if ($uuid === '' || $schemaId === '' || $matchType === '') {
			return;
		}

		$data = $this->fetch(uuid: $uuid, schemaId: $schemaId);
		if ($data === null) {
			// Object gone since creation. Stale entry (ADR-078 Rule 7).
			return;
		}

		// Re-checked: another path may have armed the envelope in the interval.
		if (isset($data['slaStatus']) === true && is_array($data['slaStatus']) === true
			&& ($data['slaStatus']['policyId'] ?? '') !== ''
		) {
			return;
		}

		$metadata = $this->extractMetadata(data: $data);
		$policy = $this->engine->resolvePolicyForObject($matchType, $uuid, $metadata);
		if ($policy === null) {
			$this->logger->debug(
				'SlaObjectCreatedListener: no matching policy',
				['type' => $matchType, 'tier' => ($metadata['tier'] ?? '')]
			);
			return;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$data['slaStatus'] = $this->engine->initialiseStatus($policy, $now);

		$this->persist(uuid: $uuid, schemaId: $schemaId, data: $data);
	}//end runDeferredWork()

	/**
	 * Extract policy-resolution metadata from the object payload.
	 *
	 * @param array<string, mixed> $data Object data.
	 *
	 * @return array<string, mixed> Metadata: tier, organisationId, contractId.
	 */
	private function extractMetadata(array $data): array {
		return [
			'tier' => (string)($data['slaTier'] ?? $data['customerTier'] ?? ''),
			'organisationId' => (string)($data['organisationId'] ?? $data['client'] ?? ''),
			'contractId' => (string)($data['contractId'] ?? ''),
		];
	}//end extractMetadata()

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
				'SlaObjectCreatedListener: re-read failed (non-blocking)',
				['error' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}//end try
	}//end fetch()

	/**
	 * Persist the mutated object data back to OpenRegister.
	 *
	 * Uses OR's `saveObject()` to atomically write the slaStatus
	 * envelope. Failure is logged but never thrown (REQ-007).
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
				'SlaObjectCreatedListener: persist failed (non-blocking)',
				['error' => $e->getMessage(), 'uuid' => $uuid]
			);
		}//end try
	}//end persist()
}//end class
