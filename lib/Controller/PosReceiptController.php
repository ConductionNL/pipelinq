<?php

/**
 * Pipelinq PosReceiptController.
 *
 * Thin controller for POS receipt operations (preview, email, thermal print).
 * All loading, authorization scoping, invoice numbering and audit logging live
 * in ReceiptDeliveryService.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ReceiptDeliveryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for POS receipt endpoints.
 *
 * Authorization model: every action requires an authenticated user AND
 * per-object access — ReceiptDeliveryService asserts the caller is the
 * transaction's own cashier, a POS-group member, or an admin before rendering
 * (closing the IDOR where any authenticated user could preview/email/print any
 * transaction by UUID). Email recipients are additionally constrained to the
 * transaction's linked customer, so the endpoint cannot be abused to send mail
 * to arbitrary addresses.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/pos-receipt-engine/spec.md
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#4.1
 */
class PosReceiptController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ReceiptDeliveryService $service The receipt delivery service.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private ReceiptDeliveryService $service,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Render a receipt preview for a transaction (no side effects).
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return JSONResponse The preview payload.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	#[NoAdminRequired]
	public function preview(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$templateId = $this->optionalString(name: 'template');

		return $this->run(
			action: fn (): array => $this->service->preview(
				transactionId: $id,
				templateId: $templateId,
				userId: $uid
			),
			label: 'preview',
			key: 'receipt'
		);
	}//end preview()

	/**
	 * Email a receipt to the transaction's linked customer.
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return JSONResponse The email result.
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	#[NoAdminRequired]
	public function email(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$templateId = $this->optionalString(name: 'template');
		$recipient = $this->optionalString(name: 'recipient');

		return $this->run(
			action: fn (): array => $this->service->emailReceipt(
				transactionId: $id,
				templateId: $templateId,
				requestedRecipient: $recipient,
				userId: $uid
			),
			label: 'email',
			key: 'receipt'
		);
	}//end email()

	/**
	 * Produce the ESC/POS thermal byte stream for a transaction.
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return JSONResponse The print result (base64 ESC/POS bytes + log id).
	 *
	 * @spec openspec/specs/pos-receipt-engine/spec.md
	 */
	#[NoAdminRequired]
	public function print(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$templateId = $this->optionalString(name: 'template');

		return $this->run(
			action: fn (): array => $this->service->printReceipt(
				transactionId: $id,
				templateId: $templateId,
				userId: $uid
			),
			label: 'print',
			key: 'receipt'
		);
	}//end print()

	/**
	 * Read an optional string request param, returning null when absent/empty.
	 *
	 * @param string $name The param name.
	 *
	 * @return string|null The trimmed value or null.
	 */
	private function optionalString(string $name): ?string {
		$raw = (string)$this->request->getParam($name, '');
		if (trim($raw) === '') {
			return null;
		}

		return $raw;
	}//end optionalString()

	/**
	 * Require an authenticated user, returning their UID.
	 *
	 * @return string|JSONResponse The acting user UID, or a 401 response.
	 */
	private function requireUserId(): string|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		return $user->getUID();
	}//end requireUserId()

	/**
	 * Run an action with shared OCS-exception to HTTP-status mapping.
	 *
	 * @param callable $action The action to run.
	 * @param string $label A short label for log context.
	 * @param string $key The response envelope key.
	 *
	 * @return JSONResponse The response.
	 */
	private function run(callable $action, string $label, string $key): JSONResponse {
		try {
			return new JSONResponse([$key => $action()]);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('PosReceiptController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end run()
}//end class
