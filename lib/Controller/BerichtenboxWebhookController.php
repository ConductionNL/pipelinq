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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-receipt-005
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-inbound-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\LogiusConnector;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Inbound webhook controller for Logius Berichtenbox events.
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
	 * @return JSONResponse 200 on success; 422 on signature failure;
	 *                      400 on malformed payload.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function readReceipt(): JSONResponse {
		$rawBody = $this->readRawBody();
		if ($this->logius->handleWebhookSignature($this->request, $rawBody) === false) {
			$this->logger->warning(
				'Berichtenbox readReceipt webhook: invalid signature.',
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
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function inboundReply(): JSONResponse {
		$rawBody = $this->readRawBody();
		if ($this->logius->handleWebhookSignature($this->request, $rawBody) === false) {
			$this->logger->warning(
				'Berichtenbox inboundReply webhook: invalid signature.',
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

		$parentMessageId = (string)($payload['parentMessageId'] ?? '');
		$logiusReplyId = (string)($payload['logiusReplyId'] ?? '');
		$bodyText = (string)($payload['bodyText'] ?? '');
		if ($parentMessageId === '' || $logiusReplyId === '' || $bodyText === '') {
			return new JSONResponse(
				['error' => 'parentMessageId, logiusReplyId and bodyText are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$attachments = ($payload['attachments'] ?? []);
		if (is_array($attachments) === false) {
			$attachments = [];
		}

		try {
			$reply = $this->berichtenbox->handleInboundReply(
				parentMessageId: $parentMessageId,
				logiusReplyId: $logiusReplyId,
				bodyText: $bodyText,
				attachments: $attachments
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Berichtenbox inboundReply handler failed.',
				['exception' => $e->getMessage(), 'parentMessageId' => $parentMessageId]
			);
			return new JSONResponse(
				['error' => 'Reply processing failed: ' . $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			[
				'ok' => true,
				'contactmomentId' => (string)($reply['createdContactmomentId'] ?? ''),
				'replyId' => (string)($reply['uuid'] ?? ''),
			]
		);
	}//end inboundReply()

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
