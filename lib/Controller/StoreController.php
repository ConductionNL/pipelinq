<?php

/**
 * Pipelinq Store Controller
 *
 * HTTP surface for the commercial-configuration store:
 *   - GET  /api/store/items                  — search the remote registry.
 *   - POST /api/store/items/{slug}/install   — install one item locally.
 *   - GET  /api/store/settings               — read the registry connection.
 *   - PUT  /api/store/settings               — save the registry connection.
 *
 * ADR-080: DISCOVERY is OpenRegister's. This controller INJECTS AppHost's
 * GenericStoreService, which owns the SSRF-guarded, redirect-refusing,
 * token-private fetch. Pipelinq builds no registry URL and holds no HTTP
 * client.
 *
 * Composition, NOT inheritance, and deliberately so, for the reasons dossiq
 * records on its own StoreController: a cross-app `extends` is resolved by the
 * AUTOLOADER rather than the container, so an absent OpenRegister would 500
 * EVERY route in this app rather than only the store's, the unit suite stubs
 * OpenRegister rather than autoloading it, and phpstan refuses to ignore
 * "extends unknown class". An injected type-hint has none of those problems.
 *
 * INSTALL stays here, and only install. Writing a pipeline, a queue or a
 * product catalogue into this instance is pipelinq-specific and differently
 * authorized from cloning a virtual app. That is the ADR-080 Decision 3 seam.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Service\StoreDescriptor;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCA\Pipelinq\Service\Settings\SchemaSlugMap;
use OCA\Pipelinq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Store search, pipelinq's own install, and the registry connection settings.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Sits at 13, and every
 * dependency is load-bearing. dossiq's equivalent scores lower only because it
 * writes through a single `ConfiguredRegistryService` seam its admin settings
 * already had; pipelinq has no such seam, so the same one responsibility costs
 * two collaborators here (`ObjectServiceInterface` to write plus
 * `RegisterResolverService` to say where). Introducing a wrapper class purely
 * to move the count under the threshold would add an indirection with one
 * caller and hide, rather than remove, the coupling. Worth revisiting if a
 * second pipelinq surface needs the same configured-write seam, at which point
 * the wrapper would earn itself.
 *
 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
 */
