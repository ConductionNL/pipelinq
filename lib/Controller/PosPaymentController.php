<?php

/**
 * Pipelinq PosPaymentController.
 *
 * Thin controller for POS payment lifecycle (initiate / capture / refund),
 * provider configuration (list, update, test), and the inbound webhook
 * endpoint. Per ADR-005 the webhook route is #[PublicPage] + #[NoCSRFRequired]
 * because payment providers cannot present a Nextcloud session cookie or CSRF
 * token; authenticity is enforced via provider-specific HMAC signatures
 * inside PosPaymentService::handleWebhook(). Invalid signatures map to
 * STATUS_BAD_REQUEST per gate-9 cleanup convention.
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
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosPaymentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * POS payment controller — provider config + payment lifecycle + webhook.
 *
 * Authorization model: provider configuration endpoints (list / update /
 * test) are admin-only (NC SecurityMiddleware default: methods without
 * #[NoAdminRequired] / #[PublicPage] are admin-only). Payment lifecycle
 * endpoints (initiate / capture / refund) are #[NoAdminRequired] — any
 * authenticated user can hit them, the service enforces transaction
 * ownership through OR's lifecycle guards on saveObject and the refund
 * manager-permission check in PosPaymentService::refundPayment(). The
 * webhook is #[PublicPage] — HMAC signature is the authenticity boundary.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the collaborators a
 *  thin payment controller legitimately needs (service + session + l10n +
 *  request + logger); splitting them would add indirection.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
 */
