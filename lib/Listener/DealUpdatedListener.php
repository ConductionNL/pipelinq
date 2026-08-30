<?php

/**
 * Pipelinq DealUpdatedListener.
 *
 * Enforces forecast-category transition rules on deal updates.
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
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-002, REQ-FRC-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Deferral\ListenerDeferralService;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Service\ForecastDealService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Listener that enforces forecast-category transition rules on deal updates.
 *
 * OpenRegister dispatches object events after the write, so a rejected
 * transition (changing a closed deal, or an unjustified large commit) is
 * corrected by re-saving the prior forecast_category. A reopen resets the
 * category to pipeline. Filtered to the lead schema.
 *
 * ADR-078: `ObjectUpdatedEvent` is a POST event — the offending value is
 * already stored and the listener could never have vetoed it, so the correction
 * is a compensating write, not a validation. It runs in
 * {@see DeferredObjectListenerJob} under the acting user instead of inside the
 * update request.
 *
 * BECAUSE THE CORRECTION IS NOW LATE, IT IS RE-DECIDED, NOT REPLAYED. The
 * deferred pass re-reads the deal and re-runs `validateTransition()` against the
 * CURRENT value: if someone has already put a valid category back, nothing
 * happens. Blindly replaying the captured revert would overwrite that fix with
 * a stale value — the failure mode ADR-078 Rule 7 exists to prevent.
 *
 * The old category is still carried in the entry, because it is the only thing
 * a later read cannot recover.
 *
 * @implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Measured 13, threshold 13.
 *  An ADR-078 deferred listener necessarily names both halves of the contract —
 *  the event and entity types it reacts to, and the deferral/job types it hands
 *  work to. The count is the contract's width, not an accumulation of
 *  responsibilities.
 */
class DealUpdatedListener implements IEventListener, DeferredObjectWork {

	/**
	 * Identifies this listener's entries in the deferral job.
	 *
	 * @var string
	 */
	public const HANDLER_KEY = 'deal-updated';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapService $schemaMapService The schema map service.
	 * @param ForecastDealService $dealService The forecast deal lifecycle service.
	 * @param ContainerInterface $container The DI container (OpenRegister ObjectService lookup).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param ListenerDeferralService $deferral The actor-forwarding deferral service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private SchemaMapService $schemaMapService,
		private ForecastDealService $dealService,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private ListenerDeferralService $deferral,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an object-updated event.
	 *
	 * Does no work: filters to the lead schema, captures the previous state
	 * the correction needs, and queues it.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-002-01, REQ-FRC-003-01
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
		if ($this->isLead(entity: $newEntity) === false || $oldEntity === null) {
			return;
		}

		$uuid = (string)$newEntity->getUuid();
		if ($uuid === '') {
			return;
		}

		// Our own deferred correction re-enters this listener. Deferring again
		// would enqueue another job whose write re-enters again — a cron loop.
		if (DeferredWorkGuard::isRunning(key: DeferredWorkGuard::key(handler: self::HANDLER_KEY, uuid: $uuid)) === true) {
			return;
		}

		$oldData = $oldEntity->getObject();
		$newData = $newEntity->getObject();

		// Nothing to correct: skip the job row entirely rather than enqueue a
		// no-op. Both decisions are re-taken against current state in the job.
		if ($this->dealService->validateTransition(oldData: $oldData, newData: $newData) === null
			&& $this->dealService->applyReopenReset(oldData: $oldData, newData: $newData) === null
		) {
			return;
		}

		$this->deferral->defer(
			jobClass: DeferredObjectListenerJob::class,
			entry: [
				'handler' => self::HANDLER_KEY,
				'uuid' => $uuid,
				'oldData' => $oldData,
			],
			dedupeKey: self::HANDLER_KEY . '|' . $uuid
		);
	}//end handle()

	/**
	 * Re-decide and apply the forecast-category correction.
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-002-01, REQ-FRC-003-01
	 */
	public function runDeferredWork(array $entry): void {
		$uuid = (string)($entry['uuid'] ?? '');
		$oldData = ($entry['oldData'] ?? []);
		if (is_array($oldData) === false) {
			return;
		}

		$current = $this->fetch(uuid: $uuid);
		if ($current === null) {
			// Deal gone since the update. Stale entry, no-op (ADR-078 Rule 7).
			return;
		}

		// Re-decided against CURRENT data, not replayed from the captured payload.
		$error = $this->dealService->validateTransition(oldData: $oldData, newData: $current);
		if ($error !== null) {
			$this->logger->warning(
				'Pipelinq: rejected forecast_category transition; reverting',
				['uuid' => $uuid, 'reason' => $error]
			);
			$reverted = $current;
			$reverted['forecast_category'] = ($oldData['forecast_category'] ?? ForecastDealService::DEFAULT_CATEGORY);
			$this->persist(uuid: $uuid, data: $reverted);
			return;
		}

		$reset = $this->dealService->applyReopenReset(oldData: $oldData, newData: $current);
		if ($reset !== null) {
			$this->persist(uuid: $uuid, data: $reset);
		}
	}//end runDeferredWork()

	/**
	 * Whether the entity belongs to the lead (deal) schema.
	 *
	 * @param object $entity The object entity.
	 *
	 * @return bool True when the entity is a lead.
	 */
	private function isLead(object $entity): bool {
		$entityType = $this->schemaMapService->resolveEntityType(schemaId: $entity->getSchema());
		return $entityType === 'lead';
	}//end isLead()

	/**
	 * Read the deal's current data.
	 *
	 * @param string $uuid The deal UUID.
	 *
	 * @return array<string, mixed>|null The deal data, or null when it is gone.
	 */
	private function fetch(string $uuid): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
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
				'Pipelinq: failed to re-read deal for deferred forecast_category correction',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}//end try
	}//end fetch()

	/**
	 * Persist mutated deal data back to OpenRegister.
	 *
	 * @param string $uuid The deal UUID.
	 * @param array<string, mixed> $data The deal data.
	 *
	 * @return void
	 */
	private function persist(string $uuid, array $data): void {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
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
				'Pipelinq: failed to persist forecast_category correction on deal update',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
		}//end try
	}//end persist()
}//end class
