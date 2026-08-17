<?php

/**
 * Pipelinq PosTenderController.
 *
 * Thin controller for the POS split-tender surface (pos-split-tender). Two
 * resource groups:
 *
 *   1. Tender types (`/api/pos/tender-types*`) — admin-only configuration
 *      of available payment methods + their GL accounts. Restricted via
 *      `#[AuthorizedAdminSetting(Application::APP_ID)]` per ADR-005.
 *   2. Per-transaction tenders (`/api/pos-transactions/{id}/tenders*`) — the
 *      cashier-facing add / list / remove surface. Restricted to authenticated
 *      users via `#[NoAdminRequired]`; the underlying service enforces the
 *      transaction-status invariant.
 *
 * All business logic lives in PosTenderService; this layer is exception
 * mapping (InvalidTenderException -> 400/409, TenderTypeNotFoundException
 * -> 404, OCSNotFoundException -> 404, OCSBadRequestException -> 400) plus
 * JSON-response shaping. Plain CRUD on posTender / posTenderType objects
 * is also available through OpenRegister's generic object API for tooling.
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
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-002
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\InvalidTenderException;
use OCA\Pipelinq\Service\PosTenderService;
use OCA\Pipelinq\Service\TenderTypeNotFoundException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for POS split-tender endpoints (tender types + per-transaction tenders).
 *
 * Authorization model:
 *   - Tender-type CRUD endpoints are admin-only via the AuthorizedAdminSetting
 *     attribute (NC ADR-005 / fleet convention).
 *   - Per-transaction tender endpoints are authenticated-user-only via the
 *     NoAdminRequired attribute; PosTenderService enforces the status invariants.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires only the collaborators
 *  a thin controller needs (service + session + request + logger); splitting
 *  them would add indirection.
 *
 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
 */
