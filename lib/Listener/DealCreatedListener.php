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
use OCA\Pipelinq\AppInfo\Application;
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
 * @implements IEventListener<Event>
 */
class DealCreatedListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param SchemaMapService    $schemaMapService The schema map service.
     * @param ForecastDealService $dealService      The forecast deal lifecycle service.
     * @param ContainerInterface  $container        The DI container (OpenRegister ObjectService lookup).
     * @param IAppConfig          $appConfig        The app configuration.
     * @param LoggerInterface     $logger           The logger.
     */
    public function __construct(
        private SchemaMapService $schemaMapService,
        private ForecastDealService $dealService,
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
     * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-001-01
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectCreatedEvent) === false) {
            return;
        }

        $entity = $event->getObject();
        if ($this->isLead(entity: $entity) === false) {
            return;
        }

        $data    = $entity->getObject();
        $mutated = $this->dealService->applyDefaultCategory($data);
        if ($mutated === null) {
            return;
        }

        $this->persist(uuid: (string) $entity->getUuid(), data: $mutated);
    }//end handle()

    /**
     * Whether the entity belongs to the lead (deal) schema.
     *
     * @param object $entity The object entity.
     *
     * @return bool True when the entity is a lead.
     */
    private function isLead(object $entity): bool
    {
        $entityType = $this->schemaMapService->resolveEntityType(schemaId: $entity->getSchema());
        return $entityType === 'lead';
    }//end isLead()

    /**
     * Persist the mutated deal data back to OpenRegister.
     *
     * @param string               $uuid The deal UUID.
     * @param array<string, mixed> $data The mutated deal data.
     *
     * @return void
     */
    private function persist(string $uuid, array $data): void
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
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
        }
    }//end persist()
}//end class
