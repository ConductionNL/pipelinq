<?php

/**
 * Pipelinq ObjectDeletedListener.
 *
 * Listener for OpenRegister object deletion events to clean up associated notes.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\Pipelinq\Service\NotesService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listener that cleans up notes when an OpenRegister object is deleted.
 *
 * @implements IEventListener<Event>
 */
class ObjectDeletedListener implements IEventListener
{
    /**
     * Entity type to notes object type mapping.
     *
     * @var array<string, string>
     */
    private const NOTE_TYPE_MAP = [
        'client'  => 'pipelinq_client',
        'contact' => 'pipelinq_contact',
        'lead'    => 'pipelinq_lead',
        'request' => 'pipelinq_request',
    ];

    /**
     * Constructor.
     *
     * @param SchemaMapService $schemaMapService The schema map service.
     * @param NotesService     $notesService     The notes service.
     * @param LoggerInterface  $logger           The logger.
     */
    public function __construct(
        private SchemaMapService $schemaMapService,
        private NotesService $notesService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle the ObjectDeletedEvent by cleaning up associated notes.
     *
     * @param Event $event The event to handle.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectDeletedEvent) === false) {
            return;
        }

        try {
            $object     = $event->getObject();
            $schemaId   = $object->getSchema();
            $entityType = $this->schemaMapService->resolveEntityType($schemaId);

            if ($entityType === null) {
                return;
            }

            $noteObjectType = self::NOTE_TYPE_MAP[$entityType] ?? null;
            if ($noteObjectType === null) {
                return;
            }

            $objectId = $object->getUuid();
            if ($objectId === null || $objectId === '') {
                return;
            }

            $this->notesService->deleteAllNotes(
                objectType: $noteObjectType,
                objectId: $objectId
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                'Failed to clean up notes on entity deletion',
                [
                    'exception' => $e->getMessage(),
                ]
            );
        }//end try
    }//end handle()
}//end class
