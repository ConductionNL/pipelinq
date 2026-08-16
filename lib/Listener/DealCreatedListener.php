<?php

/**
 * Pipelinq DealCreatedListener.
 *
 * Defaults the forecast_category of a newly created deal (lead) to "pipeline".
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
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-001-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
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
 * Listener that assigns the default forecast category to new deals.
 *
 * Filtered to the lead schema. Idempotent: a deal that already carries a
 * forecast_category is left untouched.
 *
 * NOTE (pipelinq-lifecycle-batch-b / ADR-031): the create-default is now declared
 * on the lead schema as `forecast_category.default = "pipeline"` with
 * `defaultBehavior: "falsy"`, which OpenRegister applies DURING the create save
 * (for a missing / null / empty-string value) — so by the time this listener runs
 * the category is already defaulted and {@see ForecastDealService::applyDefaultCategory()}
 * returns null (no re-save). The listener is retained as a backstop: it costs one
 * idempotent check and guarantees the default even on any code path that bypasses
 * the schema-default application. ForecastDealService reads the default value from
 * the schema annotation, so the source of truth is the schema, not this listener.
 *
 * ADR-078: `ObjectCreatedEvent` is a POST event. The deal is already written and
 * nothing this listener does can change that, so the backstop re-save no longer
 * runs inside the create request — it is deferred to
 * {@see DeferredObjectListenerJob} under the acting user. The deferred pass
 * re-reads the deal, so a category applied by any other path in the meantime is
 * simply left alone.
 *
 * @implements IEventListener<Event>
 */
class DealCreatedListener implements IEventListener, DeferredObjectWork {

	/**
	 * Identifies this listener's entries in the deferral job.
	 *
	 * @var string
	 */
	public const HANDLER_KEY = 'deal-created';

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
	 * Handle an object-created event.
	 *
	 * Does no work: filters to the lead schema and queues the backstop.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-001-01
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$entity = $event->getObject();
		if ($this->isLead(entity: $entity) === false) {
			return;
		}

		$uuid = (string)$entity->getUuid();
		if ($uuid === '') {
			return;
		}

		// Our own deferred re-save re-enters this listener. Deferring again
		// would enqueue another job whose write re-enters again — a cron loop.
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
	 * Apply the forecast-category backstop against the deal's CURRENT state.
	 *
	 * Re-reads the deal rather than trusting the dispatch-time payload:
	 * delivery is at-least-once and the deal may have been edited or removed
	 * since (ADR-078 Rule 7).
	 *
	 * @param array<string, mixed> $entry The entry captured at dispatch time.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-001-01
	 */
	public function runDeferredWork(array $entry): void {
		$uuid = (string)($entry['uuid'] ?? '');
		$data = $this->fetch(uuid: $uuid);
		if ($data === null) {
			return;
		}

		$mutated = $this->dealService->applyDefaultCategory($data);
		if ($mutated === null) {
			return;
		}

		$this->persist(uuid: $uuid, data: $mutated);
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
				'Pipelinq: failed to re-read deal for deferred forecast_category default',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}//end try
	}//end fetch()

	/**
	 * Persist the mutated deal data back to OpenRegister.
	 *
	 * @param string $uuid The deal UUID.
	 * @param array<string, mixed> $data The mutated deal data.
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
				'Pipelinq: failed to default forecast_category on new deal',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
		}//end try
	}//end persist()
}//end class
