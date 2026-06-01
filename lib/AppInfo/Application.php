<?php

/**
 * Pipelinq Application
 *
 * Main application class for the Pipelinq client and request management app.
 *
 * @category AppInfo
 * @package  OCA\Pipelinq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\AppInfo;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\Dashboard\CreateLeadWidget;
use OCA\Pipelinq\Dashboard\DealsOverviewWidget;
use OCA\Pipelinq\Dashboard\FindClientWidget;
use OCA\Pipelinq\Dashboard\MyLeadsWidget;
use OCA\Pipelinq\Dashboard\RecentActivitiesWidget;
use OCA\Pipelinq\Dashboard\StartRequestWidget;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Lifecycle\PosRefundManagerGuard;
use OCA\Pipelinq\Lifecycle\PosTransactionAccessGuard;
use OCA\Pipelinq\Lifecycle\PosTransactionConfirmGuard;
use OCA\Pipelinq\Lifecycle\PosTransactionRefundGuard;
use OCA\Pipelinq\BackgroundJob\VerifyAuditChainJob;
use OCA\Pipelinq\Listener\DeepLinkRegistrationListener;
use OCA\Pipelinq\Listener\ObjectEventListener;
use OCA\Pipelinq\Mcp\PipelinqToolProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\Comments\ICommentsManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Main application class for the Pipelinq client and request management app.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
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

        $context->registerDashboardWidget(DealsOverviewWidget::class);
        $context->registerDashboardWidget(MyLeadsWidget::class);
        $context->registerDashboardWidget(RecentActivitiesWidget::class);
        $context->registerDashboardWidget(FindClientWidget::class);
        $context->registerDashboardWidget(StartRequestWidget::class);
        $context->registerDashboardWidget(CreateLeadWidget::class);

        // Register PipelinqToolProvider as the MCP tool provider for the AI Chat Companion.
        // The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::pipelinq' is the format
        // that OR's McpToolsService enumerates to discover per-app providers (design D3).
        // The interface ships in openregister PR #1466 (ai-chat-companion-orchestrator);
        // until then this app implements the tests/Stubs/Mcp/IMcpToolProvider.php stub.
        $context->registerServiceAlias(
            'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::pipelinq',
            PipelinqToolProvider::class
        );

        $this->registerPosLifecycleGuards(context: $context);

        // Background job: hourly Kassakoppeling audit chain verification.
        $context->registerBackgroundJob(VerifyAuditChainJob::class);
    }//end register()

    /**
     * Register the POS lifecycle guards keyed by the FQCN tag the
     * posTransaction / posRefund schemas reference in their
     * x-openregister-lifecycle.transitions[*].requires.
     *
     * OpenRegister's LifecycleGuardRegistry resolves the `requires` tag to a
     * concrete LifecycleGuardInterface instance via the app container (with the
     * NC server container as FQCN fallback). The registry is fail-closed: a
     * transition that references an unregistered guard tag cannot proceed, so
     * these registrations are load-bearing for the POS lifecycle.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     */
    private function registerPosLifecycleGuards(IRegistrationContext $context): void
    {
        $context->registerService(
            PosAccessPolicy::class,
            static function ($c): PosAccessPolicy {
                return new PosAccessPolicy(
                    appConfig: $c->get(IAppConfig::class),
                    groupManager: $c->get(IGroupManager::class),
                );
            }
        );

        $context->registerService(
            PosTransactionAccessGuard::class,
            static function ($c): PosTransactionAccessGuard {
                return new PosTransactionAccessGuard(policy: $c->get(PosAccessPolicy::class));
            }
        );

        $context->registerService(
            PosTransactionConfirmGuard::class,
            static function ($c): PosTransactionConfirmGuard {
                return new PosTransactionConfirmGuard(
                    policy: $c->get(PosAccessPolicy::class),
                    container: $c->get(ContainerInterface::class),
                    appConfig: $c->get(IAppConfig::class),
                    logger: $c->get(LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            PosTransactionRefundGuard::class,
            static function ($c): PosTransactionRefundGuard {
                return new PosTransactionRefundGuard(policy: $c->get(PosAccessPolicy::class));
            }
        );

        $context->registerService(
            PosRefundManagerGuard::class,
            static function ($c): PosRefundManagerGuard {
                return new PosRefundManagerGuard(
                    policy: $c->get(PosAccessPolicy::class),
                    container: $c->get(ContainerInterface::class),
                    appConfig: $c->get(IAppConfig::class),
                    logger: $c->get(LoggerInterface::class),
                );
            }
        );
    }//end registerPosLifecycleGuards()

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

        // Hand the Features & Roadmap surface its build-time feature list.
        // docs/features.json is regenerated from openspec/specs/ by the
        // org-wide Features Extract workflow stage (.github/workflows/quality.yml).
        // Pull IInitialState from the per-app container so the serialized key
        // is correctly namespaced as `initial-state-pipelinq-<key>`.
        try {
            $initialState = $this->getContainer()->get(IInitialState::class);
            $featuresPath = __DIR__.'/../../docs/features.json';
            $features     = [];
            if (is_file($featuresPath) === true) {
                $decoded = json_decode((string) file_get_contents($featuresPath), associative: true);
                if (is_array($decoded) === true) {
                    $features = $decoded;
                }
            }

            $initialState->provideInitialState('features_roadmap_features', $features);
        } catch (\Exception $e) {
            // Initial state unavailable — Features tab will fall back to [].
        }

        try {
            $commentsManager = $server->get(ICommentsManager::class);
            $commentsManager->registerDisplayNameResolver(
                type: 'pipelinq_client',
                closure: function (string $_id): string {
                    return 'Client';
                }
            );
            $commentsManager->registerDisplayNameResolver(
                type: 'pipelinq_contact',
                closure: function (string $_id): string {
                    return 'Contact';
                }
            );
            $commentsManager->registerDisplayNameResolver(
                type: 'pipelinq_lead',
                closure: function (string $_id): string {
                    return 'Lead';
                }
            );
            $commentsManager->registerDisplayNameResolver(
                type: 'pipelinq_request',
                closure: function (string $_id): string {
                    return 'Request';
                }
            );
        } catch (\Exception $e) {
            // Comments manager not available — skip registration.
        }//end try
    }//end boot()
}//end class
