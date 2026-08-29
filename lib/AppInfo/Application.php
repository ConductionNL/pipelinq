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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\AppInfo;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\Pipelinq\Event\TimeEntryApprovedEvent;
use OCA\Pipelinq\Notification\Notifier;
use OCA\Pipelinq\Adapter\AzureDataLakeExportAdapter;
use OCA\Pipelinq\Adapter\BigQueryExportAdapter;
use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use OCA\Pipelinq\Adapter\GcsExportAdapter;
use OCA\Pipelinq\Adapter\PostgresExportAdapter;
use OCA\Pipelinq\Adapter\S3ExportAdapter;
use OCA\Pipelinq\Adapter\SftpExportAdapter;
use OCA\Pipelinq\Adapter\SnowflakeExportAdapter;
use OCA\Pipelinq\Listener\SchemaChangeListener;
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
use OCA\Pipelinq\Listener\DealCreatedListener;
use OCA\Pipelinq\Listener\DealUpdatedListener;
use OCA\Pipelinq\Listener\ExpenseApprovalListener;
use OCA\Pipelinq\Listener\ObjectEventListener;
use OCA\Pipelinq\Listener\PosTransactionCompletedListener;
use OCA\Pipelinq\Listener\ProjectCreationListener;
use OCA\Pipelinq\Listener\BerichtenboxZaakStatusListener;
use OCA\Pipelinq\Listener\ProjectPhaseStatusListener;
use OCA\Pipelinq\Listener\SlaObjectCreatedListener;
use OCA\Pipelinq\Listener\SlaObjectUpdatedListener;
use OCA\Pipelinq\Listener\SourceRecordChangedListener;
use OCA\Pipelinq\Listener\TimeApprovalListener;
use OCA\Pipelinq\Mcp\PipelinqToolProvider;
use OCA\Pipelinq\Service\AppointmentCalendarLeafProvider;
use OCA\Pipelinq\Service\AppointmentEmailService;
use OCA\Pipelinq\Service\AppointmentPaymentProvider;
use OCA\Pipelinq\Service\AvailabilityService;
use OCA\Pipelinq\Service\BookingService;
use OCA\Pipelinq\Service\WalkInQueueService;
use Throwable;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\Comments\ICommentsManager;
use OCP\IAppConfig;
use OCP\ICacheFactory;
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
        // AppHost (ADR-040): offload the mechanical observability + deep-link
        // ceremony to OpenRegister's shared engine. Scoped to the parity-safe,
        // fully-built halves — see registerAppHost() for why the Settings /
        // Preferences / repair plumbing stays bespoke.
        $this->registerAppHost(context: $context);

        // Moved here from appinfo/info.xml's <notification> block, which is not
        // in the appstore info.xsd at all — the file could never validate while
        // it was there. Deleting the block on its own would have been a silent
        // feature loss: nothing else registered this notifier, so notifications
        // would have stopped being delivered without a single error anywhere.
        $context->registerNotifierService(Notifier::class);

        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: ObjectEventListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: ObjectEventListener::class
        );
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: DealCreatedListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: DealUpdatedListener::class
        );
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: ProjectCreationListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: ProjectPhaseStatusListener::class
        );

        // Burgerportaal / MijnOverheid Berichtenbox bridge:
        // listen for zaak status transitions and queue an outbound
        // Berichtenbox message via BerichtenboxService
        // (burgerportaal-mijnoverheid-bridge / REQ-OUTBOUND-001).
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: BerichtenboxZaakStatusListener::class
        );

        // Shillinq WIP integration: time-entry approval dispatches a CloudEvent
        // to the configured shillinq webhook (pipelinq-time-to-shillinq-wip /
        // REQ-WIP-001). The listener is idempotent and a no-op when the
        // shillinq_wip_webhook_url app-config value is unset.
        $context->registerEventListener(
            event: TimeEntryApprovedEvent::class,
            listener: TimeApprovalListener::class
        );

        // Loyalty program: POS transaction completion fires the loyalty engine
        // (loyalty-program / REQ-LOY-002). The listener filters to posTransaction
        // entities + completed/settled/paid statuses, catches all errors, and never
        // throws so the POS flow is unaffected (REQ-LOY-002-05).
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: PosTransactionCompletedListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: PosTransactionCompletedListener::class
        );

        // Expense → Shillinq AP voucher dispatch on status=approved transitions
        // (pipelinq-expense-to-shillinq-ap / REQ-AP-002). Listener is filtered
        // to the expense schema and idempotent on apSyncStatus=synced so a
        // re-fired update event cannot create a duplicate AP voucher.
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: ExpenseApprovalListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: ExpenseApprovalListener::class
        );

        // SLA engine (sla-engine-and-escalation / REQ-001, REQ-003, REQ-007):
        // initialise slaStatus on tracked-object create, re-evaluate /
        // pause / resume / escalate on update. Listener exceptions are
        // swallowed (REQ-007 fail-safe).
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: SlaObjectCreatedListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: SlaObjectUpdatedListener::class
        );

        // MDM: recompute a Master Entity's golden record when a linked
        // source-record is created or updated (REQ-MDM-001).
        $context->registerEventListener(
            event: ObjectCreatedEvent::class,
            listener: SourceRecordChangedListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatedEvent::class,
            listener: SourceRecordChangedListener::class
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

        // Wave-4 external-API ports (low-volume families).
        //
        // - Logius Berichtenbox (burgerportaal-mijnoverheid-bridge):
        // the BBK 1.7 dispatch/verify/mailbox-check seam. The
        // existing concrete `LogiusConnector` HTTP client is
        // intentionally NOT bound here — it stays available for a
        // downstream activation step to wire in. The default
        // binding is the dormant log-only adapter so test +
        // staging environments never contact Logius.
        $context->registerServiceAlias(
            \OCA\Pipelinq\Service\External\Berichtenbox\BerichtenboxAdapterInterface::class,
            \OCA\Pipelinq\Service\External\Berichtenbox\LogBerichtenboxAdapter::class
        );

        $this->registerPosLifecycleGuards(context: $context);
        $this->registerExportServices(context: $context);
    }//end register()

    /**
     * Wire the OpenRegister AppHost engine (ADR-040), scoped to the parity-safe
     * halves: declarative observability (health + metrics) and the
     * manifest-driven deep-link listener.
     *
     * Each registration is a `registerService(name, Closure)`; the closure body
     * is the only place an `OCA\OpenRegister\AppHost\…` class is referenced, so
     * the closure runs lazily at dispatch time. A disabled / absent OpenRegister
     * therefore never fatals NC bootstrap — the first hit on an aliased route
     * surfaces a 5xx (the correct degraded behaviour) and `/api/health` reports
     * `orAvailable: failed`. This mirrors `AppHost\Bootstrap::register()`.
     *
     * Why this is NOT a single `Bootstrap::register(...)` call: `Bootstrap`
     * additionally aliases this app's `PreferencesController`, `SettingsController`,
     * `SettingsService`, `AdminSettings`, `SettingsSection` and the install repair
     * steps to its generics. Those cannot be adopted here without a regression:
     *   - OpenRegister `development` ships no `GenericPreferencesController` yet,
     *     so aliasing `PreferencesController` would 500 the `/api/preferences`
     *     route (the bespoke per-user `pref_` store is kept).
     *   - pipelinq's Settings stack (`SettingsService` + `SettingsLoadService` +
     *     `SettingsMapBuilder` + `SettingsController`) and its `AdminSettings`
     *     (which passes a `config` payload to `settings/admin`) are richer than
     *     the generic `['register']`-only service, and `InitializeSettings` is a
     *     domain repair step that seeds through that stack. These stay bespoke
     *     (adopt-apphost task 2.8).
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @psalm-suppress UndefinedClass The engine listener + the leaf listener
     *         alias are referenced as runtime-resolved string class names (the
     *         disabled-OpenRegister-safe pattern); neither is a compile-time
     *         dependency, so static analysis cannot resolve them.
     *
     * @spec openspec/changes/adopt-apphost/tasks.md#task-2.1
     */
    private function registerAppHost(IRegistrationContext $context): void
    {
        $appId = self::APP_ID;

        // /api/health + /api/metrics are served by thin subclasses of the
        // engine's GenericHealth/GenericMetricsController (see
        // lib/Controller/HealthController.php + MetricsController.php). They
        // autowire their OR collaborators and the parent class is only
        // autoloaded on route dispatch, so a disabled OpenRegister never fatals
        // bootstrap. No explicit registration is needed here for them.
        // Replace the bespoke DeepLinkRegistrationListener with the engine's
        // manifest-driven GenericDeepLinkRegistrationListener (reads the
        // `deepLinks` block from src/manifest.json).
        $deepLinkFactory = static function (ContainerInterface $c) use ($appId) {
            $class = 'OCA\\OpenRegister\\AppHost\\Listener\\GenericDeepLinkRegistrationListener';
            return new $class(
                appId: $appId,
                appManager: $c->get('OCP\\App\\IAppManager'),
                logger: $c->get('Psr\\Log\\LoggerInterface')
            );
        };
        $context->registerService(
            'OCA\\Pipelinq\\Listener\\DeepLinkRegistrationListener',
            $deepLinkFactory
        );
        $context->registerEventListener(
            'OCA\\OpenRegister\\Event\\DeepLinkRegistrationEvent',
            'OCA\\Pipelinq\\Listener\\DeepLinkRegistrationListener'
        );
    }//end registerAppHost()

    /**
     * Register the BI-export sink registry and the schema-change listener.
     *
     * The {@see ExportSinkRegistry} is wired with every concrete sink adapter
     * so {@see \OCA\Pipelinq\Service\Export\ExportUploadService} stays decoupled
     * from the warehouse transports. The {@see SchemaChangeListener} records
     * column drift on pipelinq schemas for the export audit (ADR-009).
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-008
     */
    private function registerExportServices(IRegistrationContext $context): void
    {
        $context->registerService(
            ExportSinkRegistry::class,
            static function ($c): ExportSinkRegistry {
                return new ExportSinkRegistry(
                    sinks: [
                        $c->get(S3ExportAdapter::class),
                        $c->get(BigQueryExportAdapter::class),
                        $c->get(SnowflakeExportAdapter::class),
                        $c->get(PostgresExportAdapter::class),
                        $c->get(AzureDataLakeExportAdapter::class),
                        $c->get(GcsExportAdapter::class),
                        $c->get(SftpExportAdapter::class),
                    ]
                );
            }
        );

        $context->registerEventListener(
            event: SchemaUpdatedEvent::class,
            listener: SchemaChangeListener::class
        );
    }//end registerExportServices()

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
     * Build the Features & Roadmap list from openspec/specs at runtime so the
     * surface stays current with the specs without depending on a committed
     * docs/features.json (which can drift). Cached per app version — the specs
     * only change when the app updates — with the committed docs/features.json
     * as a fallback for deploys that ship without openspec/.
     *
     * @return array<int, array{slug:string, title:string, summary:string, docsUrl:string}>
     */
    private function loadRoadmapFeatures(): array
    {
        $container = $this->getContainer();
        $version   = (string) $container->get(IAppManager::class)->getAppVersion('pipelinq');
        $cache     = $container->get(ICacheFactory::class)->createLocal('pipelinq_features');
        $cacheKey  = 'v'.$version;

        $cached = $cache->get($cacheKey);
        if (is_array($cached) === true) {
            return $cached;
        }

        $features = $this->extractFeaturesFromSpecs(specsDir: __DIR__.'/../../openspec/specs');
        if ($features === []) {
            $path = __DIR__.'/../../docs/features.json';
            if (is_file($path) === true) {
                $decoded = json_decode((string) file_get_contents($path), associative: true);
                if (is_array($decoded) === true) {
                    $features = $decoded;
                }
            }
        }

        $cache->set($cacheKey, $features, 86400);
        return $features;
    }//end loadRoadmapFeatures()

    /**
     * Parse `status: done` capability specs into feature entries. Mirrors the
     * org-wide extract-features.py and the docusaurus extractFeatures.js: the
     * status is read straight off the frontmatter line (resilient to YAML
     * typos in sibling fields), the title is the H1 minus a trailing
     * "Specification", and the summary is the first paragraph under `## Purpose`.
     *
     * @param string $specsDir Absolute path to openspec/specs.
     *
     * @return array<int, array{slug:string, title:string, summary:string, docsUrl:string}>
     */
    private function extractFeaturesFromSpecs(string $specsDir): array
    {
        if (is_dir($specsDir) === false) {
            return [];
        }

        $paths = glob($specsDir.'/*/spec.md');
        if ($paths === false) {
            return [];
        }

        $entries = [];
        foreach ($paths as $specPath) {
            $text = (string) file_get_contents($specPath);
            if (preg_match('/^---\s*\n(.*?\n)---\s*\n(.*)$/s', $text, $m) !== 1) {
                continue;
            }

            $front = $m[1];
            $body  = $m[2];
            if (preg_match('/^status:\s*(.+?)\s*$/m', $front, $sm) !== 1) {
                continue;
            }

            if (strtolower(trim($sm[1], " \t\"'")) !== 'done') {
                continue;
            }

            $slug  = basename(dirname($specPath));
            $title = $slug;
            if (preg_match('/^#\s+(.+?)\s*$/m', $body, $tm) === 1) {
                $title = trim((string) preg_replace('/\s+specification\s*$/i', '', trim($tm[1])));
            }

            $summary = '';
            if (preg_match('/^##\s+Purpose\s*$/m', $body, $pm, PREG_OFFSET_CAPTURE) === 1) {
                $rest = substr($body, ($pm[0][1] + strlen($pm[0][0])));
                if (preg_match('/\n##\s/', $rest, $nm, PREG_OFFSET_CAPTURE) === 1) {
                    $nextPos = $nm[0][1];
                } else {
                    $nextPos = strlen($rest);
                }

                $section = trim(substr($rest, 0, $nextPos));
                $para    = (preg_split('/\n\s*\n/', $section)[0] ?? '');
                $summary = trim((string) preg_replace('/\s+/', ' ', $para));
            }

            $entries[] = [
                'slug'    => $slug,
                'title'   => $title,
                'summary' => $summary,
                'docsUrl' => 'openspec/specs/'.$slug.'/spec.md',
            ];
        }//end foreach

        // Sort by slug (not full path) to match extract-features.py and
        // extractFeatures.js, which order by the capability slug.
        usort($entries, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return $entries;
    }//end extractFeaturesFromSpecs()

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

        // Hand the Features & Roadmap surface its feature list, derived from
        // openspec/specs at runtime so it always reflects the current specs
        // (cached per app version; see loadRoadmapFeatures). Pull IInitialState
        // from the per-app container so the serialized key is correctly
        // namespaced as `initial-state-pipelinq-<key>`.
        try {
            $initialState = $this->getContainer()->get(IInitialState::class);
            $initialState->provideInitialState(
                'features_roadmap_features',
                $this->loadRoadmapFeatures()
            );

            $manifestPath = __DIR__.'/../../src/manifest.json';
            $dependencies = [];
            if (is_file($manifestPath) === true) {
                $manifest = json_decode((string) file_get_contents($manifestPath), associative: true);
                if (is_array($manifest['dependencies'] ?? null) === true) {
                    $dependencies = $manifest['dependencies'];
                } else {
                    $dependencies = [];
                }
            }

            $appManager     = $this->getContainer()->get(IAppManager::class);
            $appStoreLookup = [];
            try {
                $appFetcher = $server->get(\OC\App\AppStore\Fetcher\AppFetcher::class);
                foreach ($appFetcher->get() as $storeApp) {
                    if (empty($storeApp['id']) === false && empty($storeApp['categories']) === false) {
                        $appStoreLookup[$storeApp['id']] = (array) $storeApp['categories'];
                    }
                }
            } catch (\Throwable) {
                // Intentionally ignored.
            }

            $dependencyStatus = [];
            foreach ($dependencies as $depId) {
                $onDisk   = false;
                $category = 'organization';
                try {
                    $appManager->getAppPath($depId);
                    $onDisk  = true;
                    $appInfo = \OC_App::getAppInfo($depId);
                    if (is_array($appInfo) === true && empty($appInfo['category']) === false) {
                        $category = (string) ((array) $appInfo['category'])[0];
                    }
                } catch (\Throwable) {
                    if (empty($appStoreLookup[$depId][0]) === false) {
                        $category = (string) $appStoreLookup[$depId][0];
                    }
                }

                $dependencyStatus[$depId] = [
                    'installed' => $onDisk,
                    'enabled'   => $appManager->isEnabledForUser($depId),
                    'category'  => $category,
                ];
            }//end foreach

            $initialState->provideInitialState('dependency_statuses', $dependencyStatus);
        } catch (\Exception $e) {
            // Initial state unavailable — Features tab will fall back to [].
        }//end try

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

        $this->wireAppointmentEmailSeam();

        // Wire the walk-in queue rebalance seam (member 09) into the booking
        // lifecycle so a Booking completion fires WalkInQueueService::rebalance.
        try {
            $bookingService     = $this->getContainer()->get(BookingService::class);
            $walkInQueueService = $this->getContainer()->get(WalkInQueueService::class);
            $bookingService->setWalkInQueueRebalance(service: $walkInQueueService);
        } catch (\Exception $e) {
            // Booking / walk-in surfaces not available — leave rebalance seam unset.
        }

        $this->wireAppointmentCalendarSeam();
        $this->wireAppointmentPaymentSeam();
    }//end boot()

    /**
     * Inject {@see AppointmentEmailService} into {@see BookingService} as the
     * confirmation email seam (member 07 of the appointment-booking chain).
     *
     * BookingService is constructed without the email provider and uses a
     * setter seam so the lifecycle code never depends on the email transport;
     * we wire the provider at boot so confirmation emails go out automatically
     * on booking create / confirm.
     *
     * @return void
     *
     * @spec openspec/changes/appointment-booking-07-email-confirmation-reminder/specs/appointment-booking/spec.md#req-apt-006
     */
    private function wireAppointmentEmailSeam(): void
    {
        try {
            $container      = $this->getContainer();
            $bookingService = $container->get(BookingService::class);
            $emailProvider  = $container->get(AppointmentEmailService::class);
            $bookingService->setEmailProvider(provider: $emailProvider);
        } catch (Throwable $e) {
            // OpenRegister or one of the collaborators is unavailable — the
            // seam stays null and bookings still transition; this is the
            // documented graceful-degradation path from BookingService.
        }
    }//end wireAppointmentEmailSeam()

    /**
     * Inject {@see AppointmentCalendarLeafProvider} into the appointment
     * services as the calendar-leaf seam (member 10 of the chain).
     *
     * AvailabilityService consumes the seam in `getBlockedTimes` to merge
     * leaf-synced staff calendar VEVENTs into the slot computation;
     * BookingService consumes it after every confirmed-transition to push
     * the booking to staff calendars (REQ-APT-018). Setter seams keep the
     * lifecycle code independent of the calendar transport; we wire the
     * provider at boot so the merge + push happen automatically.
     *
     * @return void
     *
     * @spec openspec/changes/appointment-booking-10-calendar-sync/specs/appointment-booking/spec.md#req-apt-018
     */
    private function wireAppointmentCalendarSeam(): void
    {
        try {
            $container           = $this->getContainer();
            $calendarProvider    = $container->get(AppointmentCalendarLeafProvider::class);
            $availabilityService = $container->get(AvailabilityService::class);
            $bookingService      = $container->get(BookingService::class);
            $availabilityService->setCalendarProvider(provider: $calendarProvider);
            $bookingService->setCalendarProvider(provider: $calendarProvider);
        } catch (Throwable $e) {
            // OpenRegister or one of the collaborators is unavailable — the
            // seam stays null and bookings still transition; this is the
            // documented graceful-degradation path from BookingService.
        }
    }//end wireAppointmentCalendarSeam()

    /**
     * Inject {@see AppointmentPaymentProvider} into {@see BookingService} as
     * the no-show + late-cancellation fee payment seam (member 08 of the
     * appointment-booking chain).
     *
     * The provider routes the BookingService::chargeNoShowFee /
     * chargeCancellationFee invocations through openconnector. The seam is
     * optional: when either service cannot be resolved BookingService keeps
     * recording the fee intent in statusHistory but skips the transport.
     *
     * @return void
     *
     * @spec openspec/changes/appointment-booking-08-deposit-payment/specs/appointment-booking/spec.md#req-apt-011a
     */
    private function wireAppointmentPaymentSeam(): void
    {
        try {
            $container       = $this->getContainer();
            $bookingService  = $container->get(BookingService::class);
            $paymentProvider = $container->get(AppointmentPaymentProvider::class);
            $bookingService->setPaymentProvider(provider: $paymentProvider);
        } catch (Throwable $e) {
            // OpenRegister or one of the collaborators is unavailable — the
            // seam stays null and bookings still transition; the fee is then
            // recorded in statusHistory only, never transported.
        }
    }//end wireAppointmentPaymentSeam()
}//end class
