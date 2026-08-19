<?php

/**
 * Pipelinq DealUpdatedListener.
 *
 * Enforces forecast-category transition rules on deal (lead) updates: closed
 * deals lock, large commits require justification, reopened deals reset.
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
use OCA\Pipelinq\AppInfo\Application;
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
 * reverted by re-saving the prior forecast_category. A reopen resets the
 * category to pipeline. Filtered to the lead schema.
 *
 * @implements IEventListener<Event>
 */
class DealUpdatedListener implements IEventListener
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
     * Handle an object-updated event.
     *
     * @param Event $event The dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-002-01, REQ-FRC-003-01
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectUpdatedEvent) === false) {
            return;
        }

        $newEntity = $event->getNewObject();
        $oldEntity = $event->getOldObject();
        if ($this->isLead(entity: $newEntity) === false || $oldEntity === null) {
            return;
        }

        $newData = $newEntity->getObject();
        $oldData = $oldEntity->getObject();
        $uuid    = (string) $newEntity->getUuid();

        // Reject invalid transitions by reverting to the prior category.
        $error = $this->dealService->validateTransition(oldData: $oldData, newData: $newData);
        if ($error !== null) {
            $this->logger->warning(
                'Pipelinq: rejected forecast_category transition; reverting',
                ['uuid' => $uuid, 'reason' => $error]
            );
            $reverted = $newData;
            $reverted['forecast_category'] = $oldData['forecast_category'] ?? ForecastDealService::DEFAULT_CATEGORY;
            $this->persist(uuid: $uuid, data: $reverted);
            return;
        }

        // Reopening a closed deal resets the forecast category to pipeline.
        $reset = $this->dealService->applyReopenReset(oldData: $oldData, newData: $newData);
        if ($reset !== null) {
            $this->persist(uuid: $uuid, data: $reset);
        }
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
     * Persist mutated deal data back to OpenRegister.
     *
     * @param string               $uuid The deal UUID.
     * @param array<string, mixed> $data The deal data.
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
                'Pipelinq: failed to persist forecast_category correction on deal update',
                ['exception' => $e->getMessage(), 'uuid' => $uuid]
            );
        }
    }//end persist()
}//end class
