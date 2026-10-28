<?php

/**
 * Pipelinq SemanticHandoffController.
 *
 * HTTP surface for pipelinq's emit side of the ADR-051 semantic-object-handoff
 * chains: convert a request into a case (`ns#Case`) and send an active contract
 * to invoicing (`ns#Invoice`). Both are kind-addressed — the target app is
 * resolved by OpenRegister from the declared `x-openregister-handoff` entry, so
 * no procest/shillinq literal appears here. When no installed app implements the
 * kind the action is unavailable (the frontend hides it and the endpoint refuses
 * cleanly). A failed handoff never mutates the source object.
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
 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\SemanticHandoffService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Request→Case and Contract→Invoice emit endpoints.
 *
 * Endpoints (all NoAdminRequired):
 *   GET  /api/handoff/request/{id}/availability
 *   POST /api/handoff/request/{id}/convert-to-case
 *   GET  /api/handoff/contract/{id}/availability
 *   POST /api/handoff/contract/{id}/send-to-invoicing
 *
 * ⚠️ There is no per-object "register-RBAC guard" behind these routes — an
 * earlier version of this docblock claimed one and there never was one. A
 * schema that declares no `authorization` block makes OpenRegister's
 * PermissionHandler grant every action to every authenticated user, so the
 * controller is the only layer. The two contract routes therefore authorize
 * explicitly against the contract's `ownerId` via {@see ObjectOwnerAccessPolicy}.
 * The two request routes cannot: the `ticket` schema declares no owner field,
 * so who may act on someone else's ticket is an open product decision (#801).
 *
 * Since unify-ticket-supertype a request is a `ticket` carrying
 * `ticketType: request` — the request endpoints read and write the unified
 * ticket schema (resolved via {@see TicketService}) and refuse any ticket of
 * another subtype. Contracts keep their own `contract` schema.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thin HTTP facade over the
 *  handoff service + the OR object surface.
 *
 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
 */
class SemanticHandoffController extends Controller {
	/**
	 * Canonical semantic kind URIs.
	 */
	private const KIND_CASE = 'https://openregister.app/ns#Case';
	private const KIND_INVOICE = 'https://openregister.app/ns#Invoice';

	/**
	 * Declared x-openregister-handoff entry ids.
	 */
	private const HANDOFF_CONVERT_TO_CASE = 'convert-to-case';
	private const HANDOFF_SEND_TO_INVOICING = 'send-to-invoicing';

	/**
	 * The statuses of a running contract, which may be sent to invoicing: an
	 * expiring contract still runs until its end date (PqContract).
	 */
	private const INVOICEABLE_STATUSES = ['active', 'expiring'];

