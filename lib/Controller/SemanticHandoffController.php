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

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SemanticHandoffService;
use OCA\Pipelinq\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Request→Case and Contract→Invoice emit endpoints.
 *
 * Endpoints (all NoAdminRequired + per-object register-RBAC guard):
 *   GET  /api/handoff/request/{id}/availability
 *   POST /api/handoff/request/{id}/convert-to-case
 *   GET  /api/handoff/contract/{id}/availability
 *   POST /api/handoff/contract/{id}/send-to-invoicing
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
class SemanticHandoffController extends Controller
{
    /**
     * Canonical semantic kind URIs.
     */
    private const KIND_CASE    = 'https://openregister.app/ns#Case';
    private const KIND_INVOICE = 'https://openregister.app/ns#Invoice';

    /**
     * Declared x-openregister-handoff entry ids.
     */
    private const HANDOFF_CONVERT_TO_CASE   = 'convert-to-case';
    private const HANDOFF_SEND_TO_INVOICING = 'send-to-invoicing';

    /**
     * Built-in slug of the unified ticket schema (app-config overridable).
     */
    private const TICKET_SCHEMA_SLUG = 'ticket';

    /**
     * Constructor.
     *
     * @param IRequest               $request        HTTP request.
     * @param SemanticHandoffService $handoffService Kind resolution + emit wrapper.
     * @param ContainerInterface     $container      DI container (OR ObjectService).
     * @param IAppConfig             $appConfig      App config (register/schema slugs).
     * @param TicketService          $ticketService  Unified ticket resolver.
     * @param IUserSession           $userSession    User session.
     * @param LoggerInterface        $logger         Logger.
     */
    public function __construct(
        IRequest $request,
        private SemanticHandoffService $handoffService,
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private TicketService $ticketService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
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
    public function requestAvailability(string $id=''): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $request = $this->loadRequestTicket(id: $id);
        if ($request === null) {
            return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
        }

        $available = $this->handoffService->hasImplementer(kindUri: self::KIND_CASE);
        $status    = (string) ($request['status'] ?? '');

        return new JSONResponse(
                [
                    'available'     => $available,
                    'status'        => $status,
                    'canConvert'    => ($available === true && $status === 'in_progress'),
                    'caseReference' => (string) ($request['caseReference'] ?? ''),
                ]
                );
    }//end requestAvailability()

    /**
     * Convert an in-progress request ticket into a case via OR's handoff engine.
     *
     * @param string $id Request-ticket UUID.
     *
     * @return JSONResponse The conversion outcome.
     *
     * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
     * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
     */
    #[NoAdminRequired]
    public function convertRequestToCase(string $id=''): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $request = $this->loadRequestTicket(id: $id);
        if ($request === null) {
            return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
        }

        if ((string) ($request['status'] ?? '') !== 'in_progress') {
            return new JSONResponse(
                ['status' => 'invalid-status', 'message' => 'Conversion is only available from in_progress.'],
                Http::STATUS_CONFLICT
            );
        }

        if ($this->handoffService->hasImplementer(kindUri: self::KIND_CASE) === false) {
            return new JSONResponse(['status' => 'not-available'], Http::STATUS_CONFLICT);
        }

        $result = $this->handoffService->handoff(
            register: $this->registerSlug(),
            schema: $this->ticketSchema(),
            id: $id,
            handoffId: self::HANDOFF_CONVERT_TO_CASE
        );

        if ($result['ok'] !== true) {
            return new JSONResponse(
                ['status' => 'handoff-failed', 'reason' => (string) $result['reason']],
                Http::STATUS_BAD_GATEWAY
            );
        }

        // Keep the discriminator pinned on the round-tripped payload so the
        // write can never silently untype the ticket.
        $request['ticketType']    = TicketService::TYPE_REQUEST;
        $request['status']        = 'converted';
        $request['caseReference'] = (string) $result['targetUuid'];
        $saved = $this->saveObject(schema: $this->ticketSchema(), id: $id, payload: $request);
        if ($saved === false) {
            // The case was created + linked by the engine; only the local
            // status write failed. Surface it honestly without a 5xx.
            return new JSONResponse(
                ['status' => 'converted-unsynced', 'caseReference' => (string) $result['targetUuid']],
                Http::STATUS_OK
            );
        }

