<?php

/**
 * Pipelinq CashShiftController.
 *
 * Thin controller for the POS cash-drawer lifecycle actions (open shift, record
 * drop, close-and-count, approve / reject variance). All business logic,
 * server-authoritative money math and authorization live in CashShiftService;
 * plain CRUD on cashShift / cashDrop / posCashCount / cashDiff is handled by
 * OpenRegister's generic object API.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pos-cash-management/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\CashShiftService;
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
 * Controller for POS cash-drawer lifecycle endpoints.
 *
 * Authorization model: every action requires an authenticated user, and the
 * service enforces the POS-operator (open/drop/count) or POS-manager
 * (approve/reject) predicate server-side and fails closed. Operator identity,
 * timestamps and all monetary figures are taken from the session / persisted
 * data, never from the request body.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/pos-cash-management/tasks.md#4.1
 */
class CashShiftController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param CashShiftService $service The cash-shift service.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private CashShiftService $service,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Open a new shift with a declared float (POS operator).
	 *
	 * @return JSONResponse The created shift.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#4.1
	 */
	#[NoAdminRequired]
	public function open(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$drawer = (string)$this->request->getParam('drawer', '');
		$floatAmount = (float)$this->request->getParam('floatAmount', 0);
		$reference = (string)$this->request->getParam('reference', '');
		$notes = (string)$this->request->getParam('notes', '');

		return $this->run(
			action: fn (): array => [
				'shift' => $this->service->openShift(
					drawer: $drawer,
					floatAmount: $floatAmount,
					userId: $uid,
					reference: $reference,
					notes: $notes
				),
			],
			label: 'open'
		);
	}//end open()

	/**
	 * Record a mid-shift cash drop (POS operator).
	 *
	 * @param string $id The shift UUID.
	 *
	 * @return JSONResponse The created drop.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#4.1
	 */
	#[NoAdminRequired]
	public function drop(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$amount = (float)$this->request->getParam('amount', 0);
		$reason = (string)$this->request->getParam('reason', '');

		return $this->run(
			action: fn (): array => [
				'drop' => $this->service->recordDrop(shiftId: $id, amount: $amount, reason: $reason, userId: $uid),
			],
			label: 'drop'
		);
	}//end drop()

	/**
	 * Close a shift with a blind count and compute the variance (POS operator).
	 *
	 * @param string $id The shift UUID.
	 *
	 * @return JSONResponse The count, diff and updated shift.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#4.1
	 */
	#[NoAdminRequired]
	public function count(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$amount = (float)$this->request->getParam('amount', 0);
		$notes = (string)$this->request->getParam('notes', '');

		return $this->run(
			action: fn (): array => $this->service->recordCount(
				shiftId: $id,
				amount: $amount,
				userId: $uid,
				notes: $notes
			),
			label: 'count'
		);
	}//end count()

	/**
	 * Approve a pending variance and emit the CloudEvent (POS manager only).
	 *
	 * @param string $id The shift UUID (the pending diff is resolved server-side).
	 *
	 * @return JSONResponse The approved diff.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#4.1
	 */
	#[NoAdminRequired]
	public function approve(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$diffId = (string)$this->request->getParam('diffId', $id);

		return $this->run(
			action: fn (): array => [
				'diff' => $this->service->approveDiff(diffId: $diffId, userId: $uid),
			],
			label: 'approve'
		);
	}//end approve()

	/**
	 * Reject a pending variance and reopen the shift (POS manager only).
	 *
	 * @param string $id The shift UUID (the pending diff is resolved server-side).
	 *
	 * @return JSONResponse The rejected diff.
	 *
	 * @spec openspec/changes/pos-cash-management/tasks.md#4.1
	 */
	#[NoAdminRequired]
	public function reject(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$diffId = (string)$this->request->getParam('diffId', $id);
		$reason = (string)$this->request->getParam('reason', '');

		return $this->run(
			action: fn (): array => [
				'diff' => $this->service->rejectDiff(diffId: $diffId, reason: $reason, userId: $uid),
			],
			label: 'reject'
		);
	}//end reject()

	/**
	 * Require an authenticated user, returning their UID.
	 *
	 * Returns a 401 JSONResponse when no user is in the session. Object-level
	 * access is scoped to this app's own cash schemas inside the service (an
	 * object in another app/register resolves to a 404), preventing IDOR.
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
	 * Run a lifecycle action with shared error handling.
	 *
	 * Maps the service's OCS exceptions to HTTP status codes (404 not found,
	 * 422 invalid transition / bad input, 403 operator-/manager-only).
	 *
	 * @param callable $action The action to run.
	 * @param string $label A short label for log context.
	 *
	 * @return JSONResponse The response.
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
		} catch (\Throwable $e) {
			$this->logger->error('CashShiftController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end run()
}//end class