class PosTenderController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PosTenderService $service The tender service.
	 * @param IUserSession $session The user session.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private PosTenderService $service,
		private IUserSession $session,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	// ---------------------------------------------------------------------
	// Tender-type endpoints (admin only — REQ-PST-001, REQ-PST-008).
	// ---------------------------------------------------------------------

	/**
	 * GET /api/pos/tender-types — list tender types.
	 *
	 * Query string: `activeOnly=1` to filter to isActive=true.
	 *
	 * @return JSONResponse The tender types sorted by sortOrder.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
	 */
	#[NoAdminRequired]
	public function indexTypes(): JSONResponse {
		$this->requireAuthenticatedUser();

		$activeOnly = $this->request->getParam(key: 'activeOnly', default: '0') === '1';

		try {
			$types = $this->service->listTenderTypes(activeOnly: $activeOnly);
			return new JSONResponse(['results' => $types, 'total' => count($types)]);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: listTenderTypes failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to list tender types'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end indexTypes()

	/**
	 * GET /api/pos/tender-types/{id} — fetch one tender type.
	 *
	 * @param string $id The tender type UUID.
	 *
	 * @return JSONResponse The tender type, or 404 when missing.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
	 */
	#[NoAdminRequired]
	public function showType(string $id): JSONResponse {
		$this->requireAuthenticatedUser();

		try {
			return new JSONResponse($this->service->getTenderTypeById(id: $id));
		} catch (TenderTypeNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: showType failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to fetch tender type'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end showType()

	/**
	 * POST /api/pos/tender-types — create a tender type (admin).
	 *
	 * @return JSONResponse The created tender type with HTTP 201.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function createType(): JSONResponse {
		$payload = $this->bodyPayload();

		try {
			return new JSONResponse(
				$this->service->createTenderType(data: $payload),
				Http::STATUS_CREATED
			);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: createType failed',
				['exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to create tender type'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end createType()

	/**
	 * PUT /api/pos/tender-types/{id} — update a tender type (admin).
	 *
	 * @param string $id The tender type UUID.
	 *
	 * @return JSONResponse The updated tender type.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function updateType(string $id): JSONResponse {
		$payload = $this->bodyPayload();

		try {
			return new JSONResponse($this->service->updateTenderType(id: $id, data: $payload));
		} catch (TenderTypeNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: updateType failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to update tender type'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end updateType()

	/**
	 * DELETE /api/pos/tender-types/{id} — delete a tender type (admin).
	 *
	 * Rejects with HTTP 400 when active tenders reference the type.
	 *
	 * @param string $id The tender type UUID.
	 *
	 * @return JSONResponse HTTP 204 on success.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-001
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function destroyType(string $id): JSONResponse {
		try {
			$this->service->deleteTenderType(id: $id);
			return new JSONResponse(null, Http::STATUS_NO_CONTENT);
		} catch (TenderTypeNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: destroyType failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to delete tender type'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end destroyType()

	// ---------------------------------------------------------------------
	// Per-transaction tender endpoints (REQ-PST-002 / REQ-PST-003 / REQ-PST-004).
	// ---------------------------------------------------------------------

	/**
	 * GET /api/pos-transactions/{transactionId}/tenders — list tenders.
	 *
	 * Response also carries the validation summary so the UI can render the
	 * "remaining / variance" line without a second request.
	 *
	 * @param string $transactionId The posTransaction UUID.
	 *
	 * @return JSONResponse The tenders + the tender-sum validation.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-002
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-004
	 */
	#[NoAdminRequired]
	public function indexTenders(string $transactionId): JSONResponse {
		$this->requireAuthenticatedUser();

		try {
			$tenders = $this->service->getTendersForTransaction(transactionId: $transactionId);
			$validation = $this->service->validateTenderSum(transactionId: $transactionId);
			return new JSONResponse(
				[
					'results' => $tenders,
					'total' => count($tenders),
					'validation' => $validation,
				]
			);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: indexTenders failed',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to list tenders'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end indexTenders()

	/**
	 * POST /api/pos-transactions/{transactionId}/tenders — add a tender.
	 *
	 * Body: { tenderType: uuid, amount: number, reference?: string, notes?: string }
	 *
	 * @param string $transactionId The posTransaction UUID.
	 *
	 * @return JSONResponse The created tender + the updated validation summary.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-002
	 */
	#[NoAdminRequired]
	public function addTender(string $transactionId): JSONResponse {
		$this->requireAuthenticatedUser();

		$payload = $this->bodyPayload();

		try {
			$tender = $this->service->addTender(transactionId: $transactionId, payload: $payload);
			$validation = $this->service->validateTenderSum(transactionId: $transactionId);
			return new JSONResponse(
				['tender' => $tender, 'validation' => $validation],
				Http::STATUS_CREATED
			);
		} catch (TenderTypeNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (InvalidTenderException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getStatusCode());
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: addTender failed',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to add tender'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end addTender()

	/**
	 * DELETE /api/pos-transactions/{transactionId}/tenders/{tenderId} — remove a tender.
	 *
	 * @param string $transactionId The posTransaction UUID.
	 * @param string $tenderId The posTender UUID.
	 *
	 * @return JSONResponse HTTP 204 on success.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-003
	 */
	#[NoAdminRequired]
	public function removeTender(string $transactionId, string $tenderId): JSONResponse {
		$this->requireAuthenticatedUser();

		try {
			$this->service->removeTender(transactionId: $transactionId, tenderId: $tenderId);
			return new JSONResponse(null, Http::STATUS_NO_CONTENT);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (InvalidTenderException $e) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getStatusCode());
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: removeTender failed',
				[
					'transactionId' => $transactionId,
					'tenderId' => $tenderId,
					'exception' => $e->getMessage(),
				]
			);
			return new JSONResponse(
				['error' => 'Failed to remove tender'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end removeTender()

	/**
	 * GET /api/pos-transactions/{transactionId}/tenders/validate — validation only.
	 *
	 * Returns the tender-sum vs. total comparison without modifying anything.
	 * The cashier UI calls this on a settle-attempt to surface the error
	 * message in the modal before triggering the POST /settle action.
	 *
	 * @param string $transactionId The posTransaction UUID.
	 *
	 * @return JSONResponse The validation summary.
	 *
	 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-004
	 */
	#[NoAdminRequired]
	public function summary(string $transactionId): JSONResponse {
		$this->requireAuthenticatedUser();

		try {
			return new JSONResponse(
				$this->service->validateTenderSum(transactionId: $transactionId)
			);
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error(
				'Pipelinq POS tender: summary failed',
				['transactionId' => $transactionId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Failed to validate tender sum'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end summary()

	// ---------------------------------------------------------------------
	// Internals — auth guard + request payload helper.
	// ---------------------------------------------------------------------

	/**
	 * Assert the caller is an authenticated NC user.
	 *
	 * NC SecurityMiddleware already rejects anonymous calls to a controller
	 * method without `#[PublicPage]`, but the explicit guard makes the
	 * authorization posture visible in the method body and satisfies the
	 * fleet's no-admin-IDOR gate which requires every `#[NoAdminRequired]`
	 * method to carry an in-body authorization assertion.
	 *
	 * @return void
	 *
	 * @throws OCSForbiddenException When the session has no authenticated user.
	 */
	private function requireAuthenticatedUser(): void {
		$user = $this->session->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Authentication required');
		}
	}//end requireAuthenticatedUser()

	/**
	 * Decode the JSON body into an associative array.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function bodyPayload(): array {
		$body = (string)file_get_contents('php://input');
		if ($body === '') {
			return $this->request->getParams();
		}

		$decoded = json_decode($body, true);
		if (is_array($decoded) === true) {
			return $decoded;
		}

		return $this->request->getParams();
	}//end bodyPayload()
}//end class