	/**
	 * Built-in slug of the unified ticket schema (app-config overridable).
	 */
	private const TICKET_SCHEMA_SLUG = 'ticket';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param SemanticHandoffService $handoffService Kind resolution + emit wrapper.
	 * @param ContainerInterface $container DI container (OR ObjectService).
	 * @param IAppConfig $appConfig App config (register/schema slugs).
	 * @param TicketService $ticketService Unified ticket resolver.
	 * @param IUserSession $userSession User session.
	 * @param ObjectOwnerAccessPolicy $accessPolicy Per-object owner authorization.
	 * @param LoggerInterface $logger Logger.
	 * @param IL10N $l10n Writes the answer that tells the customer the case number.
	 */
	public function __construct(
		IRequest $request,
		private SemanticHandoffService $handoffService,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private TicketService $ticketService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether the "Convert to case" action is available for a request.
	 *
	 * @param string $id Request UUID.
	 *
	 * @return JSONResponse {available, status, canConvert, caseReference}.
	 *
	 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
	 */
	#[NoAdminRequired]
	public function requestAvailability(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		// This controller already authorises per object further down via
		// accessPolicy->mayAccess(); these two entry points reach the handoff
		// surface before any object is resolved, so the privileged-group half
		// of the same policy is the check available here.
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$request = $this->loadRequestTicket(id: $id);
		if ($request === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$available = $this->handoffService->hasImplementer(kindUri: self::KIND_CASE);
		$status = (string)($request['status'] ?? '');

		return new JSONResponse(
			[
				'available' => $available,
				'status' => $status,
				'canConvert' => ($available === true && $status === 'in_progress'),
				'caseReference' => (string)($request['caseReference'] ?? ''),
				'caseTypeSource' => [
					'register' => $this->appConfig->getValueString(
						app: Application::APP_ID,
						key: 'handoff_case_type_register',
						default: 'dossiq'
					),
					'schema' => $this->appConfig->getValueString(
						app: Application::APP_ID,
						key: 'handoff_case_type_schema',
						default: 'caseType'
					),
				],
				'portalName' => $this->appConfig->getValueString(app: Application::APP_ID, key: 'portal_name', default: ''),
			]
		);
	}//end requestAvailability()

	/**
	 * Convert an in-progress request ticket into a case via OR's handoff engine.
	 *
	 * Body params: `caseType` (optional case-type UUID, carried to the case
	 * through the handoff mapping), `keepOpen` (true keeps the request's
	 * status; by default it becomes `converted`) and `informCustomer` (true
	 * answers the customer on the portal with the case number and, once
	 * known, who handles it).
	 *
	 * @param string $id Request-ticket UUID.
	 *
	 * @return JSONResponse The conversion outcome.
	 *
	 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 * @spec openspec/changes/convert-informs-the-customer/specs/request-management/spec.md#requirement-converting-can-tell-the-customer-the-case-number
	 */
	#[NoAdminRequired]
	public function convertRequestToCase(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		// This controller already authorises per object further down via
		// accessPolicy->mayAccess(); these two entry points reach the handoff
		// surface before any object is resolved, so the privileged-group half
		// of the same policy is the check available here.
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$request = $this->loadRequestTicket(id: $id);
		if ($request === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		if ((string)($request['status'] ?? '') !== 'in_progress') {
			return new JSONResponse(
				['status' => 'invalid-status', 'message' => 'Conversion is only available from in_progress.'],
				Http::STATUS_CONFLICT
			);
		}

		if ($this->handoffService->hasImplementer(kindUri: self::KIND_CASE) === false) {
			return new JSONResponse(['status' => 'not-available'], Http::STATUS_CONFLICT);
		}

		$caseTypeError = $this->recordRequestedCaseType(id: $id, request: $request);
		if ($caseTypeError !== null) {
			return $caseTypeError;
		}

		$keepOpen = filter_var($this->request->getParam('keepOpen', false), FILTER_VALIDATE_BOOLEAN);

		$result = $this->handoffService->handoff(
			register: $this->registerSlug(),
			schema: $this->ticketSchema(),
			id: $id,
			handoffId: self::HANDOFF_CONVERT_TO_CASE
		);

		if ($result['ok'] !== true) {
			return new JSONResponse(
				['status' => 'handoff-failed', 'reason' => (string)$result['reason']],
				Http::STATUS_BAD_GATEWAY
			);
		}

		return $this->closeConvertedRequest(id: $id, request: $request, result: $result, keepOpen: $keepOpen);
	}//end convertRequestToCase()

	/**
	 * Store the case type the person chose on the request, where the handoff
	 * mapping reads it (`caseType` <- `requestedCaseType`).
	 *
	 * @param string               $id      Request-ticket UUID.
	 * @param array<string, mixed> $request The request, updated in place.
	 *
	 * @return JSONResponse|null An error response, or null when there is none.
	 */
	private function recordRequestedCaseType(string $id, array &$request): ?JSONResponse {
		$caseType = trim((string)$this->request->getParam('caseType', ''));
		if ($caseType === '') {
			return null;
		}

		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $caseType) !== 1) {
			return new JSONResponse(['status' => 'invalid-case-type'], Http::STATUS_BAD_REQUEST);
		}

		$request['ticketType'] = TicketService::TYPE_REQUEST;
		$request['requestedCaseType'] = $caseType;
		if ($this->saveObject(schema: $this->ticketSchema(), id: $id, payload: $request) === false) {
			return new JSONResponse(['status' => 'handoff-failed', 'reason' => 'case-type-not-saved'], Http::STATUS_BAD_GATEWAY);
		}

		return null;
	}//end recordRequestedCaseType()

	/**
	 * Write the case reference back on a converted request and answer.
	 *
	 * @param string               $id       Request-ticket UUID.
	 * @param array<string, mixed> $request  The request.
	 * @param array<string, mixed> $result   The handoff result.
	 * @param bool                 $keepOpen Whether the request stays open.
	 *
	 * @return JSONResponse The conversion outcome.
	 */
	private function closeConvertedRequest(string $id, array $request, array $result, bool $keepOpen): JSONResponse {
		// Keep the discriminator pinned on the round-tripped payload so the
		// write can never silently untype the ticket.
		$request['ticketType'] = TicketService::TYPE_REQUEST;
		if ($keepOpen === false) {
			$request['status'] = 'converted';
		}

		$request['caseReference'] = (string)$result['targetUuid'];
		if (filter_var($this->request->getParam('informCustomer', false), FILTER_VALIDATE_BOOLEAN) === true) {
			$request = $this->withCaseAnswer(request: $request, caseUuid: (string)$result['targetUuid']);
		}

		$saved = $this->saveObject(schema: $this->ticketSchema(), id: $id, payload: $request);
		if ($saved === false) {
			// The case was created + linked by the engine; only the local
			// status write failed. Surface it honestly without a 5xx.
			return new JSONResponse(
				['status' => 'converted-unsynced', 'caseReference' => (string)$result['targetUuid']],
				Http::STATUS_OK
			);
		}

		return new JSONResponse(
			[
				'status' => 'converted',
				'caseReference' => (string)$result['targetUuid'],
				'correlationId' => (string)$result['correlationId'],
			]
		);
	}//end closeConvertedRequest()

	/**
	 * Whether the "Send to invoicing" action is available for a contract.
	 *
	 * @param string $id Contract UUID.
	 *
	 * @return JSONResponse {available, status, canSend}.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-to-invoicing-handoff-emit
	 */
	#[NoAdminRequired]
	public function contractAvailability(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$contract = $this->loadObject(schema: $this->schemaSlug(key: 'contract_schema', default: 'salesContract'), id: $id);
		if ($contract === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->mayActOnContract(uid: $user->getUID(), contract: $contract) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$available = $this->handoffService->hasImplementer(kindUri: self::KIND_INVOICE);
		$status = (string)($contract['status'] ?? '');

		return new JSONResponse(
			[
				'available' => $available,
				'status' => $status,
				'canSend' => ($available === true && in_array($status, self::INVOICEABLE_STATUSES, true) === true),
			]
		);
	}//end contractAvailability()

	/**
	 * Send an active contract to the invoice implementer via OR's engine.
	 *
	 * @param string $id Contract UUID.
	 *
	 * @return JSONResponse The handoff outcome.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-to-invoicing-handoff-emit
	 */
	#[NoAdminRequired]
	public function sendContractToInvoicing(string $id = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$contract = $this->loadObject(schema: $this->schemaSlug(key: 'contract_schema', default: 'salesContract'), id: $id);
		if ($contract === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		// Authorize BEFORE the status gate: a stranger must not be able to
		// distinguish "not yours" from "not active" on someone else's contract.
		if ($this->mayActOnContract(uid: $user->getUID(), contract: $contract) === false) {
			return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		if (in_array((string)($contract['status'] ?? ''), self::INVOICEABLE_STATUSES, true) === false) {
			return new JSONResponse(
				['status' => 'invalid-status', 'message' => 'Send to invoicing is only available on a running contract (active or expiring).'],
				Http::STATUS_CONFLICT
			);
		}

		if ($this->handoffService->hasImplementer(kindUri: self::KIND_INVOICE) === false) {
			return new JSONResponse(['status' => 'not-available'], Http::STATUS_CONFLICT);
		}

		$result = $this->handoffService->handoff(
			register: $this->registerSlug(),
			schema: $this->schemaSlug(key: 'contract_schema', default: 'salesContract'),
			id: $id,
			handoffId: self::HANDOFF_SEND_TO_INVOICING
		);

		if ($result['ok'] !== true) {
			return new JSONResponse(
				['status' => 'handoff-failed', 'reason' => (string)$result['reason']],
				Http::STATUS_BAD_GATEWAY
			);
		}

		$sentAt = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$contract['invoiceReference'] = (string)$result['targetUuid'];
		$contract['invoicedAt'] = $sentAt;
		$contract['invoicedBy'] = $user->getUID();
		// Every send is kept, so this year's invoicing can be read back.
		$history = $contract['invoiceHistory'] ?? [];
		if (is_array($history) === false) {
			$history = [];
		}

		$history[] = [
			'reference' => (string)$result['targetUuid'],
			'sentAt' => $sentAt,
			'sentBy' => $user->getUID(),
			'amount' => (float)($contract['valuePerInterval'] ?? $contract['value'] ?? 0),
		];
		$contract['invoiceHistory'] = array_values($history);
		$this->saveObject(
			schema: $this->schemaSlug(key: 'contract_schema', default: 'salesContract'),
			id: $id,
			payload: $contract
		);

		return new JSONResponse(
			[
				'status' => 'sent',
				'invoiceReference' => (string)$result['targetUuid'],
				'correlationId' => (string)$result['correlationId'],
			]
		);
	}//end sendContractToInvoicing()

	/**
	 * Load a request-type ticket by id (per-object guard + subtype guard).
	 *
	 * A ticket of another subtype (complaint / contactmoment) is reported as
	 * absent so the request endpoints can never act on it.
	 *
	 * @param string $id Ticket UUID.
	 *
	 * @return array<string, mixed>|null The request ticket, or null when absent.
	 */
	private function loadRequestTicket(string $id): ?array {
		$ticket = $this->loadObject(schema: $this->ticketSchema(), id: $id);
		if ($ticket === null) {
			return null;
		}

		if ((string)($ticket['ticketType'] ?? '') !== TicketService::TYPE_REQUEST) {
			return null;
		}

		return $ticket;
	}//end loadRequestTicket()

	/**
	 * The unified ticket schema: the configured id, else the built-in slug.
	 *
	 * @return string Schema id or slug.
	 */
	private function ticketSchema(): string {
		$schemaId = $this->ticketService->getSchemaId();
		if ($schemaId !== '') {
			return $schemaId;
		}

		return self::TICKET_SCHEMA_SLUG;
	}//end ticketSchema()

	/**
	 * Per-object authorization for a contract: owner or privileged group.
	 *
	 * @param string $uid The caller user ID.
	 * @param array<string,mixed> $contract The loaded contract.
	 *
	 * @return bool True when the caller may act on this contract.
	 */
	private function mayActOnContract(string $uid, array $contract): bool {
		return $this->accessPolicy->mayAccess(uid: $uid, object: $contract, ownerField: 'ownerId');
	}//end mayActOnContract()

	/**
	 * Load a pipelinq-register object by schema slug/id + id.
	 *
	 * ⚠️ This is a LOAD, not a guard. Its docblock used to say "per-object
	 * guard" and the body has only ever been `find(id, register, schema)`;
	 * callers must authorize the object themselves (#801).
	 *
	 * @param string $schema Schema id or slug.
	 * @param string $id Object UUID.
	 *
	 * @return array<string, mixed>|null The object, or null when absent.
	 */
	private function loadObject(string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		$objectService = $this->objectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(
				id: $id,
				register: $this->registerSlug(),
				schema: $schema,
			);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}//end loadObject()

	/**
	 * The request with an answer to the customer that names the new case:
	 * its number and, when the case has one, its handler. The answer goes
	 * where every answer goes (`customerMessage`, appended to
	 * `portalAnswers`), so the portal shows it as it shows an answer.
	 *
	 * @param array<string, mixed> $request  The request ticket.
	 * @param string               $caseUuid The case the handoff created.
	 *
	 * @return array<string, mixed> The request with the answer.
	 *
	 * @spec openspec/changes/convert-informs-the-customer/specs/request-management/spec.md#requirement-converting-can-tell-the-customer-the-case-number
	 */
	private function withCaseAnswer(array $request, string $caseUuid): array {
		$case    = ($this->loadAnyObject(id: $caseUuid) ?? []);
		$number  = (string)($case['identifier'] ?? ($case['caseNumber'] ?? ($case['reference'] ?? '')));
		$handler = (string)($case['assigneeName'] ?? ($case['handlerName'] ?? ''));

		$message = $this->l10n->t('Your request is now a case. You can follow it on your personal page.');
		if ($number !== '') {
			$message = $this->l10n->t('Your request is now case %1$s. You can follow it on your personal page.', [$number]);
		}

		if ($handler !== '') {
			$message .= ' ' . $this->l10n->t('%1$s handles the case.', [$handler]);
		}

		$answers = $request['portalAnswers'] ?? [];
		if (is_array($answers) === false) {
			$answers = [];
		}

		$answers[] = ['message' => $message, 'createdAt' => gmdate('Y-m-d\TH:i:s\Z')];
		$request['customerMessage'] = $message;
		$request['portalAnswers']   = array_values($answers);
		return $request;
	}//end withCaseAnswer()

	/**
	 * An object by its UUID in whatever register holds it (the case the
	 * handoff made lives in the case app's register), or null.
	 *
	 * @param string $id The UUID.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	private function loadAnyObject(string $id): ?array {
		$objectService = $this->objectService();
		if ($objectService === null || $id === '') {
			return null;
		}

		try {
			$entity = $objectService->find(id: $id);
		} catch (Throwable $e) {
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}//end loadAnyObject()

	/**
	 * Save an object payload back to the pipelinq register.
	 *
	 * @param string $schema Schema id or slug.
	 * @param string $id Object UUID.
	 * @param array<string, mixed> $payload Full object payload.
	 *
	 * @return bool True on success.
	 *
	 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
	 */
	private function saveObject(string $schema, string $id, array $payload): bool {
		$objectService = $this->objectService();
		if ($objectService === null) {
			return false;
		}

		try {
			$objectService->saveObject(
				object: $payload,
				register: $this->registerSlug(),
				schema: $schema,
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SemanticHandoffController.saveObject: save failed',
				['id' => $id, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end saveObject()

	/**
	 * Resolve the OpenRegister ObjectService loosely.
	 *
	 * @return object|null Service or null.
	 */
	private function objectService(): ?object {
		try {
			$service = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false || method_exists($service, 'find') === false) {
			return null;
		}

		return $service;
	}//end objectService()

	/**
	 * Normalise an OR entity to a plain array.
	 *
	 * @param mixed $value Entity or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end toArray()

	/**
	 * The pipelinq register slug (app-config overridable).
	 *
	 * Fails closed on a blanked override. Passing the built-in slug as
	 * getValueString()'s default only covers an ABSENT key: a key that is
	 * present but set to an empty string returns '' verbatim, and none of the
	 * callers here check for that. An empty register is not the same as "no
	 * register" to OpenRegister — ObjectService skips setRegister() for an
	 * empty value, so the query silently inherits whatever register context an
	 * earlier call in the same request left on the shared service instance.
	 * Normalising '' back to the built-in slug, exactly as the sibling
	 * schemaSlug() already does, keeps every call scoped.
	 *
	 * @return string Slug; never empty.
	 */
	private function registerSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return 'pipelinq';
	}//end registerSlug()

	/**
	 * Resolve a schema slug app-config override.
	 *
	 * @param string $key App-config key.
	 * @param string $default Built-in default.
	 *
	 * @return string Slug.
	 */
	private function schemaSlug(string $key, string $default): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($slug !== '') {
			return $slug;
		}

		return $default;
	}//end schemaSlug()
}//end class