class PosPaymentController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PosPaymentService $service The payment service.
	 * @param IUserSession $session The user session.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private PosPaymentService $service,
		private IUserSession $session,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	// ---------------------------------------------------------------------
	// Provider configuration (admin only — NC SecurityMiddleware default).
	// ---------------------------------------------------------------------

	/**
	 * GET /api/payment-providers — list providers (credentials masked).
	 *
	 * @auth admin-only Lists payment-provider configuration for the instance; restricted to server administrators by the framework default.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function index(): JSONResponse {
		try {
			return new JSONResponse(['providers' => $this->service->listProviders()]);
		} catch (Throwable $e) {
			return $this->serverError(label: 'index', exception: $e);
		}
	}//end index()

	/**
	 * GET /api/payment-providers/{name} — single provider config.
	 *
	 * @param string $name The provider name.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only Reads one payment provider's configuration; restricted
	 *       to server administrators by the framework default.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function show(string $name): JSONResponse {
		try {
			return new JSONResponse(['provider' => $this->service->getProviderConfig(name: $name)]);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->serverError(label: 'show', exception: $e);
		}
	}//end show()

	/**
	 * PUT /api/payment-providers/{name} — update provider credentials + config.
	 *
	 * @param string $name The provider name.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only Writes payment-provider credentials for the instance;
	 *       restricted to server administrators by the framework default.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-002
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function update(string $name): JSONResponse {
		try {
			$data = $this->request->getParams();
			return new JSONResponse(['provider' => $this->service->updateProvider(name: $name, data: $data)]);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->serverError(label: 'update', exception: $e);
		}
	}//end update()

	/**
	 * POST /api/payment-providers/{name}/test — test provider connection.
	 *
	 * @param string $name The provider name.
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only Opens an outbound connection using stored provider
	 *       credentials; restricted to server administrators by the framework
	 *       default.
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
	 */
	public function test(string $name): JSONResponse {
		try {
			return new JSONResponse(['result' => $this->service->testConnection(name: $name)]);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->serverError(label: 'test', exception: $e);
		}
	}//end test()

	// ---------------------------------------------------------------------
	// Payment lifecycle (authenticated users).
	// ---------------------------------------------------------------------

	/**
	 * POST /api/pos-payments/{id}/initiate — initiate payment.
	 *
	 * @param string $id The transaction id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
	 */
	#[NoAdminRequired]
	public function initiate(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$providerName = $this->stringParam(name: 'providerName');
		$paymentMethod = $this->stringParam(name: 'paymentMethod');

		if ($providerName === '' || $paymentMethod === '') {
			return new JSONResponse(
				['error' => $this->l10n->t('Betaalmethode niet geconfigureerd')],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return $this->run(
			action: fn (): array => $this->service->initiatePayment(
				transactionId: $id,
				providerName: $providerName,
				paymentMethod: $paymentMethod
			),
			label: 'initiate'
		);
	}//end initiate()

	/**
	 * POST /api/pos-payments/{id}/capture — capture authorized payment.
	 *
	 * @param string $id The transaction id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
	 */
	#[NoAdminRequired]
	public function capture(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(
			action: fn (): array => $this->service->capturePayment(transactionId: $id),
			label: 'capture'
		);
	}//end capture()

	/**
	 * POST /api/pos-payments/{id}/refund — refund (manager only).
	 *
	 * @param string $id The transaction id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
	 */
	#[NoAdminRequired]
	public function refund(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$reason = $this->stringParam(name: 'reason');
		return $this->run(
			action: fn (): array => $this->service->refundPayment(
				transactionId: $id,
				reason: $reason,
				userId: $uid
			),
			label: 'refund'
		);
	}//end refund()

	// ---------------------------------------------------------------------
	// Webhook ingress — public + signature-validated.
	// ---------------------------------------------------------------------

	/**
	 * POST /api/pos-payment-webhook/{provider} — provider webhook.
	 *
	 * Signature failure → STATUS_BAD_REQUEST per gate-9 cleanup convention
	 * (the AppointmentPaymentWebhookController + BlastWebhookController
	 * already follow this rule).
	 *
	 * Status → HTTP mapping (see PosPaymentService::handleWebhook()):
	 *   ok | duplicate | ignored → 200 (the delivery is consumed or can never
	 *                                   be consumed, so a retry is pointless)
	 *   invalid                  → 400
	 *   unmatched                → 503, so the provider redelivers
	 *
	 * @param string $provider The provider name.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	// POS payment provider callback.
	#[AnonRateLimit(limit: 300, period: 60)]
	public function webhook(string $provider): JSONResponse {
		$rawBody = $this->readRawBody();
		$signature = $this->resolveSignatureHeader(provider: $provider);

		try {
			$result = $this->service->handleWebhook(
				providerName: $provider,
				rawPayload: $rawBody,
				signature: $signature
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PosPaymentController::webhook crashed',
				[
					'provider' => $provider,
					'class' => get_class($e),
				]
			);
			// Still return 200 — providers will retry on 5xx and we never
			// want a transient error to block ingest. Forensics are in logs.
			return new JSONResponse(['status' => 'deferred']);
		}

		$status = (string)($result['status'] ?? '');
		if ($status === 'invalid') {
			return new JSONResponse(
				['error' => $this->l10n->t('Invalid webhook signature')],
				Http::STATUS_BAD_REQUEST
			);
		}

		if ($status === 'unmatched') {
			// A signed settlement this instance could not match to a
			// transaction. NOTHING was persisted, so the delivery must not be
			// acknowledged: all four supported providers (Mollie, CCV, Adyen,
			// Stripe) stop redelivering on any 2xx and redeliver on 5xx, so a
			// 200 here loses the settlement permanently (pipelinq#799).
			// 503 rather than 500 because the condition is genuinely transient
			// — a webhook can outrun the transaction write, and an
			// unconfigured register/schema is repaired by an operator, not by
			// the provider.
			return new JSONResponse(
				$result,
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse($result);
	}//end webhook()

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	/**
	 * Resolve the signature header for the given provider.
	 *
	 * Each provider uses a different header name; the controller normalises
	 * the lookup so the service stays provider-agnostic.
	 *
	 * @param string $provider The provider name.
	 *
	 * @return string
	 */
	private function resolveSignatureHeader(string $provider): string {
		return match ($provider) {
			'mollie' => (string)$this->request->getHeader('X-Mollie-Signature'),
			'ccv' => (string)$this->request->getHeader('X-CCV-Signature'),
			'adyen' => (string)$this->request->getHeader('X-Adyen-Signature'),
			'stripe' => (string)$this->request->getHeader('Stripe-Signature'),
			default => '',
		};
	}//end resolveSignatureHeader()

	/**
	 * Read the raw request body (with a test-suite fallback for IRequest::getParams).
	 *
	 * @return string
	 */
	private function readRawBody(): string {
		$body = file_get_contents('php://input');
		if (is_string($body) === true && $body !== '') {
			return $body;
		}

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
	 * Require an authenticated user — returns UID string or 401 JSONResponse.
	 *
	 * @return string|JSONResponse
	 */
	private function requireUserId(): string|JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		return $user->getUID();
	}//end requireUserId()

	/**
	 * Read a request param as a trimmed string.
	 *
	 * @param string $name The param name.
	 *
	 * @return string
	 */
	private function stringParam(string $name): string {
		$raw = $this->request->getParam($name, '');
		if (is_string($raw) === false) {
			return '';
		}

		return trim($raw);
	}//end stringParam()

	/**
	 * Run a service action with shared error mapping.
	 *
	 * @param callable $action The action.
	 * @param string $label The log label.
	 *
	 * @return JSONResponse
	 */
	private function run(callable $action, string $label): JSONResponse {
		try {
			return new JSONResponse($action());
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			return $this->serverError(label: $label, exception: $e);
		}//end try
	}//end run()

	/**
	 * Build a server-error response (with safe logging).
	 *
	 * @param string $label The log label.
	 * @param Throwable $exception The exception.
	 *
	 * @return JSONResponse
	 */
	private function serverError(string $label, Throwable $exception): JSONResponse {
		$this->logger->error(
			'PosPaymentController::' . $label . ' failed',
			['class' => get_class($exception)]
		);
		return new JSONResponse(
			['error' => $this->l10n->t('An unexpected error occurred')],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end serverError()
}//end class
