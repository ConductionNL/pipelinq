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

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ChannelProviderRepositoryInterface;
use OCA\Pipelinq\Kernel\Contracts\ConsentServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\MessagingServiceInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\Messaging\MessageContent;
use OCA\Pipelinq\Service\Messaging\MessageRoute;
use OCA\Pipelinq\Util\ReadsTypedRequestParameters;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Outbound messaging controller.
 *
 * Endpoints:
 *   POST /api/messaging/send                    NoAdminRequired + per-object guard
 *   POST /api/messaging/messages/{id}/resend    NoAdminRequired + per-object guard
 *   GET  /api/messaging/preflight/{contactId}   NoAdminRequired + per-object guard
 *   POST /api/messaging/consent                 NoAdminRequired + per-object guard
 *   POST /api/messaging/providers/{id}/test     AuthorizedAdminSetting
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thin HTTP facade over the
 *  send-surface services.
 *
 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
 */
class MessagingController extends Controller {
	use ReadsTypedRequestParameters;

	/**
	 * The request parameters send() reads: name => [type, default].
	 *
	 * @var array<string, array{0: string, 1: string|array<int, string>|null}>
	 */
	private const SEND_PARAMETERS = [
		'contactId' => ['string', ''],
		'channel' => ['string', ''],
		'body' => ['string', ''],
		'templateId' => ['?string', null],
		'parameters' => ['array', []],
		'providerHint' => ['?string', null],
		'clientId' => ['string', ''],
		'inReplyTo' => ['string', ''],
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request HTTP request.
	 * @param MessagingServiceInterface $messagingService Send-surface orchestration.
	 * @param ChannelProviderRepositoryInterface $providerRepo Provider read-side.
	 * @param ConsentServiceInterface $consentService Consent records.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 */
	public function __construct(
		IRequest $request,
		private MessagingServiceInterface $messagingService,
		private ChannelProviderRepositoryInterface $providerRepo,
		private ConsentServiceInterface $consentService,
		private CrmPrivilegeCheck $privilege,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Send an outbound SMS / WhatsApp message (REQ-OM-004).
	 *
	 * Request parameters: `contactId`, the contact/client UUID; `channel`,
	 * `sms` or `whatsapp`; `body`, free text (empty for a template send);
	 * `templateId`, an approved template UUID (whatsapp) or null;
	 * `parameters`, the positional template parameters (a list);
	 * `providerHint`, an optional pinned vendor; `clientId`, the linked
	 * client UUID (audit) or empty; `inReplyTo`, the contact's inbound
	 * message this answers, or empty. All but `parameters` are strings.
	 *
	 * @return Response The sanitised outcome envelope, or a bare 400 when a parameter has the wrong type.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-004-server-side-send-endpoint
	 */
	#[NoAdminRequired]
	public function send(): Response {
		$send = $this->typedRequestParameters(spec: self::SEND_PARAMETERS);
		if ($send === null) {
			return new Response(Http::STATUS_BAD_REQUEST);
		}

		// Messaging sends on the organisation's behalf and reads contact
		// consent state — a CRM capability, not an any-authenticated-user one.
		$refusal = $this->privilege->refusalWith(['status' => 'unauthorized'], ['status' => 'forbidden']);
		if ($refusal !== null) {
			return $refusal;
		}

		$uid = $this->privilege->actingUserId();

		if ($this->isMessagingChannel(channel: $send['channel']) === false) {
			return new JSONResponse(['status' => 'invalid-channel'], Http::STATUS_BAD_REQUEST);
		}

		// Per-object guard (no-admin-idor): the caller must be able to load
		// the contact via the register RBAC before any adapter is invoked.
		$contact = $this->messagingService->loadContact(contactId: $send['contactId']);
		if ($contact === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		return $this->dispatchSend(contact: $contact, send: $send, uid: $uid);
	}//end send()

	/**
	 * Send the message to the loaded contact and answer with the outcome.
	 *
	 * @param mixed $contact The loaded contact.
	 * @param array<string, mixed> $send The typed send parameters.
	 * @param string|null $uid The acting user id.
	 *
	 * @return JSONResponse The send outcome.
	 */
	private function dispatchSend($contact, array $send, $uid): JSONResponse {
		$outcome = $this->messagingService->send(
			route: new MessageRoute(contact: $contact, providerHint: $send['providerHint'], clientId: $send['clientId'], inReplyTo: $send['inReplyTo']),
			content: new MessageContent(channel: $send['channel'], body: $send['body'], templateId: $send['templateId'], parameters: $send['parameters']),
			actor: $uid
		);

		return new JSONResponse($outcome, $this->httpStatusForOutcome(status: (string)$outcome['status']));
	}//end dispatchSend()

	/**
	 * Send a failed or expired outbound message again (REQ-MSR-006).
	 *
	 * The message and its contact are loaded through the register RBAC, so a
	 * row the caller cannot read answers 404 before anything is sent. The
	 * resend runs through the normal send() with its consent, budget and
	 * session-window checks.
	 *
	 * @param string $id The channelMessage UUID.
	 *
	 * @return JSONResponse The sanitised outcome envelope; 409 on a refusal.
	 *
	 * @spec openspec/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
	 */
	#[NoAdminRequired]
	public function resend(string $id = ''): JSONResponse {
		$refusal = $this->privilege->refusalWith(['status' => 'unauthorized'], ['status' => 'forbidden']);
		if ($refusal !== null) {
			return $refusal;
		}

		$uid = $this->privilege->actingUserId();

		$message = $this->messagingService->loadMessage(messageId: $id);
		if ($message === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$refusal = $this->messagingService->resendRefusal(message: $message);
		if ($refusal !== null) {
			return new JSONResponse(['status' => $refusal], Http::STATUS_CONFLICT);
		}

		$contact = $this->messagingService->loadContact(contactId: (string)($message['contactId'] ?? ''));
		if ($contact === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$outcome = $this->messagingService->resend(message: $message, contact: $contact, actor: $uid);

		return new JSONResponse($outcome, $this->httpStatusForOutcome(status: (string)$outcome['status']));
	}//end resend()

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
	public function preflight(string $contactId = ''): JSONResponse {
		// Same posture as send()/consent(): preflight reads a contact's
		// messaging eligibility, which is CRM data.
		$refusal = $this->privilege->refusalWith(['status' => 'unauthorized'], ['status' => 'forbidden']);
		if ($refusal !== null) {
			return $refusal;
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
	 * @param string $contactId Contact UUID.
	 * @param string $channel `sms` or `whatsapp`.
	 * @param string $action `opt-in` or `opt-out`.
	 * @param string $source Source enum (manual / webform / ...).
	 * @param string $evidence Mandatory audit-trail evidence.
	 * @param string $legalBasis Mandatory GDPR legal basis.
	 *
	 * @return JSONResponse The recorded state.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One branch over, from the
	 * CRM guard. Recording consent writes a GDPR legal basis, so the guard is
	 * exactly where it belongs and the count is accepted.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Same early return; NPath counts
	 * paths multiplicatively, so one guard doubles it without adding depth.
	 */
	#[NoAdminRequired]
	public function consent(
		string $contactId = '',
		string $channel = '',
		string $action = '',
		string $source = 'manual',
		string $evidence = '',
		string $legalBasis = '',
	): JSONResponse {
		// Messaging sends on the organisation's behalf and reads contact
		// consent state — a CRM capability, not an any-authenticated-user one.
		$refusal = $this->privilege->refusalWith(['status' => 'unauthorized'], ['status' => 'forbidden']);
		if ($refusal !== null) {
			return $refusal;
		}

		$uid = $this->privilege->actingUserId();

		$invalid = $this->consentRequestError(channel: $channel, action: $action, evidence: $evidence, legalBasis: $legalBasis);
		if ($invalid !== null) {
			return $invalid;
		}

		// Per-object guard (no-admin-idor).
		$contact = $this->messagingService->loadContact(contactId: $contactId);
		if ($contact === null) {
			return new JSONResponse(['status' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$attributedEvidence = sprintf('%s (recorded by %s)', $evidence, $uid);
		$this->recordConsent(
			action: $action,
			contactId: $contactId,
			channel: $channel,
			source: $source,
			evidence: $attributedEvidence,
			legalBasis: $legalBasis
		);

		return new JSONResponse(['status' => 'recorded', 'state' => $this->consentService->latestState(contactId: $contactId, channel: $channel)]);
	}//end consent()

	/**
	 * The 400 response for an invalid consent request, or null when it is valid.
	 *
	 * @param string $channel The channel.
	 * @param string $action The consent action.
	 * @param string $evidence The evidence.
	 * @param string $legalBasis The legal basis.
	 *
	 * @return JSONResponse|null The error response, or null.
	 */
	private function consentRequestError(string $channel, string $action, string $evidence, string $legalBasis): JSONResponse|null {
		if ($this->isMessagingChannel(channel: $channel) === false) {
			return new JSONResponse(['status' => 'invalid-channel'], Http::STATUS_BAD_REQUEST);
		}

		if ($evidence === '' || $legalBasis === '') {
			return new JSONResponse(['status' => 'evidence-and-legal-basis-required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->isConsentAction(action: $action) === false) {
			return new JSONResponse(['status' => 'invalid-action'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end consentRequestError()

	/**
	 * Whether the action is `opt-in` or `opt-out`.
	 *
	 * @param string $action The consent action.
	 *
	 * @return bool True for a supported action.
	 */
	private function isConsentAction(string $action): bool {
		return ($action !== 'opt-in' && $action !== 'opt-out') === false;
	}//end isConsentAction()

	/**
	 * Whether the channel is one messaging supports (`sms` or `whatsapp`).
	 *
	 * @param string $channel The channel.
	 *
	 * @return bool True for a supported channel.
	 */
	private function isMessagingChannel(string $channel): bool {
		return ($channel !== 'sms' && $channel !== 'whatsapp') === false;
	}//end isMessagingChannel()

	/**
	 * Append the consent record for a validated opt-in / opt-out action.
	 *
	 * @param string $action `opt-in` or `opt-out` (already validated).
	 * @param string $contactId Contact UUID.
	 * @param string $channel `sms` or `whatsapp`.
	 * @param string $source Source enum.
	 * @param string $evidence Attributed audit evidence.
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
		string $legalBasis,
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
	#[AuthorizedAdminSetting(AppIdentity::APP_ID)]
	public function testProvider(string $id = ''): JSONResponse {
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
	private function httpStatusForOutcome(string $status): int {
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
