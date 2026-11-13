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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\Pipelinq\Adapter\AdapterRegistrar;
use OCA\Pipelinq\Dashboard\DashboardRegistrar;
use OCA\Pipelinq\Flow\PipelinqFlowNodeListener;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ForecastServiceInterface;
use OCA\Pipelinq\Lifecycle\LifecycleRegistrar;
use OCA\Pipelinq\Listener\ListenerRegistrar;
use OCA\Pipelinq\Mcp\PipelinqScannableServices;
use OCA\Pipelinq\Service\RoadmapFeatureCatalog;
use OCA\Pipelinq\Service\ServiceRegistrar;
use OCA\Pipelinq\Service\VatRates;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\Comments\ICommentsManager;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use Psr\Container\ContainerInterface;

/**
 * Main application class for the Pipelinq client and request management app.
 *
 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-004
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Application extends App implements IBootstrap {
	public const APP_ID = AppIdentity::APP_ID;

	/**
	 * Constructor for the Application class.
	 *
	 * @return void
	 */
	public function __construct() {
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
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) OpenRegisterAutoloader::register()
	 *  is static because it runs at the composition root, before any container
	 *  exists to resolve an instance from. The alternative,
	 *  IAppManager::loadApp(), would mark OpenRegister loaded and boot it before
	 *  its own register() had run — the load-order hazard the prelude exists to
	 *  avoid.
	 */
	public function register(IRegistrationContext $context): void {

		// ADR-084: services type-hint OpenRegister's PUBLISHED interface, never its
		// concrete class, so this app's unit tests can mock a type they are able to
		// load. Nextcloud autowires concrete classes across apps but not interfaces,
		// so the binding has to be stated — and the composition root is where this
		// app says how it is wired.
		//
		// An ALIAS, not a factory: it resolves when something actually asks for the
		// interface, so an instance without OpenRegister fails at the route that
		// needed the data rather than at registration. Both names are strings and
		// neither triggers an autoload, which is what keeps ADR-083 rule 3's promise
		// that the start screen still boots.
		$context->registerServiceAlias(
			ObjectServiceInterface::class,
			'OCA\OpenRegister\Service\ObjectService'
		);

		// The register-slug resolver, bound the same way and for the same reason.
		// What it is for is written where it is used, in ConnectorSourceRegister;
		// what belongs here is why this binding works at all.
		//
		// Verified against this container, not assumed: OpenRegister registers the
		// resolver in its OWN container, so nothing of that registration reaches
		// here. What reaches here is the alias stated here plus autowiring of the
		// concrete class, whose only dependencies are `RegisterMapper` and
		// `LoggerInterface`. Both resolve from a leaf app's DIContainer, and the
		// interface then answers with a live resolution. The one thing lost is
		// OpenRegister's shared-instance registration: a leaf container autowires a
		// fresh resolver per injection point, so the request-scoped memo is per
		// consumer rather than per request. That costs one indexed read per
		// consumer and changes no answer.
		$context->registerServiceAlias(
			RegisterSlugResolverInterface::class,
			'OCA\OpenRegister\Service\RegisterSlugResolver'
		);

		// LOAD-ORDER HAZARD: OC_App::getEnabledApps() sort()s the app list and
		// Coordinator::registerApps() registers each app's autoloader then calls
		// register() one app at a time, so an app's register() runs before the
		// PSR-4 prefix of every alphabetically-LATER app exists. This app happens
		// to sort AFTER `openregister`, so the AppHost class_exists() probes below
		// answer TRUE today — by alphabet alone. Registering OpenRegister's prefix
		// ourselves makes that independent of the app id. See
		// OpenRegisterAutoloader: public API only (Nextcloud 35 removed the private
		// OC_App::registerAutoloading() this used to call), idempotent, and it
		// never throws — a false return means the degraded path below applies.
		OpenRegisterAutoloader::register();

		// AppHost (ADR-040): offload the mechanical observability + deep-link
		// ceremony to OpenRegister's shared engine. Scoped to the parity-safe,
		// fully-built halves — see registerAppHost() for why the Settings /
		// Preferences / repair plumbing stays bespoke.
		$this->registerAppHost(context: $context);

		// Notifier registration. A <notification> element in info.xml is never read by core
		// (and app-info.xsd rejects it); the IBootstrap registration below is the canonical path.
		$context->registerNotifierService(\OCA\Pipelinq\Notification\Notifier::class);
		$context->registerSetupCheck(\OCA\Pipelinq\SetupCheck\PortalServiceAccountCheck::class);
		$context->registerSetupCheck(\OCA\Pipelinq\SetupCheck\MessagingServiceAccountCheck::class);

		(new ListenerRegistrar())->register(context: $context);

		// Marketing: contribute the competitor-watch step to OpenRegister's
		// flow catalogue (ADR-065, ADR-094). The engine owns the schedule and
		// pipelinq owns the step, which is the only way a scheduled watch can
		// exist at all: the node registry has no outbound-HTTP node, so a flow
		// built from stock nodes cannot fetch a feed. Registering against a
		// class OpenRegister owns is safe with OpenRegister absent: the event
		// is simply never dispatched.
		$context->registerEventListener(
			event: \OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent::class,
			listener: PipelinqFlowNodeListener::class
		);

		(new DashboardRegistrar())->register(context: $context);

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

		(new ServiceRegistrar())->register(context: $context);
		(new LifecycleRegistrar())->register(context: $context);
		(new AdapterRegistrar())->register(context: $context);
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
	 * @spec openspec/changes/archive/2026-06-16-adopt-apphost/tasks.md#task-2.1
	 */
	private function registerAppHost(IRegistrationContext $context): void {
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
	 * Boot the application and register comment display name resolvers.
	 *
	 * @param IBootContext $context The boot context
	 *
	 * @return void
	 *
	 * @spec openspec/changes/avg-consume-or-workflow/specs/avg-or-seam-bindings/spec.md
	 */
	public function boot(IBootContext $context): void {
		$server = $context->getServerContainer();

		// Hand the Features & Roadmap surface its feature list, derived from
		// openspec/specs at runtime so it always reflects the current specs
		// (cached per app version; see RoadmapFeatureCatalog::load). Pull IInitialState
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
			$roadmap = $this->getContainer()->get(RoadmapFeatureCatalog::class);
			$initialState->provideInitialState('features_roadmap_features', $roadmap->load());
			$initialState->provideInitialState('features_roadmap_categories', $roadmap->categories());

			$dependencies = $this->readManifestDependencies();
			$dependencyStatus = $this->resolveDependencyStatuses(context: $context, dependencies: $dependencies);
			$initialState->provideInitialState('dependency_statuses', $dependencyStatus);

			// Reporting currency and open-pipeline target (0 = none) seed the
			// SPA's `config` state: `@config.currency`, `@config.pipelineTarget`.
			$appConfig = $this->getContainer()->get(IAppConfig::class);
			// `vat_rates` labels the product form's VAT class options with
			// the configured rate (pipelinq-forms-review).
			$initialState->provideInitialState(
				'config',
				[
					'currency' => $appConfig->getValueString(self::APP_ID, 'currency', 'EUR'),
					'vat_rates' => (new VatRates(appConfig: $appConfig))->rates(),
					'pipelineTarget' => $appConfig->getValueInt(self::APP_ID, ForecastServiceInterface::PIPELINE_TARGET_KEY, 0),
				]
			);
		} catch (\Exception $e) {
			// Initial state unavailable — Features tab will fall back to [].
		}//end try

		$this->bootNonPageSurfaces(server: $server);
	}//end boot()

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
	 * @spec openspec/specs/pipelinq-or-adoption/spec.md#requirement-initial-state-is-computed-only-for-requests-that-render-a-page
	 */
	private function requestRendersPage($server): bool {
		try {
			$request = $server->get(\OCP\IRequest::class);
			$path = (string)$request->getPathInfo();
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
	private function bootNonPageSurfaces($server): void {
		$this->registerCommentResolvers(server: $server);
		(new ServiceRegistrar())->boot(container: $this->getContainer());

	}//end bootNonPageSurfaces()

	/**
	 * Register comment display-name resolvers for pipelinq's comment types.
	 *
	 * @param mixed $server The server container.
	 *
	 * @return void
	 */
	private function registerCommentResolvers($server): void {
		try {
			$commentsManager = $server->get(ICommentsManager::class);
			foreach ([
				'pipelinq_client' => 'Client',
				'pipelinq_contact' => 'Contact',
				'pipelinq_lead' => 'Lead',
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
	private function readManifestDependencies(): array {
		$manifestPath = __DIR__ . '/../../src/manifest.json';
		if (is_file($manifestPath) === false) {
			return [];
		}

		$manifest = json_decode((string)file_get_contents($manifestPath), associative: true);
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
	private function buildAppStoreLookup(IBootContext $context): array {
		$server = $context->getServerContainer();
		$appStoreLookup = [];
		try {
			$appFetcher = $server->get(\OC\App\AppStore\Fetcher\AppFetcher::class);
			foreach ($appFetcher->get() as $storeApp) {
				if (empty($storeApp['id']) === false && empty($storeApp['categories']) === false) {
					$appStoreLookup[$storeApp['id']] = (array)$storeApp['categories'];
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
	 * @param IBootContext $context The boot context.
	 * @param array<int, string> $dependencies Dependency app IDs.
	 *
	 * @return array<string, array{installed: bool, enabled: bool, category: string}>
	 */
	private function resolveDependencyStatuses(IBootContext $context, array $dependencies): array {
		// Cached per app version, exactly as RoadmapFeatureCatalog::load() is. Without
		// this, buildAppStoreLookup() below iterates the whole Nextcloud
		// appstore catalogue (3.4 MB of apps.json on the 2026-07-30 dev
		// instance) every time this runs. It is free there only because
		// `has_internet_connection=false` makes AppFetcher return an empty set
		// — a latent cost, not an absent one.
		$container = $this->getContainer();
		$cache = $container->get(ICacheFactory::class)->createLocal('pipelinq_deps');
		$cacheKey = 'v' . ((string)$container->get(IAppManager::class)->getAppVersion(self::APP_ID))
			. ':' . md5(implode(',', $dependencies));

		$cached = $cache->get($cacheKey);
		if (is_array($cached) === true) {
			return $cached;
		}

		$appManager = $container->get(IAppManager::class);
		$appStoreLookup = $this->buildAppStoreLookup(context: $context);

		$dependencyStatus = [];
		foreach ($dependencies as $depId) {
			$onDisk = false;
			$category = 'organization';
			try {
				$appManager->getAppPath($depId);
				$onDisk = true;
				// Public IAppManager::getAppInfo(): the private \OC_App::getAppInfo()
				// used here before no longer exists, so the category always fell
				// through to the app-store lookup below.
				$appInfo = $appManager->getAppInfo($depId);
				if (is_array($appInfo) === true && empty($appInfo['category']) === false) {
					$category = (string)((array)$appInfo['category'])[0];
				}
			} catch (\Throwable) {
				if (empty($appStoreLookup[$depId][0]) === false) {
					$category = (string)$appStoreLookup[$depId][0];
				}
			}

			$dependencyStatus[$depId] = [
				'installed' => $onDisk,
				'enabled' => $appManager->isEnabledForUser($depId),
				'category' => $category,
			];
		}//end foreach

		// 5 minutes: an app being installed or enabled is a rare, operator-driven
		// event, and the page shows install hints — a stale hint for a few
		// minutes is harmless, walking the appstore per request is not.
		$cache->set($cacheKey, $dependencyStatus, 300);

		return $dependencyStatus;
	}//end resolveDependencyStatuses()

}//end class
