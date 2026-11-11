<?php

/**
 * Pipelinq BerichtenboxWebhookController.
 *
 * Webhook endpoints Logius hits to deliver (a) burger-read receipts and
 * (b) inbound replies. Both endpoints are #[PublicPage] + #[NoCSRFRequired]
 * because Logius cannot present a NC session cookie; authenticity is
 * enforced by HMAC-SHA256 over the raw body using the per-instance
 * Logius webhook secret stored in app config (REQ-RECEIPT-005,
 * REQ-INBOUND-006, REQ-BBK-010 webhook-signature).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-RECEIPT-005
 * @spec openspec/specs/berichtenbox/spec.md#REQ-INBOUND-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\LogiusConnector;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Inbound webhook controller for Logius Berichtenbox events.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class BerichtenboxWebhookController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Request.
	 * @param BerichtenboxService $berichtenbox Berichtenbox service.
	 * @param LogiusConnector $logius Logius connector (signature
	 *                                verification helper).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly BerichtenboxService $berichtenbox,
		private readonly LogiusConnector $logius,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/webhook/berichtenbox/read — Logius read-receipt webhook.
	 *
	 * Body shape:
	 *   {
	 *     "logiusMessageId": "<uuid>",
	 *     "readAt": "ISO 8601 timestamp"
	 *   }
	 *
	 * Berichtenbox delivery callbacks — the rate-limit ceiling takes the same
	 * posture as every other receiver here.
	 *
	 * @return JSONResponse 200 on success; 422 on signature failure;
	 *                      400 on malformed payload.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function readReceipt(): JSONResponse {
		$payload = $this->signedPayload(webhook: 'readReceipt');
		if ($payload instanceof JSONResponse) {
			return $payload;
		}

		$logiusMessageId = (string)($payload['logiusMessageId'] ?? '');
		$readAt = (string)($payload['readAt'] ?? '');
		if ($logiusMessageId === '' || $readAt === '') {
			return new JSONResponse(
				['error' => 'logiusMessageId and readAt are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$updated = $this->berichtenbox->handleReadReceipt($logiusMessageId, $readAt);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Berichtenbox readReceipt handler failed.',
				['exception' => $e->getMessage(), 'logiusMessageId' => $logiusMessageId]
			);
			return new JSONResponse(['ok' => false, 'deferred' => true]);
		}

		return new JSONResponse(['ok' => true, 'updated' => $updated]);
	}//end readReceipt()

	/**
	 * POST /api/webhook/berichtenbox/reply — Logius inbound-reply webhook.
	 *
	 * Body shape:
	 *   {
	 *     "parentMessageId": "<uuid>",
	 *     "logiusReplyId":  "<uuid>",
	 *     "bodyText": "...",
	 *     "attachments": [ {filename, mime, sizeBytes, contentBase64}, ... ]
	 *   }
	 *
	 * @return JSONResponse 200 with the created contactmomentId; 422 on
	 *                      signature failure; 400 on malformed payload.
	 * @spec openspec/specs/berichtenbox/spec.md#REQ-RECEIPT-005
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function inboundReply(): JSONResponse {
		$payload = $this->signedPayload(webhook: 'inboundReply');
		if ($payload instanceof JSONResponse) {
			return $payload;
		}

		$reply = self::replyFields(payload: $payload);
		if ($reply === null) {
			return new JSONResponse(
				['error' => 'parentMessageId, logiusReplyId and bodyText are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->handleReply(reply: $reply);
	}//end inboundReply()

	/**
	 * The decoded body of a webhook call that carries a valid Logius signature.
	 *
	 * @param string $webhook The webhook name, for the refusal log.
	 *
	 * @return array<mixed>|JSONResponse The payload, or the 422 (unsigned) / 400 (not an object) refusal.
	 */
	private function signedPayload(string $webhook): array|JSONResponse {
		$rawBody = $this->readRawBody();
		if ($this->logius->handleWebhookSignature($this->request, $rawBody) === false) {
			$this->logger->warning(
				'Berichtenbox ' . $webhook . ' webhook: invalid signature.',
				['ip' => $this->request->getRemoteAddress()]
			);
			return new JSONResponse(
				['error' => 'Invalid webhook signature'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$payload = json_decode($rawBody, true);
		if (is_array($payload) === false) {
			return new JSONResponse(['error' => 'Invalid payload'], Http::STATUS_BAD_REQUEST);
		}

		return $payload;
	}//end signedPayload()

	/**
	 * The fields of an inbound reply; attachments that are not a list become none.
	 *
	 * @param array<mixed> $payload The decoded body.
	 *
	 * @return array{parentMessageId: string, logiusReplyId: string, bodyText: string, attachments: array<mixed>}|null
	 *         The fields, or null when a required one is missing.
	 */
	private static function replyFields(array $payload): ?array {
		$reply = [
			'parentMessageId' => (string)($payload['parentMessageId'] ?? ''),
			'logiusReplyId' => (string)($payload['logiusReplyId'] ?? ''),
			'bodyText' => (string)($payload['bodyText'] ?? ''),
			'attachments' => ($payload['attachments'] ?? []),
		];
		if (in_array('', [$reply['parentMessageId'], $reply['logiusReplyId'], $reply['bodyText']], true) === true) {
			return null;
		}

		if (is_array($reply['attachments']) === false) {
			$reply['attachments'] = [];
		}

		return $reply;
	}//end replyFields()

	/**
	 * Hand an inbound reply to the Berichtenbox service; a failure is logged and refused with its reason.
	 *
	 * @param array{parentMessageId: string, logiusReplyId: string, bodyText: string, attachments: array<mixed>} $reply The reply fields.
	 *
	 * @return JSONResponse The created contact moment and reply ids, or the 400.
	 */
	private function handleReply(array $reply): JSONResponse {
		try {
			$created = $this->berichtenbox->handleInboundReply(
				parentMessageId: $reply['parentMessageId'],
				logiusReplyId: $reply['logiusReplyId'],
				bodyText: $reply['bodyText'],
				attachments: $reply['attachments']
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Berichtenbox inboundReply handler failed.',
				['exception' => $e->getMessage(), 'parentMessageId' => $reply['parentMessageId']]
			);
			return new JSONResponse(
				['error' => 'Reply processing failed: ' . $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			[
				'ok' => true,
				'interactionId' => (string)($created['createdInteractionId'] ?? ''),
				'replyId' => (string)($created['uuid'] ?? ''),
			]
		);
	}//end handleReply()

	/**
	 * Read the raw request body.
	 *
	 * @return string
	 */
	protected function readRawBody(): string {
		$body = file_get_contents('php://input');
		if ($body !== false && $body !== '') {
			return $body;
		}

		// Test-runner / form-encoded fallback.
		$params = $this->request->getParams();
		$encoded = json_encode($params);
		if ($encoded === false) {
			return '';
		}

		return $encoded;
	}//end readRawBody()
}//end class
