<?php

/**
 * Pipelinq PosCustomerController.
 *
 * Thin controller for the POS customer-link surface (search + attach +
 * detach + history + consent). All business logic + authorization lives
 * in PosCustomerLinkService; this controller only marshals input/output
 * and maps OCS exceptions to HTTP status codes.
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
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-002
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosCustomerLinkService;
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
 * Controller for POS customer-link endpoints.
 *
 * All endpoints require an authenticated user. The service performs the
 * authoritative lookup, mutation and consent sync; the controller does
 * nothing more than translate JSON / query params into method arguments
 * and OCS exceptions into HTTP status codes (404 / 422 / 403 / 500).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Aggregates the standard
 *  Nextcloud controller collaborators (request, session, l10n, logger) plus
 *  the single service it delegates to.
 *
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
 */
class PosCustomerController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PosCustomerLinkService $service The POS customer-link service.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private PosCustomerLinkService $service,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Search pipelinq contacts as POS customers.
	 *
	 * `GET /api/pos-customers/search?query={q}&limit={n}` — returns a
	 * decorated contact list (id, name, email, phone, doNotContact,
	 * marketingConsent). Query must be ≥ 2 chars; limit is capped at 100.
	 *
	 * @return JSONResponse The list of decorated customers.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
	 */
	#[NoAdminRequired]
	public function search(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$query = (string)$this->request->getParam('query', '');
		$limit = (int)$this->request->getParam('limit', (string)PosCustomerLinkService::DEFAULT_SEARCH_LIMIT);

		return $this->run(
			action: fn (): array => $this->service->searchCustomers(query: $query, limit: $limit),
			label: 'search',
			key: 'customers'
		);
	}//end search()

	/**
	 * Attach a pipelinq contact (customer) to a draft / parked transaction
	 * and optionally capture marketing consent.
	 *
	 * `POST /api/pos-transactions/{id}/customer` — JSON body:
	 *   { "customer": "<uuid>", "marketingConsent": true|false }
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return JSONResponse The updated transaction.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-002
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-004
	 */
	#[NoAdminRequired]
	public function attach(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$contact = (string)$this->request->getParam('customer', '');
		$consent = $this->parseBool(value: $this->request->getParam('marketingConsent'));

		if ($contact === '') {
			return new JSONResponse(
				['error' => $this->l10n->t('customer UUID is required')],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		return $this->run(
			action: fn (): array => $this->service->attachCustomer(
				transactionId: $id,
				contactUuid: $contact,
				marketingConsent: $consent
			),
			label: 'attachCustomer'
		);
	}//end attach()

	/**
	 * Detach the customer from a draft / parked transaction.
	 *
	 * `DELETE /api/pos-transactions/{id}/customer`.
	 *
	 * @param string $id The transaction UUID.
	 *
	 * @return JSONResponse The updated transaction.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-002
	 */
	#[NoAdminRequired]
	public function detach(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(
			action: fn (): array => $this->service->detachCustomer(transactionId: $id),
			label: 'detachCustomer'
		);
	}//end detach()

	/**
	 * Fetch the purchase history for a customer.
	 *
	 * `GET /api/pos-customers/{id}/history?limit={n}` — returns the most
	 * recent confirmed / settled / refunded transactions for the customer
	 * (admin-configurable depth, capped at 50).
	 *
	 * @param string $id The customer (contact) UUID.
	 *
	 * @return JSONResponse The history rows.
	 *
	 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-003
	 */
	#[NoAdminRequired]
	public function history(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$limit = (int)$this->request->getParam('limit', '0');

		return $this->run(
			action: fn (): array => $this->service->getCustomerHistory(contactUuid: $id, limit: $limit),
			label: 'history',
			key: 'history'
		);
	}//end history()

	/**
	 * Require an authenticated user; return the UID or a 401 response.
	 *
	 * @return string|JSONResponse The user UID, or a 401 response.
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
	 * Parse a request param into a strict bool (false on null / empty).
	 *
	 * @param mixed $value The raw param value.
	 *
	 * @return bool The parsed boolean.
	 */
	private function parseBool(mixed $value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		if (is_string($value) === true) {
			return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
		}

		return ((int)$value) === 1;
	}//end parseBool()

	/**
	 * Run a service action with shared OCS → HTTP error mapping.
	 *
	 * @param callable $action The action to run.
	 * @param string $label A short label for log context.
	 * @param string $key The response envelope key.
	 *
	 * @return JSONResponse The response.
	 */
	private function run(callable $action, string $label, string $key = 'transaction'): JSONResponse {
		try {
			return new JSONResponse([$key => $action()]);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('PosCustomerController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end run()
}//end class
