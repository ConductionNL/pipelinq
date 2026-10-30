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
 * @spec openspec/specs/pos-cash-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\CashShiftService;
use OCA\Pipelinq\Service\CashShiftSummaryService;
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
 * @spec openspec/specs/pos-cash-management/spec.md
 */
class CashShiftController extends Controller {
	/**
	 * The measures the shifts list can ask for per shift.
	 *
	 * @var array<int, string>
	 */
	private const SUMMARY_MEASURES = ['dropCount', 'dropTotal', 'difference', 'outcome'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param CashShiftService $service The cash-shift service.
	 * @param CashShiftSummaryService $summaries The cash-shift summary reader.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private CashShiftService $service,
		private CashShiftSummaryService $summaries,
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
	 * @spec openspec/specs/pos-cash-management/spec.md
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
	 * @spec openspec/specs/pos-cash-management/spec.md
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
	 * @spec openspec/specs/pos-cash-management/spec.md
	 * @spec openspec/changes/pl2-pos-header-actions/specs/pos-cash-management/spec.md#requirement-a-shift-is-counted-only-with-an-amount
	 */
	#[NoAdminRequired]
	public function count(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		// A missing amount used to count as 0 and close the shift on an empty drawer.
		$rawAmount = $this->request->getParam('amount', null);
		if (is_numeric($rawAmount) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Enter the counted amount.')],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		$amount = (float)$rawAmount;
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
	 * @spec openspec/specs/pos-cash-management/spec.md
	 */
	#[NoAdminRequired]
	public function approve(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$diffId = (string)$this->request->getParam('diffId', '');

		return $this->run(
			action: fn (): array => [
				'diff' => $this->service->approveDiff(
					diffId: $this->diffIdFor(shiftId: $id, diffId: $diffId),
					userId: $uid
				),
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
	 * @spec openspec/specs/pos-cash-management/spec.md
	 */
	#[NoAdminRequired]
	public function reject(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$diffId = (string)$this->request->getParam('diffId', '');
		$reason = (string)$this->request->getParam('reason', '');

		return $this->run(
			action: fn (): array => [
				'diff' => $this->service->rejectDiff(
					diffId: $this->diffIdFor(shiftId: $id, diffId: $diffId),
					reason: $reason,
					userId: $uid
				),
			],
			label: 'reject'
		);
	}//end reject()

	/**
	 * One measure of the shifts named by `keys`, as a list column's endpoint
	 * aggregate reads it: `{ available, values: { shiftId: value } }`.
	 * Measures: dropCount, dropTotal, difference, outcome.
	 *
	 * @param string $measure The measure.
	 *
	 * @return JSONResponse The values per shift.
	 *
	 * @spec openspec/changes/pos-shift-summary/specs/pos-cash-management/spec.md#requirement-a-shift-summary-reads-its-drops-and-difference
	 */
	#[NoAdminRequired]
	public function summary(string $measure): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		if (in_array($measure, self::SUMMARY_MEASURES, true) === false) {
			return new JSONResponse(['error' => $this->l10n->t('Unknown measure')], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$keys = $this->request->getParam('keys', []);
		if (is_array($keys) === false) {
			$keys = [];
		}

		$keys = array_values(array_filter($keys, 'is_string'));

		return $this->run(
			action: fn (): array => [
				'available' => true,
				'values' => array_map(
					fn (array $summary): mixed => $summary[$measure] ?? null,
					$this->summaries->summarise(shiftIds: $keys)
				),
			],
			label: 'summary'
		);
	}//end summary()

	/**
	 * The summary of one shift for its page: drops, difference, outcome, the
	 * sales booked in it and the Z report of its till on its day.
	 *
	 * @param string $id The shift UUID.
	 *
	 * @return JSONResponse The summary.
	 *
	 * @spec openspec/changes/pos-shift-summary/specs/pos-cash-management/spec.md#requirement-a-shift-page-names-its-sales-and-z-report
	 */
	#[NoAdminRequired]
	public function shiftSummary(string $id): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		return $this->run(
			action: fn (): array => $this->summaries->shiftSummary(shiftId: $id),
			label: 'shiftSummary'
		);
	}//end shiftSummary()

	/**
	 * The difference an approve or reject acts on: the one named in the
	 * request, else the shift's pending difference.
	 *
	 * @param string $shiftId The shift UUID.
	 * @param string $diffId  The diff UUID from the request, or ''.
	 *
	 * @return string The diff UUID.
	 */
	private function diffIdFor(string $shiftId, string $diffId): string {
		if ($diffId !== '') {
			return $diffId;
		}

		return $this->service->pendingDiffId(shiftId: $shiftId);
	}//end diffIdFor()

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
