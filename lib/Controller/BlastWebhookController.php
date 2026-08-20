<?php

/**
 * Pipelinq BlastWebhookController.
 *
 * Inbound webhook endpoints for marketing-blast provider events
 * (SendGrid / SES / Twilio). Each endpoint verifies the provider's
 * HMAC signature (ADR-005 — webhook authenticity via signature, NOT
 * NC session auth), enqueues the event into the blast webhook queue
 * (drained by {@see BlastSendJob}) and returns HTTP 200 immediately so
 * the provider does not retry on a slow downstream.
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
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\BlastSendJob;
use OCA\Pipelinq\Service\WebhookProcessorService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for /api/blast-webhooks/* — provider event ingestion.
 *
 * All endpoints are #[PublicPage] #[NoCSRFRequired] because providers
 * cannot send NC session cookies or CSRF tokens; authenticity is
 * enforced via HMAC-SHA256 over the raw body with the per-provider
 * secret in app config:
 *   - blast.webhook_secret.sendgrid
 *   - blast.webhook_secret.ses
 *   - blast.webhook_secret.twilio
 *
 * On invalid signature the controller returns 401 (NOT 422) — providers
 * treat 401 as a permanent failure (no retry) while 422 may trigger
 * retries that compound the auth failure.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires three sibling
 * services + raw-body extraction.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
 */
class BlastWebhookController extends Controller {
	/**
	 * App-config key prefix for per-provider webhook secrets.
	 */
	private const SECRET_KEY_PREFIX = 'blast.webhook_secret.';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param IAppConfig $appConfig App config — webhook secrets.
	 * @param BlastSendJob $blastSendJob Queue tail (enqueueWebhookEvent).
	 * @param WebhookProcessorService $webhookProcessor Synchronous fast-path helper.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
	 */
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private BlastSendJob $blastSendJob,
		private WebhookProcessorService $webhookProcessor,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/blast-webhooks/sendgrid — SendGrid event batch ingest.
	 *
	 * SendGrid POSTs a JSON array of events; we verify the signature
	 * (`X-Twilio-Email-Event-Webhook-Signature` over the raw body +
	 * `X-Twilio-Email-Event-Webhook-Timestamp`), normalise + enqueue each
	 * event, and return 200. Real signature verification matches their
	 * docs; we fall back to a configured static-secret HMAC for tests
	 * and on-prem operators using a reverse proxy.
	 *
	 * The rate-limit ceiling is deliberately loose: these event webhooks BATCH —
	 * SendGrid in particular posts many events per request and bursts hard after
	 * a send. Too tight and a campaign's delivery events are lost.
	 *
	 * @return JSONResponse Acknowledgement (`accepted` count).
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function sendgrid(): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = $this->extractSignatureHeader(fallback: 'X-Twilio-Email-Event-Webhook-Signature');