class StoreController extends Controller {
	/**
	 * Kebab-case slug pattern for a remote store item.
	 */
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	/**
	 * Schema slugs an installed store item is allowed to write.
	 *
	 * 🔴 THIS LIST IS A SECURITY BOUNDARY, not a convenience.
	 *
	 * Every entry is CONFIGURATION: the shape of the commercial process,
	 * authored once by an administrator and shared between organisations.
	 * Absent from it, and deliberately, is every RECORD schema — `client`,
	 * `contact`, `lead`, `ticket`, `task`, `posTransaction`, `contract` and
	 * the rest. Those hold a customer's file and a company's money.
	 *
	 * Without the allowlist, "Install" is a remote write primitive: a registry,
	 * or anyone who can answer as one, could push objects straight into live
	 * commercial records through a button an administrator was told was safe.
	 *
	 * Widening it is a decision about what a stranger's registry may write into
	 * an organisation's data. Do not add a slug here to make an import work.
	 *
	 * Every entry is also a key of SchemaSlugMap::SLUG_TO_CONFIG_KEY, and
	 * phpstan proves it: an unmapped slug turns the lookup below into a
	 * nullable read and fails analysis.
	 *
	 * @var array<int, string>
	 */
	private const INSTALLABLE_SLUGS = [
		'pipeline',
		'queue',
		'skill',
		'product',
		'productCategory',
		'billingCategory',
		'posRole',
		'posTenderType',
		'receiptTemplate',
		'refundReason',
		'loyaltyProgramme',
		'pointsRule',
		'berichtenboxTemplate',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The current HTTP request.
	 * @param LoggerInterface $logger PSR logger.
	 * @param IUserSession $userSession Current Nextcloud user session.
	 * @param IAppConfig $appConfig App config store holding the registry connection and schema ids.
	 * @param GenericStoreService $storeService Engine-owned store discovery client.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 * @param RegisterResolverService $registerResolver Resolves pipelinq's register id.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $appConfig,
		private readonly GenericStoreService $storeService,
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterResolverService $registerResolver,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Pipelinq's store parameters.
	 *
	 * @return StoreDescriptor
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	private function descriptor(): StoreDescriptor {
		return new StoreDescriptor(
			appId: Application::APP_ID,
			schema: 'commercial-template',
			defaultRegister: 'pipelinq',
			cardFields: [
				'slug' => 'slug',
				'title' => 'title',
				'description' => 'description',
				'kind' => 'kind',
				'category' => 'category',
				'version' => 'version',
				'publisher' => 'publisher',
			]
		);
	}//end descriptor()

	/**
	 * Search the remote store.
	 *
	 * Login-required through an in-body guard, so an anonymous caller gets an
	 * explicit 401 rather than a login redirect. Returns normalised cards and a
	 * generic outcome, NEVER the registry URL or token.
	 *
	 * With no registry configured the engine returns `not_configured` without
	 * making a network call, which is what lets the page fall back to
	 * pipelinq's built-in templates (ADR-080 Decision 4).
	 *
	 * @return JSONResponse 200 with `{outcome, cards}`; 401 for anonymous.
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 *
	 * @no-admin-idor-exempt Addresses no pipelinq-owned object. The query and
	 *   kind filter are forwarded to an EXTERNAL registry, so there is nothing
	 *   of another tenant's to reach by guessing an identifier.
	 */
	#[NoAdminRequired]
	public function search(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(
				data: ['outcome' => 'unauthenticated', 'cards' => []],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$query = $this->request->getParam('q');
		if (is_string($query) === false) {
			$query = null;
		}

		$kind = $this->request->getParam('kind');
		if (is_string($kind) === false) {
			$kind = null;
		}

		try {
			$result = $this->storeService->search(
				descriptor: $this->descriptor(),
				query: $query,
				kind: $kind
			);
		} catch (Throwable $e) {
			// Detail to the log, generic outcome to the browser: a registry's
			// internals are not the caller's business.
			$this->logger->error('Pipelinq store: search failed: ' . $e->getMessage());
			return new JSONResponse(
				data: ['outcome' => GenericStoreService::OUTCOME_UNREACHABLE, 'cards' => []],
				statusCode: Http::STATUS_OK
			);
		}

		return new JSONResponse(
			data: ['outcome' => $result['outcome'], 'cards' => $result['cards']],
			statusCode: Http::STATUS_OK
		);
	}//end search()

	/**
	 * Install one store item into this instance.
	 *
	 * Administrative: the components written here are the shape of the work
	 * every salesperson and agent then operates against.
	 *
	 * The write runs as the calling administrator and NOT through
	 * `runAsSystem()`. That elevation is for operations whose inputs originate
	 * from code or the app's own seed data; a store payload is neither. It
	 * comes off the network.
	 *
	 * @param string $slug The remote item slug.
	 *
	 * @return JSONResponse 200 with a per-component report; 400 for a bad slug; 404 when unresolved.
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function install(string $slug): JSONResponse {
		if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'Malformed item slug.'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$item = $this->storeService->resolve(descriptor: $this->descriptor(), slug: $slug);
		} catch (Throwable $e) {
			$this->logger->error('Pipelinq store: resolve failed: ' . $e->getMessage());
			$item = null;
		}

		if ($item === null) {
			return new JSONResponse(
				data: ['success' => false, 'message' => 'The store item could not be resolved.'],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(
			data: $this->installComponents(components: $this->components(item: $item)),
			statusCode: Http::STATUS_OK
		);
	}//end install()

	/**
	 * Read the components a resolved store item declares.
	 *
	 * @param array<string, mixed> $item The resolved remote object.
	 *
	 * @return array<int, array<string, mixed>> The declared components, possibly empty.
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	private function components(array $item): array {
		$components = ($item['components'] ?? null);

		// A registry may ship the component list as a JSON string.
		if (is_string($components) === true) {
			$components = json_decode($components, true);
		}

		if (is_array($components) === false) {
			return [];
		}

		return array_values(array_filter($components, static fn ($c): bool => is_array($c) === true));
	}//end components()

	/**
	 * Write every allowed component, and report every refused one.
	 *
	 * A refusal does not abort the install: the remaining components still
	 * arrive, and the report names what did not. An item that is half
	 * configuration and half records is a registry's mistake, not a reason to
	 * deny an administrator the half they may have.
	 *
	 * @param array<int, array<string, mixed>> $components The declared components.
	 *
	 * @return array{success: bool, components: array<int, array<string, string>>}
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	private function installComponents(array $components): array {
		$report = [];
		$failed = false;
		$registerId = $this->registerResolver->resolve();

		foreach ($components as $component) {
			$slug = (string)($component['schema'] ?? '');
			$object = ($component['object'] ?? null);

			if (in_array($slug, self::INSTALLABLE_SLUGS, true) === false) {
				$failed = true;
				$report[] = [
					'schema' => $slug,
					'status' => 'refused',
					'message' => 'Only commercial configuration installs from the store; '
						. 'this component names a record schema.',
				];
				continue;
			}

			if (is_array($object) === false) {
				$failed = true;
				$report[] = [
					'schema' => $slug,
					'status' => 'refused',
					'message' => 'The component declares no object to install.',
				];
				continue;
			}

			// No null guard on the lookup, and none is needed: every entry in
			// INSTALLABLE_SLUGS is a key of SLUG_TO_CONFIG_KEY, and phpstan
			// PROVES it — a slug added to the allowlist with no mapping turns
			// this back into a nullable read and fails analysis.
			$schemaId = $this->appConfig->getValueString(
				Application::APP_ID,
				SchemaSlugMap::SLUG_TO_CONFIG_KEY[$slug],
				''
			);

			if ($registerId === '' || $schemaId === '') {
				$failed = true;
				$report[] = [
					'schema' => $slug,
					'status' => 'error',
					'message' => 'This instance has no register or schema configured for that component.',
				];
				continue;
			}

			try {
				$this->objectService->saveObject(
					$this->asNewObject(object: $object),
					[],
					$registerId,
					$schemaId,
					null
				);
				$report[] = ['schema' => $slug, 'status' => 'installed', 'message' => ''];
			} catch (Throwable $e) {
				$failed = true;
				$this->logger->error(
					'Pipelinq store: installing {schema} failed: {error}',
					['schema' => $slug, 'error' => $e->getMessage()]
				);
				$report[] = [
					'schema' => $slug,
					'status' => 'error',
					'message' => 'The component could not be written.',
				];
			}//end try
		}//end foreach

		return ['success' => ($failed === false && $report !== []), 'components' => $report];
	}//end installComponents()

	/**
	 * Strip every identity the remote payload carries, so an install CREATES.
	 *
	 * 🔴 WITHOUT THIS, "Install" IS AN OVERWRITE PRIMITIVE.
	 *
	 * OpenRegister's `saveObject()` resolves the object it is writing from the
	 * payload itself: `extractUuidAndNormalizeObject()` reads
	 * `$object['@self']['id'] ?? $object['id']` and treats a match as the uuid
	 * to UPDATE. So a store item whose component carried the uuid of this
	 * organisation's live pipeline would replace it rather than add one — and
	 * the write is PUT-semantic, so keys the payload omits are nulled, not left
	 * alone. The pipeline would not merely change, it would be gutted.
	 *
	 * The schema allowlist does not cover this. It governs WHICH schema a
	 * component may write, never whether the write creates or replaces, so an
	 * entirely legitimate `pipeline` component is the attack.
	 *
	 * Identity is not the registry's to supply. An installed item is a NEW
	 * local object, and if install ever needs to be idempotent it must key on
	 * something pipelinq controls rather than on a remote id.
	 *
	 * @param array<string, mixed> $object The component's object.
	 *
	 * @return array<string, mixed> The object with every identity key removed.
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	private function asNewObject(array $object): array {
		unset($object['id'], $object['uuid'], $object['@self']);

		return $object;
	}//end asNewObject()

	/**
	 * Read the registry connection.
	 *
	 * Reports WHETHER a token is set and never what it is. A settings form that
	 * round-trips a credential through the browser has published it to every
	 * extension the administrator happens to be running.
	 *
	 * @return JSONResponse 200 with the connection, minus the token value.
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function getSettings(): JSONResponse {
		return new JSONResponse(
			data: [
				'registryUrl' => $this->appConfig->getValueString(Application::APP_ID, 'registry_url', ''),
				'registryRegister' => $this->appConfig->getValueString(
					Application::APP_ID,
					'registry_register',
					'pipelinq'
				),
				'registryTokenSet' => trim(
					$this->appConfig->getValueString(Application::APP_ID, 'registry_token', '')
				) !== '',
			],
			statusCode: Http::STATUS_OK
		);
	}//end getSettings()

	/**
	 * Save the registry connection.
	 *
	 * An EMPTY token leaves the stored one untouched. The form cannot show the
	 * current token, so it posts an empty field whenever the administrator did
	 * not retype it — treating that as "clear the credential" would silently
	 * disconnect the store on every unrelated edit to the URL.
	 *
	 * @return JSONResponse 200 with the connection, minus the token value.
	 *
	 * @spec openspec/changes/pipelinq-store-surface/specs/pipelinq-store-surface/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function saveSettings(): JSONResponse {
		$url = $this->request->getParam('registryUrl');
		if (is_string($url) === true) {
			$this->appConfig->setValueString(Application::APP_ID, 'registry_url', trim($url));
		}

		$register = $this->request->getParam('registryRegister');
		if (is_string($register) === true && trim($register) !== '') {
			$this->appConfig->setValueString(Application::APP_ID, 'registry_register', trim($register));
		}

		$token = $this->request->getParam('registryToken');
		if (is_string($token) === true && trim($token) !== '') {
			$this->appConfig->setValueString(Application::APP_ID, 'registry_token', trim($token));
		}

		return $this->getSettings();
	}//end saveSettings()
}//end class
