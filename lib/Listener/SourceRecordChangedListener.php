<?php

/**
 * Pipelinq SourceRecordChangedListener.
 *
 * Listens for OpenRegister object create/update events on the MDM `sourceRecord`
 * schema and recomputes the golden record of the linked Master Entity, so a
 * change in any source system immediately re-resolves the trust-tier
 * survivorship for the affected master entity.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Service\Mdm\MasterEntityService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Recomputes a Master Entity's golden record when a linked source record changes.
 *
 * @implements IEventListener<Event>
 */
class SourceRecordChangedListener implements IEventListener
{
    /**
     * The sourceRecord schema slug this listener reacts to.
     *
     * @var string
     */
    private const SOURCE_SLUG = 'sourceRecord';

    /**
     * Constructor.
     *
     * @param MasterEntityService $masterEntities   The master-entity service.
     * @param SchemaMapService    $schemaMapService The schema map service.
     * @param LoggerInterface     $logger           The logger.
     */
    public function __construct(
        private MasterEntityService $masterEntities,
        private SchemaMapService $schemaMapService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an incoming OR object event.
     *
     * @param Event $event The event to handle.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        $object = null;
        if ($event instanceof ObjectCreatedEvent) {
            $object = $event->getObject();
        } else if ($event instanceof ObjectUpdatedEvent) {
            $object = $event->getNewObject();
        }

        if ($object === null) {
            return;
        }

        try {
            $entityType = $this->schemaMapService->resolveEntityType(schemaId: $object->getSchema());
        } catch (\Throwable $e) {
            return;
        }

        if ($entityType !== self::SOURCE_SLUG) {
            return;
        }

        $serialized = $object->jsonSerialize();
        $masterId   = '';
        if (is_array($serialized) === true) {
            $masterId = (string) ($serialized['currentMasterEntity'] ?? '');
        }

        if ($masterId === '') {
            return;
        }

        try {
            $this->masterEntities->recomputeGoldenRecord($masterId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq MDM: golden-record recompute failed on source-record change',
                ['master' => $masterId, 'exception' => $e->getMessage()]
            );
        }
    }//end handle()
}//end class