        return new JSONResponse(
                [
                    'status'        => 'converted',
                    'caseReference' => (string) $result['targetUuid'],
                    'correlationId' => (string) $result['correlationId'],
                ]
                );
    }//end convertRequestToCase()

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
    public function contractAvailability(string $id=''): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $contract = $this->loadObject(schema: $this->schemaSlug(key: 'contract_schema', default: 'contract'), id: $id);
        if ($contract === null) {
            return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
        }

        $available = $this->handoffService->hasImplementer(kindUri: self::KIND_INVOICE);
        $status    = (string) ($contract['status'] ?? '');

        return new JSONResponse(
                [
                    'available' => $available,
                    'status'    => $status,
                    'canSend'   => ($available === true && $status === 'active'),
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
    public function sendContractToInvoicing(string $id=''): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $contract = $this->loadObject(schema: $this->schemaSlug(key: 'contract_schema', default: 'contract'), id: $id);
        if ($contract === null) {
            return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
        }

        if ((string) ($contract['status'] ?? '') !== 'active') {
            return new JSONResponse(
                ['status' => 'invalid-status', 'message' => 'Send to invoicing is only available on an active contract.'],
                Http::STATUS_CONFLICT
            );
        }

        if ($this->handoffService->hasImplementer(kindUri: self::KIND_INVOICE) === false) {
            return new JSONResponse(['status' => 'not-available'], Http::STATUS_CONFLICT);
        }

        $result = $this->handoffService->handoff(
            register: $this->registerSlug(),
            schema: $this->schemaSlug(key: 'contract_schema', default: 'contract'),
            id: $id,
            handoffId: self::HANDOFF_SEND_TO_INVOICING
        );

        if ($result['ok'] !== true) {
            return new JSONResponse(
                ['status' => 'handoff-failed', 'reason' => (string) $result['reason']],
                Http::STATUS_BAD_GATEWAY
            );
        }

        $contract['invoiceReference'] = (string) $result['targetUuid'];
        $this->saveObject(
            schema: $this->schemaSlug(key: 'contract_schema', default: 'contract'),
            id: $id,
            payload: $contract
        );

        return new JSONResponse(
                [
                    'status'           => 'sent',
                    'invoiceReference' => (string) $result['targetUuid'],
                    'correlationId'    => (string) $result['correlationId'],
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
    private function loadRequestTicket(string $id): ?array
    {
        $ticket = $this->loadObject(schema: $this->ticketSchema(), id: $id);
        if ($ticket === null) {
            return null;
        }

        if ((string) ($ticket['ticketType'] ?? '') !== TicketService::TYPE_REQUEST) {
            return null;
        }

        return $ticket;
    }//end loadRequestTicket()

    /**
     * The unified ticket schema: the configured id, else the built-in slug.
     *
     * @return string Schema id or slug.
     */
    private function ticketSchema(): string
    {
        $schemaId = $this->ticketService->getSchemaId();
        if ($schemaId !== '') {
            return $schemaId;
        }

        return self::TICKET_SCHEMA_SLUG;
    }//end ticketSchema()

    /**
     * Load a pipelinq-register object by schema slug/id + id (per-object guard).
     *
     * @param string $schema Schema id or slug.
     * @param string $id     Object UUID.
     *
     * @return array<string, mixed>|null The object, or null when absent.
     */
    private function loadObject(string $schema, string $id): ?array
    {
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
     * Save an object payload back to the pipelinq register.
     *
     * @param string               $schema  Schema id or slug.
     * @param string               $id      Object UUID.
     * @param array<string, mixed> $payload Full object payload.
     *
     * @return bool True on success.
     *
     * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
     */
    private function saveObject(string $schema, string $id, array $payload): bool
    {
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
    private function objectService(): ?object
    {
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
    private function toArray(mixed $value): array
    {
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
    private function registerSlug(): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        if ($slug !== '') {
            return $slug;
        }

        return 'pipelinq';
    }//end registerSlug()

    /**
     * Resolve a schema slug app-config override.
     *
     * @param string $key     App-config key.
     * @param string $default Built-in default.
     *
     * @return string Slug.
     */
    private function schemaSlug(string $key, string $default): string
    {
        $slug = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        if ($slug !== '') {
            return $slug;
        }

        return $default;
    }//end schemaSlug()
}//end class
