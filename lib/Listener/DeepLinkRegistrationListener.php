<?php

/**
 * Pipelinq DeepLinkRegistrationListener
 *
 * Registers Pipelinq's deep link URL patterns with OpenRegister's search provider.
 *
 * @category Listener
 * @package  OCA\Pipelinq\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Listener;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers Pipelinq's deep link URL patterns with OpenRegister's search provider.
 *
 * When a user searches in Nextcloud's unified search, results for Pipelinq schemas
 * (clients, leads, requests, contacts) will link directly to Pipelinq's detail views.
 */
class DeepLinkRegistrationListener implements IEventListener
{
    /**
     * Handle the deep link registration event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof DeepLinkRegistrationEvent === false) {
            return;
        }

        $event->register(
            appId: 'pipelinq',
            registerSlug: 'pipelinq',
            schemaSlug: 'client',
            urlTemplate: '/apps/pipelinq/#/clients/{uuid}'
        );

        $event->register(
            appId: 'pipelinq',
            registerSlug: 'pipelinq',
            schemaSlug: 'lead',
            urlTemplate: '/apps/pipelinq/#/leads/{uuid}'
        );

        $event->register(
            appId: 'pipelinq',
            registerSlug: 'pipelinq',
            schemaSlug: 'request',
            urlTemplate: '/apps/pipelinq/#/requests/{uuid}'
        );

        $event->register(
            appId: 'pipelinq',
            registerSlug: 'pipelinq',
            schemaSlug: 'contact',
            urlTemplate: '/apps/pipelinq/#/contacts/{uuid}'
        );
    }//end handle()
}//end class