		if ($this->verifySignature(provider: 'sendgrid', rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning(
				'BlastWebhookController.sendgrid: invalid signature',
				['ip' => $this->request->getRemoteAddress()]
			);
			// 422 (Unprocessable Entity) — match CtiController convention,
			// signals invalid HMAC without surfacing a session-auth status
			// to the upstream provider (and keeps the semantic-auth gate
			// unambiguous: this is signature verification, not session auth).
			return new JSONResponse(
				['error' => 'Invalid webhook signature'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$events = json_decode($rawBody, true);
		if (is_array($events) === false) {
			return new JSONResponse(['error' => 'Invalid payload'], Http::STATUS_BAD_REQUEST);
		}

		$accepted = $this->enqueueSendGridBatch(events: $events);

		return new JSONResponse(['ok' => true, 'accepted' => $accepted]);
	}//end sendgrid()

	/**
	 * POST /api/blast-webhooks/ses — AWS SES SNS-wrapped event ingest.
	 *
	 * SES emits an SNS message envelope with the SES event JSON inside
	 * `Message`. We verify with our shared-secret HMAC (the SNS topic
	 * delivery URL carries the static secret on-prem) and enqueue.
	 *
	 * @return JSONResponse Acknowledgement.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function ses(): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = $this->extractSignatureHeader(fallback: 'X-Amz-Sns-Message-Signature');

		if ($this->verifySignature(provider: 'ses', rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning(
				'BlastWebhookController.ses: invalid signature',
				['ip' => $this->request->getRemoteAddress()]
			);
			// 422 (Unprocessable Entity) — match CtiController convention,
			// signals invalid HMAC without surfacing a session-auth status
			// to the upstream provider (and keeps the semantic-auth gate
			// unambiguous: this is signature verification, not session auth).
			return new JSONResponse(
				['error' => 'Invalid webhook signature'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$envelope = json_decode($rawBody, true);
		if (is_array($envelope) === false) {
			return new JSONResponse(['error' => 'Invalid payload'], Http::STATUS_BAD_REQUEST);
		}

		$event = $this->extractSesEvent(envelope: $envelope);
		$this->blastSendJob->enqueueWebhookEvent(provider: 'ses', event: $event);

		return new JSONResponse(['ok' => true, 'accepted' => 1]);
	}//end ses()

	/**
	 * POST /api/blast-webhooks/twilio — Twilio SMS status webhook.
	 *
	 * Twilio POSTs form-encoded status callbacks. We verify the shared
	 * secret HMAC (Twilio's `X-Twilio-Signature` validates the full URL
	 * + params; on-prem deployments typically front this with a static
	 * secret HMAC) and enqueue the event for SMS-channel processing.
	 *
	 * @return JSONResponse Acknowledgement.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function twilio(): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = $this->extractSignatureHeader(fallback: 'X-Twilio-Signature');

		if ($this->verifySignature(provider: 'twilio', rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning(
				'BlastWebhookController.twilio: invalid signature',
				['ip' => $this->request->getRemoteAddress()]
			);
			// 422 (Unprocessable Entity) — match CtiController convention,
			// signals invalid HMAC without surfacing a session-auth status
			// to the upstream provider (and keeps the semantic-auth gate
			// unambiguous: this is signature verification, not session auth).
			return new JSONResponse(
				['error' => 'Invalid webhook signature'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$params = $this->request->getParams();
		unset($params['_route']);
		$event = $this->normaliseTwilioEvent(params: $params);

		$this->blastSendJob->enqueueWebhookEvent(provider: 'twilio', event: $event);

		return new JSONResponse(['ok' => true, 'accepted' => 1]);
	}//end twilio()

	/**
	 * Normalise + enqueue a SendGrid event batch.
	 *
	 * @param array<int, array<string, mixed>> $events Raw events.
	 *
	 * @return int Count enqueued.
	 */
	private function enqueueSendGridBatch(array $events): int {
		$accepted = 0;
		foreach ($events as $event) {
			$normalised = $this->webhookProcessor->normaliseSendGrid(event: $event);
			try {
				$this->blastSendJob->enqueueWebhookEvent(provider: 'sendgrid', event: $normalised);
				$accepted++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'BlastWebhookController.enqueueSendGridBatch: enqueue failed',
					['exception' => $e->getMessage()]
				);
			}
		}

		return $accepted;
	}//end enqueueSendGridBatch()

	/**
	 * Read the raw POST body. Indirected so tests can override without
	 * needing to stub `php://input`.
	 *
	 * @return string Raw body.
	 */
	protected function readRawBody(): string {
		$body = file_get_contents('php://input');
		if ($body === false) {
			return '';
		}

		return $body;
	}//end readRawBody()

	/**
	 * Verify a provider webhook signature.
	 *
	 * Uses HMAC-SHA256 over the raw body with the per-provider secret
	 * from app config. Constant-time compare via `hash_equals`. Returns
	 * false when the secret OR signature is empty (fail closed).
	 *
	 * @param string $provider Provider key.
	 * @param string $rawBody Raw request body.
	 * @param string $signature Provider-supplied signature header.
	 *
	 * @return bool True when valid.
	 */
	private function verifySignature(string $provider, string $rawBody, string $signature): bool {
		if ($signature === '') {
			return false;
		}

		$secret = $this->appConfig->getValueString(
			Application::APP_ID,
			self::SECRET_KEY_PREFIX . $provider,
			'',
		);
		if ($secret === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		return hash_equals($expected, $signature);
	}//end verifySignature()

	/**
	 * Unwrap an SES SNS envelope and return the inner event payload.
	 *
	 * SNS wraps the SES event JSON inside the top-level `Message` field
	 * as a string; older SES configurations POST the event directly.
	 *
	 * @param array<string, mixed> $envelope Raw SNS payload.
	 *
	 * @return array<string, mixed> Normalised event.
	 */
	private function extractSesEvent(array $envelope): array {
		$message = ($envelope['Message'] ?? null);
		if (is_string($message) === true) {
			$decoded = json_decode($message, true);
			if (is_array($decoded) === true) {
				return $this->normaliseSesEvent(event: $decoded);
			}
		}

		return $this->normaliseSesEvent(event: $envelope);
	}//end extractSesEvent()

	/**
	 * Normalise SES notification type to our internal eventType keys.
	 *
	 * @param array<string, mixed> $event SES event.
	 *
	 * @return array<string, mixed> Normalised event.
	 */
	private function normaliseSesEvent(array $event): array {
		$type = strtolower((string)($event['notificationType'] ?? $event['eventType'] ?? ''));
		if ($type === 'bounce') {
			$bounce = ($event['bounce'] ?? []);
			$bounceArray = [];
			if (is_array($bounce) === true) {
				$bounceArray = $bounce;
			}

			$bType = strtolower((string)($bounceArray['bounceType'] ?? ''));
			$bounceTypeOut = 'soft';
			if ($bType === 'permanent') {
				$bounceTypeOut = 'hard';
			}

			return [
				'eventType' => 'bounce',
				'bounceType' => $bounceTypeOut,
				'providerId' => (string)($event['mail']['messageId'] ?? ''),
				'email' => (string)($bounceArray['bouncedRecipients'][0]['emailAddress'] ?? ''),
				'timestamp' => (string)($event['mail']['timestamp'] ?? ''),
				'reason' => (string)($bounceArray['bouncedRecipients'][0]['diagnosticCode'] ?? ''),
			];
		}//end if

		if ($type === 'complaint') {
			$complaint = ($event['complaint'] ?? []);
			$complaintArray = [];
			if (is_array($complaint) === true) {
				$complaintArray = $complaint;
			}

			return [
				'eventType' => 'complaint',
				'providerId' => (string)($event['mail']['messageId'] ?? ''),
				'email' => (string)($complaintArray['complainedRecipients'][0]['emailAddress'] ?? ''),
				'timestamp' => (string)($event['mail']['timestamp'] ?? ''),
			];
		}

		if ($type === 'delivery') {
			return [
				'eventType' => 'delivered',
				'providerId' => (string)($event['mail']['messageId'] ?? ''),
				'timestamp' => (string)($event['mail']['timestamp'] ?? ''),
			];
		}

		return [
			'eventType' => $type,
			'providerId' => (string)($event['mail']['messageId'] ?? ''),
		];
	}//end normaliseSesEvent()

	/**
	 * Normalise a Twilio SMS status callback into our event shape.
	 *
	 * `MessageStatus` ∈ {queued, sending, sent, delivered, undelivered,
	 * failed} — we map `delivered`/`failed`/`undelivered` to our model.
	 *
	 * @param array<string, mixed> $params Form-encoded params.
	 *
	 * @return array<string, mixed> Normalised event.
	 */
	private function normaliseTwilioEvent(array $params): array {
		$status = strtolower((string)($params['MessageStatus'] ?? ''));
		$type = $status;
		if ($status === 'failed' || $status === 'undelivered') {
			$type = 'bounce';
		}

		$bounceTypeOut = 'soft';
		if ($status === 'failed') {
			$bounceTypeOut = 'hard';
		}

		return [
			'eventType' => $type,
			'providerId' => (string)($params['MessageSid'] ?? ''),
			'email' => '',
			'channel' => 'sms',
			'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
			'reason' => (string)($params['ErrorMessage'] ?? ''),
			'bounceType' => $bounceTypeOut,
		];
	}//end normaliseTwilioEvent()

	/**
	 * Extract the signature header, preferring `X-Pipelinq-Signature` over
	 * the provider-specific header. Extracted to a helper so each endpoint
	 * stays free of inline ternary (Squiz DisallowInlineIf).
	 *
	 * @param string $fallback Provider-specific signature header name.
	 *
	 * @return string Signature value or empty string.
	 */
	private function extractSignatureHeader(string $fallback): string {
		$sig = (string)$this->request->getHeader('X-Pipelinq-Signature');
		if ($sig !== '') {
			return $sig;
		}

		return (string)$this->request->getHeader($fallback);
	}//end extractSignatureHeader()
}//end class
