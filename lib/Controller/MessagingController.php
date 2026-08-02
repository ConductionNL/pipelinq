<?php

/**
 * Pipelinq MessagingController.
 *
 * HTTP surface for the agent outbound messaging feature (REQ-OM-004): the
 * server-side send endpoint, the composer preflight, consent opt-in/opt-out
 * recording and the admin-gated zero-cost provider connectivity test. All
 * consent / budget / template gating stays server-side in MessagingService +
 * the channel adapters; the controller is a thin, per-object-guarded wrapper
 * that never returns a raw vendor error or credential.
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
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\MessagingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Outbound messaging controller.
 *
 * Endpoints:
 *   POST /api/messaging/send                    NoAdminRequired + per-object guard
 *   GET  /api/messaging/preflight/{contactId}   NoAdminRequired + per-object guard
 *   POST /api/messaging/consent                 NoAdminRequired + per-object guard
 *   POST /api/messaging/providers/{id}/test     AuthorizedAdminSetting
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thin HTTP facade over the
 *  send-surface services.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 */
class MessagingController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                  $request          HTTP request.
     * @param MessagingService          $messagingService Send-surface orchestration.
     * @param ChannelProviderRepository $providerRepo     Provider read-side.
     * @param ConsentService            $consentService   Consent records.
     * @param IUserSession              $userSession      User session.
     */
    public function __construct(
        IRequest $request,
        private MessagingService $messagingService,
        private ChannelProviderRepository $providerRepo,
        private ConsentService $consentService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Send an outbound SMS / WhatsApp message (REQ-OM-004).
     *
     * @param string             $contactId    Contact/client UUID.
     * @param string             $channel      `sms` or `whatsapp`.
     * @param string             $body         Free-text body (or empty for a template send).
     * @param string|null        $templateId   Approved template UUID (whatsapp) or null.
     * @param array<int, string> $parameters   Positional template parameters.
     * @param string|null        $providerHint Optional pinned vendor.
     * @param string             $clientId     Linked client UUID (audit), or empty.
     *
     * @return JSONResponse The sanitised outcome envelope.
     *
     * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
     */
    #[NoAdminRequired]
    public function send(
        string $contactId='',
        string $channel='',
        string $body='',
        ?string $templateId=null,
        array $parameters=[],
        ?string $providerHint=null,
        string $clientId=''
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        if ($channel !== 'sms' && $channel !== 'whatsapp') {
            return new JSONResponse(['status' => 'invalid-channel'], Http::STATUS_BAD_REQUEST);
        }

        // Per-object guard (no-admin-idor): the caller must be able to load
        // the contact via the register RBAC before any adapter is invoked.
        $contact = $this->messagingService->loadContact(contactId: $contactId);
        if ($contact === null) {
            return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
        }

        $outcome = $this->messagingService->send(
            contact: $contact,
            channel: $channel,
            body: $body,
            templateId: $templateId,
            parameters: $parameters,
            providerHint: $providerHint,
            actor: $user->getUID(),
            clientId: $clientId
        );

        return new JSONResponse($outcome, $this->httpStatusForOutcome(status: (string) $outcome['status']));
    }//end send()

    /**
     * Composer preflight facts for one contact (REQ-OM-004).
     *
     * @param string $contactId Contact/client UUID.
     *
     * @return JSONResponse Available channels, session-window, consent, templates.
     *
     * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
     */
    #[NoAdminRequired]
    public function preflight(string $contactId=''): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $contact = $this->messagingService->loadContact(contactId: $contactId);
        if ($contact === null) {
            return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($this->messagingService->preflight(contactId: $contactId));
    }//end preflight()

    /**
     * Record a consent opt-in / opt-out from the send surface (REQ-OM-005).
     *
     * @param string $contactId  Contact UUID.
     * @param string $channel    `sms` or `whatsapp`.
     * @param string $action     `opt-in` or `opt-out`.
     * @param string $source     Source enum (manual / webform / ...).
     * @param string $evidence   Mandatory audit-trail evidence.
     * @param string $legalBasis Mandatory GDPR legal basis.
     *
     * @return JSONResponse The recorded state.
     *
     * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
     */
    #[NoAdminRequired]
    public function consent(
        string $contactId='',
        string $channel='',
        string $action='',
        string $source='manual',
        string $evidence='',
        string $legalBasis=''
    ): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        if ($channel !== 'sms' && $channel !== 'whatsapp') {
            return new JSONResponse(['status' => 'invalid-channel'], Http::STATUS_BAD_REQUEST);
        }

        if ($evidence === '' || $legalBasis === '') {
            return new JSONResponse(['status' => 'evidence-and-legal-basis-required'], Http::STATUS_BAD_REQUEST);
        }

        if ($action !== 'opt-in' && $action !== 'opt-out') {
            return new JSONResponse(['status' => 'invalid-action'], Http::STATUS_BAD_REQUEST);
        }

        // Per-object guard (no-admin-idor).
        $contact = $this->messagingService->loadContact(contactId: $contactId);
        if ($contact === null) {
            return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
        }

        $attributedEvidence = sprintf('%s (recorded by %s)', $evidence, $user->getUID());
        $this->recordConsent(
            action: $action,
            contactId: $contactId,
            channel: $channel,
            source: $source,
            evidence: $attributedEvidence,
            legalBasis: $legalBasis
        );

        return new JSONResponse(
            [
                'status' => 'recorded',
                'state'  => $this->consentService->latestState(contactId: $contactId, channel: $channel),
            ]
        );
    }//end consent()

    /**
     * Append the consent record for a validated opt-in / opt-out action.
     *
     * @param string $action     `opt-in` or `opt-out` (already validated).
     * @param string $contactId  Contact UUID.
     * @param string $channel    `sms` or `whatsapp`.
     * @param string $source     Source enum.
     * @param string $evidence   Attributed audit evidence.
     * @param string $legalBasis GDPR legal basis.
     *
     * @return void
     *
     * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
     */
    private function recordConsent(
        string $action,
        string $contactId,
        string $channel,
        string $source,
        string $evidence,
        string $legalBasis
    ): void {
        if ($action === 'opt-in') {
            $this->consentService->recordOptIn(
                contactId: $contactId,
                channel: $channel,
                source: $source,
                evidence: $evidence,
                legalBasis: $legalBasis
            );
            return;
        }

        $this->consentService->recordOptOut(
            contactId: $contactId,
            channel: $channel,
            source: $source,
            evidence: $evidence,
            legalBasis: $legalBasis
        );
    }//end recordConsent()

    /**
     * Zero-cost provider connectivity test (REQ-OM-002, admin-gated).
     *
     * @param string $id Provider UUID.
     *
     * @return JSONResponse Reachable / degraded-cause (with mock badge).
     *
     * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-002-zero-cost-provider-connectivity-test
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function testProvider(string $id=''): JSONResponse
    {
        $provider = $this->providerRepo->findById(id: $id);
        if ($provider === null) {
            return new JSONResponse(['reachable' => false, 'cause' => 'provider-not-found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($this->messagingService->runProviderTest(provider: $provider));
    }//end testProvider()

    /**
     * Map a send outcome status onto an HTTP status code.
     *
     * @param string $status The sanitised outcome status.
     *
     * @return int HTTP status code.
     */
    private function httpStatusForOutcome(string $status): int
    {
        if ($status === 'sent') {
            return Http::STATUS_OK;
        }

        if ($status === 'failed') {
            return Http::STATUS_BAD_GATEWAY;
        }

        // Business refusals the caller can act on (consent / budget / template
        // / no-provider) — 422, envelope carries the reason.
        return Http::STATUS_UNPROCESSABLE_ENTITY;
    }//end httpStatusForOutcome()
}//end class
