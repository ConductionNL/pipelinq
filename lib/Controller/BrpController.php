<?php

/**
 * Pipelinq BrpController.
 *
 * REST endpoints for BRP lookup, mutation-webhook ingest, BSN validation, geheimhouding
 * reveal, and local opt-out registration.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.1
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Listener\BrpMutationWebhookListener;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BsnAuditService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\HaalCentraalException;
use OCA\Pipelinq\Service\OptOutService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Controller for the BRP-lookup REST surface.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregates the full BRP surface (validate,
 *   lookup, webhook ingest, geheimhouding reveal, opt-out) behind one cohesive controller.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-002
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-003
 */
class BrpController extends Controller {
	/**
	 * Default role-based access list (group IDs).
	 *
	 * Admin always has access via the IGroupManager::isAdmin() check.
	 *
	 * @var array<int,string>
	 */
	private const DEFAULT_ALLOWED_GROUPS = [
		'behandelaar-burgerzaken',
		'behandelaar-avg',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Current user.
	 * @param IGroupManager $groupManager Group manager.
	 * @param IL10N $l10n i18n.
	 * @param IAppConfig $appConfig App config.
	 * @param BsnValidationService $validation 11-proef service.
	 * @param BrpCacheService $cacheService BRP cache.
	 * @param HaalCentraalClient $haalCentraal HaalCentraal client.
	 * @param BsnAuditService $audit Audit service.
	 * @param OptOutService $optOut Opt-out service.
	 * @param BrpMutationWebhookListener $webhookListener Webhook listener.
	 * @param ContainerInterface $container DI container.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Standard NC constructor injection; each
	 *   parameter is a distinct collaborator wired by the DI container.
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private IL10N $l10n,
		private IAppConfig $appConfig,
		private BsnValidationService $validation,
		private BrpCacheService $cacheService,
		private HaalCentraalClient $haalCentraal,
		private BsnAuditService $audit,
		private OptOutService $optOut,
		private BrpMutationWebhookListener $webhookListener,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Server-side BSN validation endpoint (mirror of the client-side 11-proef).
	 *
	 * This endpoint exists so admin-tooling / API clients without a browser can verify a
	 * BSN. The UI does NOT call this — it does the 11-proef locally (REQ-BSN-001-04). All
	 * responses use the masked BSN; the raw BSN never echoes back.
	 *
	 * @return JSONResponse The validation result.
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-001
	 */
	#[NoAdminRequired]
	public function validate(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$raw = (string)$this->request->getParam('bsn', '');
		$result = $this->validation->validate($raw);
		// Never echo back the raw BSN — only the masked variant.
		return new JSONResponse(
			[
				'isFormalValid' => $result['isFormalValid'],
				'errorCode' => $result['errorCode'],
				'errorMessage' => $result['errorMessage'],
				'maskedBsn' => $result['maskedBsn'],
			],
			Http::STATUS_OK
		);
	}//end validate()

	/**
	 * POST /api/brp/lookup — execute a BRP lookup with doelbinding.
	 *
	 * Body params:
	 *   - bsn:           string (required, 9 digits, must pass 11-proef)
	 *   - verzoekreden:  string (required, non-empty)
	 *   - doelbinding:   string (required, non-empty)
	 *   - grondslag:     string (required, non-empty)
	 *   - gekoppeldVerzoek: string (optional UUID)
	 *   - gekoppeldContact: string (optional UUID)
	 *   - vogScreening:  bool   (optional, default false)
	 *
	 * @return JSONResponse JSON shape: { persoon?, lookupVerzoek, responseInCache, errorMessage? }.
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-002-01
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-002-02
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-003-03
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Single auth-guarded BRP flow: doelbinding,
	 *   permission, 11-proef, cache/remote, audit-persist — sequential guard clauses that must
	 *   stay one atomic transaction; splitting would fragment the audit trail.
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Same rationale; independent guards, not nesting.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Same rationale; the audit-record payload is
	 *   assembled inline so every branch contributes to one recordLookup() call.
	 * @SuppressWarnings(PHPMD.StaticAccess)          BsnValidationService::hash() is a pure static
	 *   hashing utility (no state), used identically across the codebase.
	 */
	#[NoAdminRequired]
	public function lookup(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$actor = $user->getUID();

		$rawBsn = (string)$this->request->getParam('bsn', '');
		$verzoekreden = trim((string)$this->request->getParam('verzoekreden', ''));
		$doelbinding = trim((string)$this->request->getParam('doelbinding', ''));
		$basis = trim((string)$this->request->getParam('basis', ''));
		$requestId = (string)$this->request->getParam('linkedRequest', '');
		$contactId = (string)$this->request->getParam('gekoppeldContact', '');
		$vogScreening = (bool)$this->request->getParam('vogScreening', false);

		// 1) Doelbinding presence (REQ-BSN-002-01).
		if ($verzoekreden === '' || $doelbinding === '') {
			return new JSONResponse(
				[
					'errorCode' => 'doelbinding-missing',
					'errorMessage' => $this->l10n->t('Verzoekreden en doelbinding zijn verplicht.'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($basis === '') {
			$basis = $doelbinding;
		}

		// 2) Permission check (REQ-BSN-005-03).
		$actorRole = $this->resolveActorRole(actor: $actor);
		if ($actorRole === null) {
			$linkedRequest = null;
			if ($requestId !== '') {
				$linkedRequest = $requestId;
			}

			$this->audit->recordLookup(
				actor: $actor,
				rawBsn: $rawBsn,
				verzoekreden: $verzoekreden,
				doelbinding: $doelbinding,
				uitkomst: 'geweigerd-onbevoegd',
				action: 'brp-lookup-geweigerd',
				responseCode: 403,
				linkedRequest: $linkedRequest,
				vogScreening: $vogScreening,
			);
			return new JSONResponse(
				[
					'errorCode' => 'unauthorized',
					'errorMessage' => $this->l10n->t('U bent niet bevoegd voor deze lookup.'),
				],
				Http::STATUS_FORBIDDEN
			);
		}//end if

		// 3) BSN must pass 11-proef before any external call (defense-in-depth — the UI
		// already validates, but a direct REST caller could skip the UI).
		$validation = $this->validation->validate($rawBsn);
		if ($validation['isFormalValid'] === false) {
			return new JSONResponse(
				[
					'errorCode' => 'invalid-bsn',
					'errorMessage' => $validation['errorMessage'] ?? $this->l10n->t('Ongeldig BSN.'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

		// 4) Cache lookup.
		$cached = $this->cacheService->get($rawBsn);
		$person = null;
		$uitkomst = 'fout';
		$responseCode = 0;
		$correlationId = null;
		$responseInCache = false;
		$responseDurationMs = null;

		if ($cached !== null) {
			$person = $cached;
			$responseInCache = true;
			$uitkomst = 'geslaagd';
			$responseCode = 200;
		}

		if ($cached === null) {
			try {
				$requestRef = null;
				if ($requestId !== '') {
					$requestRef = $requestId;
				}

				$remote = $this->haalCentraal->lookupPersoon($rawBsn, $requestRef);
				if ($remote === null) {
					$uitkomst = 'niet-gevonden';
					$responseCode = 404;
				}

				if ($remote !== null) {
					$correlationId = $remote['_correlationId'] ?? null;
					$responseDurationMs = $remote['_responseDurationMs'] ?? null;
					$responseCode = (int)($remote['_responseStatus'] ?? 200);
					unset($remote['_correlationId'], $remote['_responseDurationMs'], $remote['_responseStatus']);

					$remote['bsnHash'] = BsnValidationService::hash($rawBsn);
					$remote['lookupRequestId'] = '';
					// Back-filled after verzoek save below.
					$remote['gekoppeldContact'] = $contactId;
					$person = $this->cacheService->set($remote);
					$uitkomst = 'geslaagd';

					// Record opt-out side-effect.
					$this->optOut->recordFromBrpResponse(
						$rawBsn,
						(string)($person['indicationSecret'] ?? '0')
					);
				}//end if
			} catch (HaalCentraalException $e) {
				$uitkomst = 'fout';
				if ($e->getStatusCode() === 0) {
					$uitkomst = 'timeout';
				}

				$responseCode = $e->getStatusCode();
				$correlationId = $e->getCorrelationId();
			} catch (Throwable $e) {
				$this->logger->error(
					'Unexpected BRP lookup failure',
					['error' => $e->getMessage()]
				);
				$uitkomst = 'fout';
				$responseCode = 500;
			}//end try
		}//end if

		// 5) Persist lookup verzoek (audit-trail).
		$gekoppeldRequestRef = null;
		if ($requestId !== '') {
			$gekoppeldRequestRef = $requestId;
		}

		$gekoppeldContactRef = null;
		if ($contactId !== '') {
			$gekoppeldContactRef = $contactId;
		}

		$cacheExpiresOn = null;
		if ($person !== null) {
			$cacheExpiresOn = ($person['retentionTo'] ?? null);
		}

		$request = [
			'bsnHash' => BsnValidationService::hash($rawBsn),
			'verzoekreden' => $verzoekreden,
			'doelbinding' => $doelbinding,
			'basis' => $basis,
			'requestedBy' => $actor,
			'requestedOnBehalfOf' => $actorRole,
			'requestMoment' => $now->format(DATE_ATOM),
			'linkedRequest' => $gekoppeldRequestRef,
			'gekoppeldContact' => $gekoppeldContactRef,
			'responseStatus' => $uitkomst,
			'responseMoment' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
			'responseDurationMs' => $responseDurationMs,
			'haalcentraalCorrelationId' => $correlationId,
			'responseContainsSecrecy' => $person !== null && (string)($person['indicationSecret'] ?? '0') === '1',
			'responseInCache' => $responseInCache,
			'cacheExpiresOn' => $cacheExpiresOn,
		];
		$request = array_filter($request, static fn ($v) => $v !== null);
		$requestSaved = $this->saveLookupRequest(request: $request);
		$verzoekUuid = (string)($requestSaved['@self']['id'] ?? $requestSaved['id'] ?? '');

		// 5b) Back-fill lookupVerzoekId on the freshly-saved persoon.
		if ($person !== null && $responseInCache === false && $verzoekUuid !== '') {
			$person['lookupRequestId'] = $verzoekUuid;
			$this->saveBrpPerson(person: $person);
		}

		// 6) Audit-record (always).
		$this->audit->recordLookup(
			actor: $actor,
			rawBsn: $rawBsn,
			verzoekreden: $verzoekreden,
			doelbinding: $doelbinding,
			uitkomst: $uitkomst,
			responseCode: $responseCode,
			haalcentraalCorrelationId: $correlationId,
			linkedRequest: $gekoppeldRequestRef,
			actorRole: $actorRole,
			vogScreening: $vogScreening,
		);

		// 7) Update contact (REQ-BSN-008).
		if ($person !== null && $contactId !== '') {
			$this->stampContactWithVerifiedBsn(
				contactId: $contactId,
				brpPersonId: (string)($person['@self']['id'] ?? $person['id'] ?? ''),
				secrecy: ((string)($person['indicationSecret'] ?? '0')) === '1',
			);
		}

		// 8) Response shaping. Strip raw BSN-like fields; never echo BSN.
		if ($person !== null) {
			unset($person['bsn']);
		}

		if ($uitkomst === 'niet-gevonden') {
			return new JSONResponse(
				[
					'errorCode' => 'not-found',
					'errorMessage' => $this->l10n->t('BSN niet aangetroffen in BRP — controleer invoer.'),
					'responseInCache' => false,
					'lookupRequestId' => $verzoekUuid,
				],
				Http::STATUS_NOT_FOUND
			);
		}

		if ($uitkomst === 'fout' || $uitkomst === 'timeout') {
			return new JSONResponse(
				[
					'errorCode' => 'brp-unavailable',
					'errorMessage' => $this->l10n->t('BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.'),
					'responseInCache' => false,
					'lookupRequestId' => $verzoekUuid,
				],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse(
			[
				'persoon' => $person,
				'responseInCache' => $responseInCache,
				'lookupRequestId' => $verzoekUuid,
			],
			Http::STATUS_OK
		);
	}//end lookup()

	/**
	 * POST /api/brp/contact/{id}/reveal-address — reveal a geheimhouding adres
	 * with an extra audit entry.
	 *
	 * @param string $id Contact UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-006-01
	 */
	#[NoAdminRequired]
	public function revealAddress(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$actor = $user->getUID();
		if ($this->resolveActorRole(actor: $actor) === null) {
			return new JSONResponse(
				[
					'errorCode' => 'unauthorized',
					'errorMessage' => $this->l10n->t('U bent niet bevoegd voor deze actie.'),
				],
				Http::STATUS_FORBIDDEN
			);
		}

		// The actual address surfacing happens on the frontend (it already has the persoon
		// payload from the lookup). This endpoint only writes the audit entry.
		$person = $this->findLatestPersonForContact(contactId: $id);
		if ($person === null) {
			return new JSONResponse(
				[
					'errorCode' => 'not-found',
					'errorMessage' => $this->l10n->t('Geen BRP-persoon gekoppeld aan dit contact.'),
				],
				Http::STATUS_NOT_FOUND
			);
		}

		// Reconstructing the raw BSN is impossible; use empty raw for audit (hash already on persoon).
		$this->audit->recordLookup(
			actor: $actor,
			rawBsn: '',
			verzoekreden: 'Adres onthuld op behandelaarsverantwoording',
			doelbinding: 'Wet BRP art. 3.3 (uitzondering geheimhouding)',
			uitkomst: 'adres-onthuld',
			action: 'brp-adres-onthuld',
			responseCode: 200,
			linkedRequest: null,
			actorRole: $this->resolveActorRole(actor: $actor),
		);

		return new JSONResponse(
			[
				'residence' => $person['residence'] ?? null,
			],
			Http::STATUS_OK
		);
	}//end revealAddress()

	/**
	 * POST /api/brp/opt-out — manually register a local opt-out flag.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-006-02
	 */
	#[NoAdminRequired]
	public function optOutCreate(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$actor = $user->getUID();
		if ($this->resolveActorRole(actor: $actor) === null) {
			return new JSONResponse(
				[
					'errorCode' => 'unauthorized',
					'errorMessage' => $this->l10n->t('U bent niet bevoegd voor deze actie.'),
				],
				Http::STATUS_FORBIDDEN
			);
		}

		$rawBsn = (string)$this->request->getParam('bsn', '');
		$note = $this->request->getParam('note');
		$validation = $this->validation->validate($rawBsn);
		if ($validation['isFormalValid'] === false) {
			return new JSONResponse(
				[
					'errorCode' => 'invalid-bsn',
					'errorMessage' => $validation['errorMessage'] ?? $this->l10n->t('Ongeldig BSN.'),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		$noteValue = null;
		if (is_string($note) === true) {
			$noteValue = $note;
		}

		$recorded = $this->optOut->recordLocalOptOut($rawBsn, $actor, $noteValue);
		if ($recorded === false) {
			return new JSONResponse(
				[
					'errorCode' => 'internal',
				],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(['result' => 'ok'], Http::STATUS_CREATED);
	}//end optOutCreate()

	/**
	 * POST /api/brp/mutations — external HaalCentraal mutation webhook.
	 *
	 * Public (no NC session) — but HMAC-SHA256-verified inside the listener.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-004-03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function mutationWebhook(): JSONResponse {
		$rawBody = (string)file_get_contents('php://input');
		$signature = (string)$this->request->getHeader('X-Signature');
		if ($signature === '') {
			$signature = (string)$this->request->getHeader('x-signature');
		}

		// Always respond 200 OK with a structured result field — HaalCentraal
		// expects to ack the delivery regardless of inner outcome. The HMAC
		// verification + bsn-shape check happen inside the listener
		// (BrpMutationWebhookListener::handle) which logs + records an audit
		// line on every rejection; the HTTP layer is just "we received it".
		$outcome = $this->webhookListener->handle($rawBody, $signature);
		return new JSONResponse(
			[
				'result' => $outcome['result'],
				'invalidated' => $outcome['invalidated'],
			],
			Http::STATUS_OK
		);
	}//end mutationWebhook()

	/**
	 * GET /api/brp/monitor — return the latest BrpMonitorJob report + cert health.
	 *
	 * Admin-only: regular users would learn sensitive operational metrics.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-010
	 */
	#[AuthorizedAdminSetting(settings: \OCA\Pipelinq\Settings\AdminSettings::class)]
	public function monitor(): JSONResponse {
		// AuthorizedAdminSetting middleware already enforces admin membership;
		// we still load the user for logging context.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$reportRaw = $this->appConfig->getValueString(Application::APP_ID, 'brp.monitor_report', '');
		$certRaw = $this->appConfig->getValueString(Application::APP_ID, 'brp.cert_health', '');

		$report = null;
		if ($reportRaw !== '') {
			$report = json_decode($reportRaw, true);
		}

		$cert = null;
		if ($certRaw !== '') {
			$cert = json_decode($certRaw, true);
		}

		$reportPayload = null;
		if (is_array($report) === true) {
			$reportPayload = $report;
		}

		$certPayload = null;
		if (is_array($cert) === true) {
			$certPayload = $cert;
		}

		return new JSONResponse(
			[
				'report' => $reportPayload,
				'cert' => $certPayload,
			],
			Http::STATUS_OK
		);
	}//end monitor()

	/**
	 * Determine the actor's role for BRP lookups.
	 *
	 * Admins are always authorised. Other users must be a member of an allowed group
	 * (configurable via `brp.allowed_groups` app-config).
	 *
	 * @param string $actor User UID.
	 *
	 * @return string|null Role label or null when unauthorised.
	 */
	private function resolveActorRole(string $actor): ?string {
		if ($this->groupManager->isAdmin($actor) === true) {
			return 'beheerder';
		}

		$configured = $this->appConfig->getValueString(
			Application::APP_ID,
			'brp.allowed_groups',
			''
		);
		$allowed = self::DEFAULT_ALLOWED_GROUPS;
		if ($configured !== '') {
			$allowed = array_filter(array_map('trim', explode(',', $configured)));
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		foreach ($allowed as $groupId) {
			$group = $this->groupManager->get($groupId);
			if ($group !== null && $group->inGroup($user) === true) {
				return $groupId;
			}
		}

		return null;
	}//end resolveActorRol()

	/**
	 * Persist a brpLookupVerzoek record.
	 *
	 * @param array<string,mixed> $request The lookup-verzoek payload to persist.
	 *
	 * @return array<string,mixed>
	 */
	private function saveLookupRequest(array $request): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'brpLookupVerzoek_schema', '');
		if ($register === '' || $schema === '') {
			return $request;
		}

		try {
			$saved = $this->getObjectService()->saveObject(
				object: $request,
				extend: [],
				register: $register,
				schema: $schema,
			);
			if (is_array($saved) === true) {
				return $saved;
			}

			if (method_exists($saved, 'jsonSerialize') === true) {
				return (array)$saved->jsonSerialize();
			}

			return $request;
		} catch (Throwable $e) {
			$this->logger->error('lookupVerzoek save failed', ['error' => $e->getMessage()]);
			return $request;
		}
	}//end saveLookupVerzoek()

	/**
	 * Persist a brpPersoon record.
	 *
	 * @param array<string,mixed> $person The BRP-persoon payload to persist.
	 *
	 * @return array<string,mixed>
	 */
	private function saveBrpPerson(array $person): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'brpPersoon_schema', '');
		$uuid = (string)($person['@self']['id'] ?? $person['id'] ?? '');
		if ($register === '' || $schema === '') {
			return $person;
		}

		$uuidRef = null;
		if ($uuid !== '') {
			$uuidRef = $uuid;
		}

		try {
			$saved = $this->getObjectService()->saveObject(
				object: $person,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $uuidRef,
			);
			if (is_array($saved) === true) {
				return $saved;
			}

			if (method_exists($saved, 'jsonSerialize') === true) {
				return (array)$saved->jsonSerialize();
			}

			return $person;
		} catch (Throwable $e) {
			$this->logger->error('brpPersoon save failed', ['error' => $e->getMessage()]);
			return $person;
		}//end try
	}//end saveBrpPersoon()

	/**
	 * Stamp the Pipelinq contact with verifiedBSN/brpPersoonId/geheimhouding.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $brpPersonId BrpPersoon UUID.
	 * @param bool $secrecy True when indicatieGeheim=1.
	 *
	 * @return void
	 */
	private function stampContactWithVerifiedBsn(string $contactId, string $brpPersonId, bool $secrecy): void {
		if ($contactId === '' || $brpPersonId === '') {
			return;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'contact_schema', '');
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$existing = $this->getObjectService()->find(
				id: $contactId,
				register: $register,
				schema: $schema,
			);
			$existingArr = [];
			if (is_array($existing) === true) {
				$existingArr = $existing;
			} elseif (method_exists($existing, 'jsonSerialize') === true) {
				$existingArr = (array)$existing->jsonSerialize();
			}

			if (empty($existingArr) === true) {
				return;
			}

			$existingArr['verifiedBSN'] = true;
			$existingArr['brpPersonId'] = $brpPersonId;
			$existingArr['secrecy'] = $secrecy;
			$this->getObjectService()->saveObject(
				object: $existingArr,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $contactId,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'contact stamp with verifiedBSN failed',
				['contactId' => $contactId, 'error' => $e->getMessage()]
			);
		}//end try
	}//end stampContactWithVerifiedBsn()

	/**
	 * Find the most recent BrpPersoon linked to a contact (or null).
	 *
	 * @param string $contactId Contact UUID.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findLatestPersonForContact(string $contactId): ?array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'brpPersoon_schema', '');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$results = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'gekoppeldContact' => $contactId,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
			$latest = null;
			foreach (($results ?? []) as $object) {
				$arr = [];
				if (is_array($object) === true) {
					$arr = $object;
				} elseif (method_exists($object, 'jsonSerialize') === true) {
					$arr = (array)$object->jsonSerialize();
				}

				if ($latest === null || (string)($arr['fetchedOn'] ?? '') > (string)($latest['fetchedOn'] ?? '')) {
					$latest = $arr;
				}
			}

			return $latest;
		} catch (Throwable $e) {
			$this->logger->error('findLatestPersoonForContact failed', ['error' => $e->getMessage()]);
			return null;
		}//end try
	}//end findLatestPersoonForContact()

	/**
	 * Lazy OR ObjectService.
	 *
	 * @return object
	 *
	 * @throws \RuntimeException When OR is unavailable.
	 */
	private function getObjectService(): object {
		try {
			return $this->objectService;
		} catch (Throwable $e) {
			throw new RuntimeException('OpenRegister service is not available.');
		}
	}//end getObjectService()
}//end class
