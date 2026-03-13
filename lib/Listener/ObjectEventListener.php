<?php

/**
 * Pipelinq ObjectEventListener.
 *
 * Listener for OpenRegister object events to trigger Pipelinq notifications and activity.
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

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Service\ObjectEventHandlerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Listener for OpenRegister object create/update events.
 */
class ObjectEventListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param ObjectEventHandlerService $handlerService The event handler service.
     */
    public function __construct(
        private ObjectEventHandlerService $handlerService,
    ) {
    }//end __construct()

    /**
     * Handle an incoming event.
     *
     * @param Event $event The event to handle.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatedEvent) {
            $this->handlerService->handleCreated(objectEntity: $event->getObject());
        } else if ($event instanceof ObjectUpdatedEvent) {
            $this->handlerService->handleUpdated(
                newObject: $event->getNewObject(),
                oldObject: $event->getOldObject()
            );
        }
    }//end handle()
}//end class
