<?php

/**
 * Pipelinq AppointmentPaymentWebhookController.
 *
 * Inbound webhook endpoint openconnector hits to report the outcome of
 * an appointment-deposit payment session. The endpoint is #[PublicPage]
 * + #[NoCSRFRequired] because openconnector cannot present a Nextcloud
 * session cookie or CSRF token; authenticity is enforced via HMAC-SHA256
 * over the raw body using the per-instance shared secret stored at
 * {@see AppointmentDepositService::WEBHOOK_SECRET_KEY}.
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
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AppointmentDepositService;
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
 * Webhook controller for openconnector → pipelinq payment callbacks.
 *
 * The endpoint is intentionally minimal: verify the signature, decode
 * the payload, hand off to {@see AppointmentDepositService::handlePaymentCallback},
 * return 200. All business logic (state-machine transitions, email
 * dispatch) lives in the deposit service so the controller stays a thin
 * authentication boundary.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
class AppointmentPaymentWebhookController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IAppConfig $appConfig App configuration (webhook secret).
	 * @param AppointmentDepositService $deposit The deposit service (callback handler).
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private AppointmentDepositService $deposit,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * POST /api/appointment-payment-webhook — payment callback ingest.
	 *
	 * Body shape (set by openconnector):
	 *   {
	 *     "bookingId": "<uuid>",
	 *     "status":    "paid" | "failed" | "expired" | "cancelled",
	 *     "providerReference": "<provider-id>"
	 *   }
	 *
	 * The signature is sent in the `X-Pipelinq-Signature` header as a
	 * hex-encoded HMAC-SHA256 of the raw body using the shared secret.
	 *
	 * @return JSONResponse Acknowledgement (HTTP 200 + status), 422 on
	 *                      invalid signature, 400 on malformed payload.
	 *
	 * Inbound provider webhook: the caller is a payment provider retrying on its
	 * own schedule, authenticated by its own signature. The rate-limit ceiling is
	 * generous — dropping a payment notification is a worse failure than
	 * absorbing a burst, and it would land on the provider's side where we
	 * cannot see it.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 300, period: 60)]
	public function callback(): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = (string)$this->request->getHeader('X-Pipelinq-Signature');

		if ($this->verifySignature(rawBody: $rawBody, signature: $signature) === false) {
			$this->logger->warning(
				'AppointmentPaymentWebhookController: invalid signature',
				['ip' => $this->request->getRemoteAddress()]
			);
			// 422 — matches BlastWebhookController convention; signals
			// signature failure without surfacing session-auth status.
			return new JSONResponse(
				['error' => 'Invalid webhook signature'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$payload = json_decode($rawBody, true);
		if (is_array($payload) === false) {
			return new JSONResponse(
				['error' => 'Invalid payload'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$bookingId = trim((string)($payload['bookingId'] ?? ''));
		$status = trim((string)($payload['status'] ?? ''));
		if ($bookingId === '' || $status === '') {
			return new JSONResponse(
				['error' => 'bookingId and status are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$outcome = $this->deposit->handlePaymentCallback(
				bookingId: $bookingId,
				status: $status
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'AppointmentPaymentWebhookController: callback handler failed',
				['booking' => $bookingId]
			);
			// Return 200 anyway — openconnector retries on non-2xx and
			// we never want a transient error to spam the callback. The
			// 15-minute timeout job still releases the slot.
			return new JSONResponse(['ok' => true, 'outcome' => 'deferred']);
		}

		return new JSONResponse(['ok' => true, 'outcome' => $outcome]);
	}//end callback()

	/**
	 * Read the raw request body (form-encoded fallback to JSON-encoded
	 * params if php://input is empty in the test runner).
	 *
	 * @return string
	 */
	protected function readRawBody(): string {
		$body = file_get_contents('php://input');
		if (is_string($body) === true && $body !== '') {
			return $body;
		}

		// Fallback for the unit-test boundary (IRequest::getParams).
		$params = $this->request->getParams();
		if (is_array($params) === true && $params !== []) {
			$encoded = json_encode($params);
			if (is_string($encoded) === true) {
				return $encoded;
			}
		}

		return '';
	}//end readRawBody()

	/**
	 * Verify the X-Pipelinq-Signature header.
	 *
	 * Empty body OR empty configured secret → reject (fail-closed —
	 * ADR-005). The comparison uses `hash_equals` to avoid timing
	 * leaks.
	 *
	 * @param string $rawBody The raw request body.
	 * @param string $signature The hex-encoded HMAC-SHA256 from the header.
	 *
	 * @return bool
	 */
	private function verifySignature(string $rawBody, string $signature): bool {
		if ($rawBody === '' || $signature === '') {
			return false;
		}

		$secret = $this->appConfig->getValueString(
			Application::APP_ID,
			AppointmentDepositService::WEBHOOK_SECRET_KEY,
			''
		);
		if ($secret === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		return hash_equals($expected, $signature);
	}//end verifySignature()
}//end class
