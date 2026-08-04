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
use OCA\Pipelinq\Listener\ObjectsMergedSyncListener;
use OCA\Pipelinq\Listener\TimeApprovalListener;
use OCA\Pipelinq\Mcp\PipelinqScannableServices;
use OCA\Pipelinq\Service\AppointmentCalendarLeafProvider;
use OCA\Pipelinq\Service\AppointmentEmailService;
use OCA\Pipelinq\Service\AppointmentPaymentProvider;
use OCA\Pipelinq\Service\AvailabilityService;
use OCA\Pipelinq\Service\BookingService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\Gdpr\PipelinqApRegulatorEscalateProvider;
use OCA\Pipelinq\Service\Gdpr\PipelinqBsnIdentityVerifyProvider;
use OCA\Pipelinq\Service\HaalCentraalClient;
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
 * @spec exclude Main app bootstrap class; per-change spec coverage lives on the changed methods.
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
     *
     * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
     * @spec openspec/specs/avg-verzoeken-workflow/spec.md
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) A flat DI registration
     *  manifest — one linear list of service/listener wirings, not branching logic.
     */
    public function register(IRegistrationContext $context): void
    {
        // AppHost (ADR-040): offload the mechanical observability + deep-link
        // ceremony to OpenRegister's shared engine. Scoped to the parity-safe,
        // fully-built halves — see registerAppHost() for why the Settings /
        // Preferences / repair plumbing stays bespoke.
        $this->registerAppHost(context: $context);

        // Notifier registration. Previously declared via a <notification>
        // element in info.xml, which Nextcloud core never reads (and which
        // app-info.xsd rejects) — the IBootstrap registration below is the
        // canonical path, fixed with the align-claims-and-first-hour
        // conformance sweep.
        $context->registerNotifierService(\OCA\Pipelinq\Notification\Notifier::class);

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

        // MDM: OpenRegister now materialises the golden record on save via its
        // SurvivorshipRecomputeListener (x-openregister-survivorship), so the
        // app-side recompute-on-source-change listener is retired. Instead we
        // subscribe to OR's ObjectsMergedEvent to enqueue downstream sync after a
        // merge / reversal (REQ-MDM-004; ADR-041 event-not-RPC propagation).
        $context->registerEventListener(
            event: \OCA\OpenRegister\Event\ObjectsMergedEvent::class,
            listener: ObjectsMergedSyncListener::class
        );

        $context->registerDashboardWidget(DealsOverviewWidget::class);
        $context->registerDashboardWidget(MyLeadsWidget::class);
        $context->registerDashboardWidget(RecentActivitiesWidget::class);
        $context->registerDashboardWidget(FindClientWidget::class);
        $context->registerDashboardWidget(StartRequestWidget::class);
        $context->registerDashboardWidget(CreateLeadWidget::class);

        // Register PipelinqScannableServices as the MCP attribute-scan opt-in for the
        // AI Chat Companion (ADR-063 chain 3/3, openregister PR #363). The alias key
        // 'OCA\OpenRegister\Mcp\IMcpScannableServices::pipelinq' is the format OR's
        // Application::collectAttributeMcpProviders() enumerates to discover each app's
        // scannable service classes, mirroring the retired per-app
        // 'IMcpToolProvider::pipelinq' convention. Pipelinq no longer ships a
        // hand-written IMcpToolProvider — every CRUD read is served by OpenRegister's
        // schema-derived tools, and the three curated tools (createLead,
        // logContactmoment, pipelineForecast) are `#[McpTool]`-attributed methods on
        // LeadService/TicketService, reflected via this opt-in (plq-mcp-provider-surgery).
        // Until openregister is installed this app implements the
        // tests/Stubs/Mcp/IMcpScannableServices.php + tests/Stubs/Mcp/Attribute/McpTool.php stubs.
        $context->registerServiceAlias(
            'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::pipelinq',
            PipelinqScannableServices::class
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
        $this->registerGdprSeamProviders(context: $context);
    }//end register()

    /**
     * Register pipelinq's two AVG/DSAR integration-seam providers as services
     * (ADR-047 Phase-3 / ADR-019). The providers implement OpenRegister's
     * `IdentityVerifyProvider` / `RegulatorEscalateProvider` contracts; they are
     * registered here as lazy service factories and are added to OR's registries
     * from {@see wireGdprSeamProviders()} in `boot()` — matching the OR registry
     * contract's "each app registers its provider from its own boot hook"
     * guidance, where the cross-app OR registry can be safely resolved. Both
     * factories only construct the OR-interface-implementing class on resolve, so
     * a disabled / absent OpenRegister never fatals bootstrap.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     *
     * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
     */
    private function registerGdprSeamProviders(IRegistrationContext $context): void
    {
        $context->registerService(
            PipelinqBsnIdentityVerifyProvider::class,
            static function ($c): PipelinqBsnIdentityVerifyProvider {
                return new PipelinqBsnIdentityVerifyProvider(
                    bsnValidation: $c->get(BsnValidationService::class),
                    brpClient: $c->get(HaalCentraalClient::class),
                    logger: $c->get(LoggerInterface::class),
                );
            }
        );

        $context->registerService(
            PipelinqApRegulatorEscalateProvider::class,
            static function ($c): PipelinqApRegulatorEscalateProvider {
                return new PipelinqApRegulatorEscalateProvider(logger: $c->get(LoggerInterface::class));
            }
        );
    }//end registerGdprSeamProviders()

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

        // /api/health + /api/metrics are served by lib/Controller/
        // HealthController.php + MetricsController.php, which adopt the engine
        // by COMPOSITION: they resolve ManifestLoader / HealthCheckExecutor /
        // MetricsEngine out of the DI container by FQCN string at dispatch
        // time and never name an OpenRegister class in a position the
        // autoloader must resolve. They must NOT go back to `extends
        // Generic*Controller`: NC's router ReflectionClass()es every file in
        // lib/Controller/ while MATCHING any route, so one unresolvable parent
        // 500s EVERY pipelinq route, not just its own (decidesk#377). With
        // OpenRegister absent they degrade — health 200 `degraded`, metrics
        // 503 — instead of fatalling. No explicit registration is needed here.
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
            if (preg_match('/^---\s*\n(.*?\n)---\s*\n(.*)$/s', $text, $matches) !== 1) {
                continue;
            }

            $front = $matches[1];
            $body  = $matches[2];
            if (preg_match('/^status:\s*(.+?)\s*$/m', $front, $statusMatch) !== 1) {
                continue;
            }

            if (strtolower(trim($statusMatch[1], " \t\"'")) !== 'done') {
                continue;
            }

            $slug  = basename(dirname($specPath));
            $title = $slug;
            if (preg_match('/^#\s+(.+?)\s*$/m', $body, $titleMatch) === 1) {
                $title = trim((string) preg_replace('/\s+specification\s*$/i', '', trim($titleMatch[1])));
            }

            $entries[] = [
                'slug'    => $slug,
                'title'   => $title,
                'summary' => $this->extractSummary(body: $body),
                'docsUrl' => 'openspec/specs/'.$slug.'/spec.md',
            ];
        }//end foreach

        // Sort by slug (not full path) to match extract-features.py and
        // extractFeatures.js, which order by the capability slug.
        usort($entries, static fn(array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return $entries;
    }//end extractFeaturesFromSpecs()

    /**
     * Extract the first paragraph under `## Purpose` as the feature summary.
     *
     * @param string $body Spec markdown body (frontmatter stripped).
     *
     * @return string Collapsed single-line summary, or empty when absent.
     */
    private function extractSummary(string $body): string
    {
        if (preg_match('/^##\s+Purpose\s*$/m', $body, $purposeMatch, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }

        $rest    = substr($body, ($purposeMatch[0][1] + strlen($purposeMatch[0][0])));
        $nextPos = strlen($rest);
        if (preg_match('/\n##\s/', $rest, $nextMatch, PREG_OFFSET_CAPTURE) === 1) {
            $nextPos = $nextMatch[0][1];
        }

        $section = trim(substr($rest, 0, $nextPos));
        $para    = (preg_split('/\n\s*\n/', $section)[0] ?? '');
        return trim((string) preg_replace('/\s+/', ' ', $para));
    }//end extractSummary()

    /**
     * Boot the application and register comment display name resolvers.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
     */
    public function boot(IBootContext $context): void
    {
        $server = $context->getServerContainer();

        // Hand the Features & Roadmap surface its feature list, derived from
        // openspec/specs at runtime so it always reflects the current specs
        // (cached per app version; see loadRoadmapFeatures). Pull IInitialState
        // from the per-app container so the serialized key is correctly
        // namespaced as `initial-state-pipelinq-<key>`.
        // Initial state exists for PAGE loads. An API request — an object
        // create, a webhook, a DAV call — serialises it into a response nobody
        // reads, and `resolveDependencyStatuses()` below walks the appstore
        // catalogue to build it. Skip the whole block when nothing will render.
        // See ADR-076 and openregister/openspec/changes/object-write-at-instance-floor.
        if ($this->requestRendersPage(server: $server) === false) {
            $this->bootNonPageSurfaces(server: $server);
            return;
        }

        try {
            $initialState = $this->getContainer()->get(IInitialState::class);
            $initialState->provideInitialState(
                'features_roadmap_features',
                $this->loadRoadmapFeatures()
            );

            $dependencies     = $this->readManifestDependencies();
            $dependencyStatus = $this->resolveDependencyStatuses(context: $context, dependencies: $dependencies);
            $initialState->provideInitialState('dependency_statuses', $dependencyStatus);

            // Reporting currency (persisted by the setup wizard, default EUR)
            // seeds the SPA's `config` initial state so manifest dashboards can
            // format currency KPIs via the `@config.currency` token. Serialized
            // as `initial-state-pipelinq-config` and read in main.js via
            // loadState('pipelinq', 'config').
            $appConfig = $this->getContainer()->get(IAppConfig::class);
            $initialState->provideInitialState(
                'config',
                ['currency' => $appConfig->getValueString(self::APP_ID, 'currency', 'EUR')]
            );
        } catch (\Exception $e) {
            // Initial state unavailable — Features tab will fall back to [].
        }//end try

        $this->bootNonPageSurfaces(server: $server);
    }//end boot()

    /**
     * Add pipelinq's two AVG/DSAR seam providers to OpenRegister's registries
     * (ADR-047 Phase-3 / ADR-019, first-wins). The registries are OR-owned
     * shared services resolved from the app container at boot; the whole wiring
     * is wrapped per registry so a disabled / absent OpenRegister (or an OR build
     * predating the Gdpr seams) never fatals boot — in that case the NL pack's
     * selector simply resolves to OR's fail-closed default provider and identity
     * stays unverified / escalation stays refused (ADR-005 / CWE-863).
     *
     * @return void
     *
     * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
     */
    private function wireGdprSeamProviders(): void
    {
        $identityRegistryClass  = 'OCA\\OpenRegister\\Service\\Gdpr\\Identity\\IdentityVerifyRegistry';
        $regulatorRegistryClass = 'OCA\\OpenRegister\\Service\\Gdpr\\Regulator\\RegulatorEscalateRegistry';
        $container = $this->getContainer();

        try {
            $identityRegistry = $container->get($identityRegistryClass);
            $identityRegistry->addProvider($container->get(PipelinqBsnIdentityVerifyProvider::class));
        } catch (Throwable $e) {
            // OpenRegister absent or the identity-verify seam not present — the
            // NL pack selector resolves OR's fail-closed default (unverified).
        }

        try {
            $regulatorRegistry = $container->get($regulatorRegistryClass);
            $regulatorRegistry->addProvider($container->get(PipelinqApRegulatorEscalateProvider::class));
        } catch (Throwable $e) {
            // OpenRegister absent or the regulator-escalate seam not present — the
            // NL pack selector resolves OR's fail-closed default (refused).
        }
    }//end wireGdprSeamProviders()

    /**
     * Register pipelinq's CRM objects as an evidence source in OpenRegister's
     * DSAR case engine (ADR-047 Phase 3 / ADR-019 seam).
     *
     * OpenRegister's EvidenceHarvestService enumerates only providers added to
     * its EvidenceSourceRegistry, so this registration is what makes pipelinq
     * data reachable during a data-subject request. The registry is resolved
     * lazily through the container so a disabled / absent OpenRegister never
     * fatals bootstrap — the registration is simply skipped.
     *
     * @return void
     *
     * @spec openspec/specs/avg-verzoeken-workflow/spec.md#requirement-req-avg-016-pipelinq-evidence-source-registration
     */
    private function registerDsarEvidenceSource(): void
    {
        try {
            $registry = $this->getContainer()->get('OCA\\OpenRegister\\Service\\Gdpr\\Evidence\\EvidenceSourceRegistry');
            $provider = $this->getContainer()->get(\OCA\Pipelinq\Service\PipelinqEvidenceSourceProvider::class);
            $registry->addProvider($provider);
        } catch (\Throwable $e) {
            // OpenRegister absent or DSAR engine not present — pipelinq boots
            // without contributing DSAR evidence. No Throwable escapes.
        }
    }//end registerDsarEvidenceSource()

    /**
     * Whether this request will render a page that can read initial state.
     *
     * `provideInitialState()` only reaches a browser through a rendered
     * template. On an API request — an object create, a webhook, a DAV call —
     * it is computed, serialised and discarded, and the computation walks the
     * appstore catalogue to do it.
     *
     * Judged from the request path rather than the response type, because the
     * decision has to be made in boot(), before a controller is selected. Errs
     * toward TRUE: a misjudged page request loses an optimisation, a misjudged
     * API request would lose functionality.
     *
     * @param mixed $server The server container.
     *
     * @return boolean True when initial state is worth computing.
     *
     * @spec openspec/changes/object-write-at-instance-floor/specs/object-write-performance/spec.md
     */
    private function requestRendersPage($server): bool
    {
        try {
            $request = $server->get(\OCP\IRequest::class);
            $path    = (string) $request->getPathInfo();
        } catch (\Throwable) {
            // Cannot tell — assume it renders, so we never remove state a page
            // needs on the strength of a failed guess.
            return true;
        }

        if ($path === '') {
            return true;
        }

        // Anything under an API, DAV, OCS or asset route renders no template.
        foreach (['/api/', '/apps/pipelinq/api', '/ocs/', '/remote.php', '/dav', '/webhook', '/css/', '/js/'] as $needle) {
            if (str_contains($path, $needle) === true) {
                return false;
            }
        }

        return true;

    }//end requestRendersPage()

    /**
     * Boot the parts that must run regardless of whether a page renders.
     *
     * Comment resolvers, seams and provider registrations are wiring: something
     * later in the request may depend on them, so they cannot be skipped for API
     * requests the way initial state can.
     *
     * @param mixed $server The server container.
     *
     * @return void
     */
    private function bootNonPageSurfaces($server): void
    {
        $this->registerCommentResolvers(server: $server);
        $this->wireAppointmentEmailSeam();
        $this->wireBookingWalkInRebalance();
        $this->wireAppointmentCalendarSeam();
        $this->wireAppointmentPaymentSeam();
        $this->wireGdprSeamProviders();
        $this->registerDsarEvidenceSource();

    }//end bootNonPageSurfaces()

    /**
     * Register comment display-name resolvers for pipelinq's comment types.
     *
     * @param mixed $server The server container.
     *
     * @return void
     */
    private function registerCommentResolvers($server): void
    {
        try {
            $commentsManager = $server->get(ICommentsManager::class);
            foreach ([
                'pipelinq_client'  => 'Client',
                'pipelinq_contact' => 'Contact',
                'pipelinq_lead'    => 'Lead',
                'pipelinq_request' => 'Request',
            ] as $type => $label
            ) {
                $commentsManager->registerDisplayNameResolver(
                    type: $type,
                    closure: static function (string $_id) use ($label): string {
                        return $label;
                    }
                );
            }
        } catch (\Exception $e) {
            // Comments manager not available — skip registration.
        }//end try

    }//end registerCommentResolvers()

    /**
     * Wire the walk-in queue rebalance seam into the booking lifecycle, so a
     * Booking completion fires WalkInQueueService::rebalance.
     *
     * @return void
     */
    private function wireBookingWalkInRebalance(): void
    {
        try {
            $bookingService     = $this->getContainer()->get(BookingService::class);
            $walkInQueueService = $this->getContainer()->get(WalkInQueueService::class);
            $bookingService->setWalkInQueueRebalance(service: $walkInQueueService);
        } catch (\Exception $e) {
            // Booking / walk-in surfaces not available — leave rebalance seam unset.
        }

    }//end wireBookingWalkInRebalance()

    /**
     * Read the SPA manifest's declared app dependencies, as a flat id list.
     *
     * The manifest schema (`app-manifest-v2.schema.json`) allows each entry to
     * be EITHER a bare string (a HARD dependency) OR an object
     * `{ id, required?, name? }`, where `required: false` marks a SOFT one.
     *
     * Everything downstream of here — `resolveDependencyStatuses()` — only
     * needs the app id: it reports installed/enabled/category per app, and the
     * HARD-vs-SOFT distinction is a frontend concern that `CnAppRoot` reads
     * from the manifest itself. So normalise to ids here, and let a malformed
     * entry drop out rather than reach `implode()` / an array array-key, both
     * of which would take the whole page down from inside `boot()`.
     *
     * @return array<int, string> Dependency app IDs (empty when absent/malformed).
     */
    private function readManifestDependencies(): array
    {
        $manifestPath = __DIR__.'/../../src/manifest.json';
        if (is_file($manifestPath) === false) {
            return [];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), associative: true);
        if (is_array($manifest['dependencies'] ?? null) === false) {
            return [];
        }

        $ids = [];
        foreach ($manifest['dependencies'] as $entry) {
            $id = $entry;
            if (is_array($entry) === true) {
                $id = ($entry['id'] ?? null);
            }

            if (is_string($id) === true && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }//end readManifestDependencies()

    /**
     * Build an app-store id → categories lookup (best-effort).
     *
     * @param IBootContext $context The boot context.
     *
     * @return array<string, array<int, mixed>> Categories keyed by app id.
     */
    private function buildAppStoreLookup(IBootContext $context): array
    {
        $server         = $context->getServerContainer();
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

        return $appStoreLookup;
    }//end buildAppStoreLookup()

    /**
     * Resolve installed/enabled/category status for each declared dependency.
     *
     * @param IBootContext       $context      The boot context.
     * @param array<int, string> $dependencies Dependency app IDs.
     *
     * @return array<string, array{installed: bool, enabled: bool, category: string}>
     *
     * @SuppressWarnings(PHPMD.StaticAccess) \OC_App::getAppInfo() is the only
     *  API exposing an on-disk app's category; no OCP equivalent exists.
     */
    private function resolveDependencyStatuses(IBootContext $context, array $dependencies): array
    {
        // Cached per app version, exactly as loadRoadmapFeatures() is. Without
        // this, buildAppStoreLookup() below iterates the whole Nextcloud
        // appstore catalogue (3.4 MB of apps.json on the 2026-07-30 dev
        // instance) every time this runs. It is free there only because
        // `has_internet_connection=false` makes AppFetcher return an empty set
        // — a latent cost, not an absent one.
        $container = $this->getContainer();
        $cache     = $container->get(ICacheFactory::class)->createLocal('pipelinq_deps');
        $cacheKey  = 'v'.((string) $container->get(IAppManager::class)->getAppVersion(self::APP_ID))
            .':'.md5(implode(',', $dependencies));

        $cached = $cache->get($cacheKey);
        if (is_array($cached) === true) {
            return $cached;
        }

        $appManager     = $container->get(IAppManager::class);
        $appStoreLookup = $this->buildAppStoreLookup(context: $context);

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

        // 5 minutes: an app being installed or enabled is a rare, operator-driven
        // event, and the page shows install hints — a stale hint for a few
        // minutes is harmless, walking the appstore per request is not.
        $cache->set($cacheKey, $dependencyStatus, 300);

        return $dependencyStatus;
    }//end resolveDependencyStatuses()

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
     * @spec openspec/specs/appointment-booking/spec.md
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
     * @spec openspec/specs/appointment-booking/spec.md
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
     * @spec openspec/specs/appointment-booking/spec.md
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
