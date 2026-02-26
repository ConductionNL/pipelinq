<?php

/**
 * Pipelinq Application
 *
 * Main application class for the Pipelinq client and request management app.
 *
 * @category AppInfo
 * @package  OCA\Pipelinq\AppInfo
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

namespace OCA\Pipelinq\AppInfo;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Listener\DeepLinkRegistrationListener;
use OCA\Pipelinq\Listener\ObjectEventListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Comments\ICommentsManager;

/**
 * Main application class for the Pipelinq client and request management app.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'pipelinq';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register event listeners and services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     */
    public function register(IRegistrationContext $context): void
    {
        $context->registerEventListener(
            event: DeepLinkRegistrationEvent::class,
            listener: DeepLinkRegistrationListener::class
        );
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: ObjectEventListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: ObjectEventListener::class
        );
    }//end register()

    /**
     * Boot the application and register comment display name resolvers.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     */
    public function boot(IBootContext $context): void
    {
        $server = $context->getServerContainer();

        try {
            $commentsManager = $server->get(ICommentsManager::class);
            $commentsManager->registerDisplayNameResolver(
                type: 'pipelinq_client',
                callable: function (string $id): string {
                    return 'Client';
                }
            );
            $commentsManager->registerDisplayNameResolver(
                type: 'pipelinq_contact',
                callable: function (string $id): string {
                    return 'Contact';
                }
            );
            $commentsManager->registerDisplayNameResolver(
                type: 'pipelinq_lead',
                callable: function (string $id): string {
                    return 'Lead';
                }
            );
            $commentsManager->registerDisplayNameResolver(
                type: 'pipelinq_request',
                callable: function (string $id): string {
                    return 'Request';
                }
            );
        } catch (\Exception $e) {
            // Comments manager not available — skip registration.
        }//end try
    }//end boot()
}//end class
