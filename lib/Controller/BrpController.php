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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Listener\BrpMutationWebhookListener;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BsnAudit\LookupAudit;
use OCA\Pipelinq\Service\BsnAudit\LookupReason;
use OCA\Pipelinq\Service\BsnAudit\LookupResult;
use OCA\Pipelinq\Service\BsnAudit\LookupSubject;
use OCA\Pipelinq\Service\BsnAuditService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\HaalCentraalClient;
use OCA\Pipelinq\Service\HaalCentraalException;
use OCA\Pipelinq\Service\OptOutService;
use OCA\Pipelinq\Service\PersonLookup\LookupLinks;
use OCA\Pipelinq\Service\PersonLookup\LookupQuery;
use OCA\Pipelinq\Service\PersonLookup\LookupRun;
use OCA\Pipelinq\Service\PersonLookup\PersonFetch;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
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
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Controller for the BRP-lookup REST surface.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Aggregates the full BRP surface (validate,
 *   lookup, webhook ingest, geheimhouding reveal, opt-out) behind one cohesive controller.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
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
	 * @param ObjectOwnerAccessPolicy $accessPolicy Per-object owner access policy.
	 * @param IGroupManager $groupManager Group manager.
	 * @param IL10N $l10n i18n.
	 * @param IAppConfig $appConfig App config.
	 * @param BsnValidationService $validation 11-proef service.
	 * @param BrpCacheService $cacheService BRP cache.
	 * @param HaalCentraalClient $haalCentraal HaalCentraal client.
	 * @param BsnAuditService $audit Audit service.
	 * @param OptOutService $optOut Opt-out service.
	 * @param BrpMutationWebhookListener $webhookListener Webhook listener.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Standard NC constructor injection; each
	 *   parameter is a distinct collaborator wired by the DI container.
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
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
	 * POST /api/brp/lookup — execute a BRP lookup with doelbinding.
	 *
	 * Body params:
	 *   - bsn:           string (required, 9 digits, must pass 11-proef)
	 *   - verzoekreden:  string (required, non-empty)
	 *   - purposeBinding:   string (required, non-empty)
	 *   - grondslag:     string (required, non-empty)
	 *   - gekoppeldVerzoek: string (optional UUID)
	 *   - gekoppeldContact: string (optional UUID)
	 *   - vogScreening:  bool   (optional, default false)
	 *
	 * @return JSONResponse JSON shape: { persoon?, lookupVerzoek, responseInCache, errorMessage? }.
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-02
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003-03
	 *
	 */
	#[NoAdminRequired]
	public function lookup(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		$actor = $user->getUID();

		// Resolving a BSN against the BRP is a CRM capability, not an
		// any-authenticated-user one: this guard decides whether this caller
		// may look anyone up at all, before the role check below.
		// Every refused lookup is audited (who, why, when), with the BSN hashed.
		$query = $this->lookupQuery();
		if ($this->accessPolicy->isPrivileged(uid: $actor) === false) {
			$this->auditRefusal(actor: $actor, query: $query, outcome: 'refused-unauthorised', responseCode: Http::STATUS_FORBIDDEN);
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		// 1) Doelbinding presence (REQ-BSN-002-01).
		if ($query->hasPurpose() === false) {
			$this->auditRefusal(actor: $actor, query: $query, outcome: 'refused-missing-purpose', responseCode: Http::STATUS_BAD_REQUEST);
			return $this->badLookupRequest(errorCode: 'doelbinding-missing', message: $this->l10n->t('Verzoekreden en doelbinding zijn verplicht.'));
		}

		// 2) Permission check (REQ-BSN-005-03).
		$actorRole = $this->resolveActorRole(actor: $actor);
		if ($actorRole === null) {
			return $this->refuseLookup(actor: $actor, query: $query);
		}

		// 3) BSN must pass 11-proef before any external call (defense-in-depth — the UI
		// already validates, but a direct REST caller could skip the UI).
		$validation = $this->validation->validate($query->rawBsn);
		if ($validation['isFormalValid'] === false) {
			$this->auditRefusal(actor: $actor, query: $query, outcome: 'refused-invalid-bsn', responseCode: Http::STATUS_BAD_REQUEST);
			return $this->badLookupRequest(errorCode: 'invalid-bsn', message: $validation['errorMessage'] ?? $this->l10n->t('Ongeldig BSN.'));
		}

		$requestedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$run = new LookupRun(query: $query, actorRole: $actorRole, requestedAt: $requestedAt, fetch: $this->fetchPerson(query: $query));
		return $this->completeLookup(actor: $actor, run: $run);
	}//end lookup()

	/**
	 * Read the lookup from the request body.
	 *
	 * @return LookupQuery
	 */
	private function lookupQuery(): LookupQuery {
		$purposeBinding = trim((string)$this->request->getParam('purposeBinding', ''));
		$basis = trim((string)$this->request->getParam('basis', ''));
		if ($basis === '') {
			$basis = $purposeBinding;
		}

		$linkedRequest = (string)$this->request->getParam('linkedRequest', '');
		if ($linkedRequest === '') {
			$linkedRequest = null;
		}

		return new LookupQuery(
			rawBsn: (string)$this->request->getParam('bsn', ''),
			reason: new LookupReason(
				verzoekreden: trim((string)$this->request->getParam('verzoekreden', '')),
				purposeBinding: $purposeBinding,
				linkedRequest: $linkedRequest,
				vogScreening: (bool)$this->request->getParam('vogScreening', false),
			),
			basis: $basis,
			links: new LookupLinks(
				contactId: (string)$this->request->getParam('gekoppeldContact', ''),
				clientId: (string)$this->request->getParam('verifiedClient', ''),
				identityCheck: trim((string)$this->request->getParam('identityCheck', '')),
			),
		);
	}//end lookupQuery()

	/**
	 * Refuse a lookup by a user without a BRP role, and audit the refusal.
	 *
	 * @param string      $actor The session user.
	 * @param LookupQuery $query The lookup.
	 *
	 * @return JSONResponse
	 */
	private function refuseLookup(string $actor, LookupQuery $query): JSONResponse {
		$this->auditRefusal(actor: $actor, query: $query, outcome: 'refused-unauthorised', responseCode: Http::STATUS_FORBIDDEN);
		return new JSONResponse(
			[
				'errorCode' => 'unauthorized',
				'errorMessage' => $this->l10n->t('U bent niet bevoegd voor deze lookup.'),
			],
			Http::STATUS_FORBIDDEN
		);
	}//end refuseLookup()

	/**
	 * Write the audit record of a refused lookup.
	 *
	 * @param string      $actor        The session user.
	 * @param LookupQuery $query        The lookup.
	 * @param string      $outcome      Why the lookup was refused.
	 * @param int         $responseCode The status the caller is answered with.
	 *
	 * @return void
	 */
	private function auditRefusal(string $actor, LookupQuery $query, string $outcome, int $responseCode): void {
		$this->audit->recordLookup(
			actor: $actor,
			lookup: new LookupAudit(
				subject: new LookupSubject(rawBsn: $query->rawBsn),
				reason: $query->reason,
				result: new LookupResult(outcome: $outcome, action: 'brp-lookup-refused', responseCode: $responseCode),
			),
		);
	}//end auditRefusal()

	/**
	 * A 400 answer to a lookup.
	 *
	 * @param string $errorCode The error code.
	 * @param string $message   The translated message.
	 *
	 * @return JSONResponse
	 */
	private function badLookupRequest(string $errorCode, string $message): JSONResponse {
		return new JSONResponse(['errorCode' => $errorCode, 'errorMessage' => $message], Http::STATUS_BAD_REQUEST);
	}//end badLookupRequest()

	/**
	 * Persist, audit and answer an authorised lookup whose person was fetched.
	 *
	 * @param string    $actor The session user.
	 * @param LookupRun $run   The lookup.
	 *
	 * @return JSONResponse
	 */
	private function completeLookup(string $actor, LookupRun $run): JSONResponse {
		$query = $run->query;

		// 4) Persist the lookup verzoek (audit trail) and back-fill its id on a fresh person.
		$requestSaved = $this->saveLookupRequest(request: $this->lookupRequestRecord(actor: $actor, run: $run));
		$requestUuid = (string)($requestSaved['@self']['id'] ?? $requestSaved['id'] ?? '');
		$fetch = $this->backfillLookupRequest(fetch: $run->fetch, requestUuid: $requestUuid);

		// 5) Audit record (always).
		$this->audit->recordLookup(
			actor: $actor,
			lookup: new LookupAudit(
				subject: new LookupSubject(rawBsn: $query->rawBsn),
				reason: $query->reason,
				result: $fetch->result,
				actorRole: $run->actorRole,
			),
		);

		// 6) Stamp the contact (REQ-BSN-008) and the client whose identity was verified.
		$this->stampVerifiedLinks(run: $run);

		return $this->lookupResponse(fetch: $fetch, requestUuid: $requestUuid);
	}//end completeLookup()

	/**
	 * The person for a lookup: from the cache, else from the BRP.
	 *
	 * @param LookupQuery $query The lookup.
	 *
	 * @return PersonFetch
	 */
	private function fetchPerson(LookupQuery $query): PersonFetch {
		$cached = $this->cacheService->get($query->rawBsn);
		if ($cached !== null) {
			return new PersonFetch(person: $cached, result: new LookupResult(outcome: 'succeeded', responseCode: 200), fromCache: true, durationMs: null);
		}

		return $this->fetchRemotePerson(query: $query);
	}//end fetchPerson()

	/**
	 * Fetch a person from the BRP, store it in the cache and record its opt-out flag.
	 *
	 * A failure after the BRP answered keeps what was already known: the
	 * correlation id, the duration and, once cached, the person.
	 *
	 * @param LookupQuery $query The lookup.
	 *
	 * @return PersonFetch
	 */
	private function fetchRemotePerson(LookupQuery $query): PersonFetch {
		$fetch = new PersonFetch(person: null, result: new LookupResult(outcome: 'error', responseCode: 0), fromCache: false, durationMs: null);
		try {
			$remote = $this->haalCentraal->lookupPersoon($query->rawBsn, $query->reason->linkedRequest);
			if ($remote === null) {
				return new PersonFetch(person: null, result: new LookupResult(outcome: 'not-found', responseCode: 404), fromCache: false, durationMs: null);
			}

			$fetch = $this->remoteMeta(remote: $remote);
			$fetch = $fetch->succeeded(person: $this->cacheService->set($this->remotePerson(remote: $remote, query: $query)));

			$this->optOut->recordFromBrpResponse(
				$query->rawBsn,
				(string)($fetch->person['indicationSecret'] ?? '0')
			);
			return $fetch;
		} catch (HaalCentraalException $e) {
			$outcome = 'error';
			if ($e->getStatusCode() === 0) {
				$outcome = 'timeout';
			}

			return $fetch->withResult(result: new LookupResult(outcome: $outcome, responseCode: $e->getStatusCode(), correlationId: $e->getCorrelationId()));
		} catch (Throwable $e) {
			$this->logger->error(
				'Unexpected BRP lookup failure',
				['error' => $e->getMessage()]
			);
			return $fetch->withResult(result: new LookupResult(outcome: 'error', responseCode: 500, correlationId: $fetch->result->correlationId));
		}//end try
	}//end fetchRemotePerson()

	/**
	 * The meta fields of a BRP answer, before its person is stored.
	 *
	 * @param array<string, mixed> $remote The BRP answer with its `_` meta keys.
	 *
	 * @return PersonFetch
	 */
	private function remoteMeta(array $remote): PersonFetch {
		return new PersonFetch(
			person: null,
			result: new LookupResult(
				outcome: 'error',
				responseCode: (int)($remote['_responseStatus'] ?? 200),
				correlationId: ($remote['_correlationId'] ?? null),
			),
			fromCache: false,
			durationMs: ($remote['_responseDurationMs'] ?? null),
		);
	}//end remoteMeta()

	/**
	 * A BRP answer as a person to cache: meta keys removed, BSN hashed, links set.
	 *
	 * @param array<string, mixed> $remote The BRP answer.
	 * @param LookupQuery          $query  The lookup.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::hash() is a pure static hashing utility.
	 */
	private function remotePerson(array $remote, LookupQuery $query): array {
		unset($remote['_correlationId'], $remote['_responseDurationMs'], $remote['_responseStatus']);

		$remote['bsnHash'] = BsnValidationService::hash($query->rawBsn);
		// Back-filled once the lookup verzoek is saved.
		$remote['lookupRequestId'] = '';
		$remote['gekoppeldContact'] = $query->links->contactId;
		return $remote;
	}//end remotePerson()

	/**
	 * The brpLookupVerzoek record of a lookup, without null fields.
	 *
	 * @param string    $actor The session user who looked up.
	 * @param LookupRun $run   The lookup.
	 *
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) BsnValidationService::hash() is a pure static hashing utility.
	 */
	private function lookupRequestRecord(string $actor, LookupRun $run): array {
		$fetch = $run->fetch;
		$contactId = null;
		if ($run->query->links->contactId !== '') {
			$contactId = $run->query->links->contactId;
		}

		$record = [
			'bsnHash' => BsnValidationService::hash($run->query->rawBsn),
			'verzoekreden' => $run->query->reason->verzoekreden,
			'purposeBinding' => $run->query->reason->purposeBinding,
			'basis' => $run->query->basis,
			'requestedBy' => $actor,
			'requestedOnBehalfOf' => $run->actorRole,
			'requestMoment' => $run->requestedAt->format(DATE_ATOM),
			'linkedRequest' => $run->query->reason->linkedRequest,
			'gekoppeldContact' => $contactId,
			'responseStatus' => $fetch->result->outcome,
			'responseMoment' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
			'responseDurationMs' => $fetch->durationMs,
			'haalcentraalCorrelationId' => $fetch->result->correlationId,
			'responseContainsSecrecy' => $fetch->isSecret(),
			'responseInCache' => $fetch->fromCache,
			'cacheExpiresOn' => ($fetch->person['retentionTo'] ?? null),
		];
		return array_filter($record, static fn ($v) => $v !== null);
	}//end lookupRequestRecord()

	/**
	 * Store the saved verzoek's id on a person fetched from the BRP.
	 *
	 * @param PersonFetch $fetch       The fetch.
	 * @param string      $requestUuid The saved verzoek's id, or ''.
	 *
	 * @return PersonFetch The fetch with the back-filled person.
	 */
	private function backfillLookupRequest(PersonFetch $fetch, string $requestUuid): PersonFetch {
		if ($fetch->person === null || $fetch->fromCache === true || $requestUuid === '') {
			return $fetch;
		}

		$person = $fetch->person;
		$person['lookupRequestId'] = $requestUuid;
		$this->saveBrpPerson(person: $person);
		return $fetch->withPerson(person: $person);
	}//end backfillLookupRequest()

	/**
	 * Stamp the linked contact and client of a lookup that found a person.
	 *
	 * @param LookupRun $run The lookup.
	 *
	 * @return void
	 */
	private function stampVerifiedLinks(LookupRun $run): void {
		$person = $run->fetch->person;
		if ($person === null) {
			return;
		}

		$links = $run->query->links;
		if ($links->contactId !== '') {
			$this->stampContactWithVerifiedBsn(contactId: $links->contactId, fetch: $run->fetch);
		}

		if ($links->clientId !== '') {
			$this->stampClientIdentity(links: $links, verifiedAt: $run->requestedAt);
		}
	}//end stampVerifiedLinks()

	/**
	 * The answer to a lookup; never echoes the BSN.
	 *
	 * @param PersonFetch $fetch       The fetch.
	 * @param string      $requestUuid The saved verzoek's id, or ''.
	 *
	 * @return JSONResponse
	 */
	private function lookupResponse(PersonFetch $fetch, string $requestUuid): JSONResponse {
		$outcome = $fetch->result->outcome;
		if ($outcome === 'not-found') {
			return new JSONResponse(
				[
					'errorCode' => 'not-found',
					'errorMessage' => $this->l10n->t('BSN niet aangetroffen in BRP — controleer invoer.'),
					'responseInCache' => false,
					'lookupRequestId' => $requestUuid,
				],
				Http::STATUS_NOT_FOUND
			);
		}

		if ($outcome === 'error' || $outcome === 'timeout') {
			return new JSONResponse(
				[
					'errorCode' => 'brp-unavailable',
					'errorMessage' => $this->l10n->t('BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.'),
					'responseInCache' => false,
					'lookupRequestId' => $requestUuid,
				],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$person = $fetch->person;
		unset($person['bsn']);
		return new JSONResponse(
			[
				'persoon' => $person,
				'responseInCache' => $fetch->fromCache,
				'lookupRequestId' => $requestUuid,
			],
			Http::STATUS_OK
		);
	}//end lookupResponse()

	/**
	 * POST /api/brp/contact/{id}/reveal-address — reveal a geheimhouding adres
	 * with an extra audit entry.
	 *
	 * @param string $id Contact UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-006-01
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

		// The raw BSN is not reconstructible here, but the persoon in hand carries
		// its own hash — so pass THAT, rather than letting the service hash ''.
		// It used to hash the empty string, which stamped every geheimhouding
		// reveal with the same sha256("") and produced an audit trail that named
		// no data subject at all.
		$auditId = $this->audit->recordLookup(
			actor: $actor,
			lookup: new LookupAudit(
				subject: new LookupSubject(rawBsn: '', bsnHash: (string)($person['bsnHash'] ?? '')),
				reason: new LookupReason(
					verzoekreden: 'Adres onthuld op behandelaarsverantwoording',
					purposeBinding: 'Wet BRP art. 3.3 (uitzondering geheimhouding)',
					linkedRequest: null,
					vogScreening: false,
				),
				result: new LookupResult(outcome: 'address-revealed', action: 'brp-address-revealed', responseCode: 200),
				actorRole: $this->resolveActorRole(actor: $actor),
			),
		);

		// 🔴 NO AUDIT RECORD, NO REVEAL.
		//
		// recordLookup() answers '' when the write failed, and that return used
		// to be discarded — so a reveal whose audit record never landed still
		// answered 200 with the withheld residence. An unlogged disclosure of a
		// geheimhouding address is the exact thing the audit trail exists to
		// make impossible, so this fails closed: the caller is told the
		// disclosure could not be recorded, and gets no address.
		if ($auditId === '') {
			return new JSONResponse(
				[
					'error' => $this->l10n->t('Address reveal could not be recorded'),
					'errorMessage' => $this->l10n->t(
						'De onthulling kon niet worden vastgelegd in het BSN-auditlogboek. '
						. 'Het adres is daarom niet getoond.'
					),
				],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

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
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-006-02
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
	 * The rate limit is a volume ceiling only: the sender authenticates by its
	 * own credential, and refusing a citizen-record mutation because of a rate
	 * limit would leave this app's data silently stale.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-004-03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function mutationWebhook(): JSONResponse {
		$rawBody = (string)file_get_contents('php://input');
		$signature = (string)$this->request->getHeader('X-Signature');
		if ($signature === '') {
			$signature = (string)$this->request->getHeader('x-signature');
		}

		// 🔴 A REJECTED DELIVERY MUST NOT BE ACKNOWLEDGED WITH 200.
		//
		// This answered 200 for every outcome, including `forbidden` (the HMAC
		// did not verify) and `bad-request` (the body was malformed), on the
		// stated grounds that "HaalCentraal expects to ack the delivery
		// regardless of inner outcome". A 200 IS the acknowledgement: it tells
		// the sender the mutation was taken. A rejected delivery was therefore
		// indistinguishable from an accepted one at the only layer the sender
		// can see, and a genuine misconfiguration -- a wrong shared secret --
		// would look like a working integration forever while every mutation
		// was dropped.
		//
		// The structured `result` field stays, so a client that reads the body
		// loses nothing; the status now agrees with it. A bad signature is 403
		// and a malformed body is 400, which are the two things the sender can
		// actually act on.
		$outcome = $this->webhookListener->handle($rawBody, $signature);

		$status = Http::STATUS_OK;
		if ($outcome['result'] === BrpMutationWebhookListener::RESULT_FORBIDDEN) {
			$status = Http::STATUS_FORBIDDEN;
		}

		if ($outcome['result'] === BrpMutationWebhookListener::RESULT_BAD_REQUEST) {
			$status = Http::STATUS_BAD_REQUEST;
		}

		return new JSONResponse(
			[
				'result' => $outcome['result'],
				'invalidated' => $outcome['invalidated'],
			],
			$status
		);
	}//end mutationWebhook()

	/**
	 * GET /api/brp/monitor — return the latest BrpMonitorJob report + cert health.
	 *
	 * Admin-only: regular users would learn sensitive operational metrics.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-010
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
	 * Record on a client that its identity was verified in the BRP: when, via
	 * what, and what the employee checked with the customer.
	 *
	 * @param LookupLinks       $links      The client UUID and what the employee checked ('' keeps the earlier one).
	 * @param DateTimeImmutable $verifiedAt When the lookup ran.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/brp-lookup/spec.md#requirement-a-brp-lookup-can-verify-a-clients-identity
	 */
	private function stampClientIdentity(LookupLinks $links, DateTimeImmutable $verifiedAt): void {
		$clientId = $links->clientId;
		$identityCheck = $links->identityCheck;
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$existing = $this->getObjectService()->find(id: $clientId, register: $register, schema: $schema);
			$client = [];
			if (is_array($existing) === true) {
				$client = $existing;
			} else if (is_object($existing) === true && method_exists($existing, 'jsonSerialize') === true) {
				$client = (array)$existing->jsonSerialize();
			}

			if ($client === []) {
				return;
			}

			$client['identityVerifiedAt'] = $verifiedAt->format(DATE_ATOM);
			$client['identityVerifiedVia'] = 'BRP';
			if ($identityCheck !== '') {
				$client['identityCheck'] = $identityCheck;
			}

			$this->getObjectService()->saveObject(object: $client, extend: [], register: $register, schema: $schema, uuid: $clientId);
		} catch (Throwable $e) {
			$this->logger->error('client identity stamp failed', ['clientId' => $clientId, 'error' => $e->getMessage()]);
		}//end try
	}//end stampClientIdentity()

	/**
	 * Stamp the Pipelinq contact with verifiedBSN/brpPersoonId/geheimhouding.
	 *
	 * @param string      $contactId Contact UUID.
	 * @param PersonFetch $fetch     The fetch whose person is stamped on the contact.
	 *
	 * @return void
	 */
	private function stampContactWithVerifiedBsn(string $contactId, PersonFetch $fetch): void {
		$brpPersonId = (string)($fetch->person['@self']['id'] ?? $fetch->person['id'] ?? '');
		$secrecy = $fetch->isSecret();
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
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
