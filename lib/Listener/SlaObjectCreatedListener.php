<?php

/**
 * Pipelinq SlaObjectCreatedListener.
 *
 * Listens on OpenRegister `ObjectCreatedEvent` and initialises the SLA
 * tracking envelope (`slaStatus`) on tracked schemas (request, klacht
 * / complaint, callback) before the API response returns.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
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
 * Initialise slaStatus on object creation.
 *
 * Filtered to tracked schemas (request / complaint / callback). Any
 * exception is logged and swallowed — the underlying object save MUST
 * succeed (REQ-007 fail-safe).
 *
 * @implements IEventListener<Event>
 */
class SlaObjectCreatedListener implements IEventListener {
	private const TRACKED_TYPES = ['request', 'complaint', 'klacht', 'callback'];

	/**
	 * Constructor.
	 *
	 * @param SlaEngineService $engine SLA engine.
	 * @param SchemaMapService $schemaMapService Schema → entity-type map.
	 * @param ContainerInterface $container DI container (OR ObjectService).
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
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		try {
			$entity = $event->getObject();
			$type = $this->schemaMapService->resolveEntityType($entity->getSchema());
			if (in_array($type, self::TRACKED_TYPES, true) === false) {
				return;
			}

			// Normalise 'complaint' → 'klacht' for policy matching, per spec wording.
			$matchType = $type;
			if ($type === 'complaint') {
				$matchType = 'klacht';
			}

			$data = $entity->getObject();

			// Already initialised? Don't recompute (REQ-001 immutability).
			if (isset($data['slaStatus']) === true && is_array($data['slaStatus']) === true
				&& ($data['slaStatus']['policyId'] ?? '') !== ''
			) {
				return;
			}

			$metadata = $this->extractMetadata(data: $data);
			$policy = $this->engine->resolvePolicyForObject($matchType, (string)$entity->getUuid(), $metadata);
			if ($policy === null) {
				$this->logger->debug(
					'SlaObjectCreatedListener: no matching policy',
					['type' => $matchType, 'tier' => $metadata['tier'] ?? '']
				);
				return;
			}

			$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
			$data['slaStatus'] = $this->engine->initialiseStatus($policy, $now);

			$this->persist(entity: $entity, data: $data);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SlaObjectCreatedListener: SLA init failed (non-blocking)',
				['error' => $e->getMessage()]
			);
		}//end try
	}//end handle()

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
	 * Persist the mutated object data back to OpenRegister.
	 *
	 * Uses OR's `saveObject()` to atomically write the slaStatus
	 * envelope. Failure is logged but never thrown (REQ-007).
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
				'SlaObjectCreatedListener: persist failed (non-blocking)',
				['error' => $e->getMessage(), 'uuid' => $uuid]
			);
		}
	}//end persist()
}//end class
