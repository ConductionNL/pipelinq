<?php

/**
 * Pipelinq ShillinqApController.
 *
 * Admin-only manual re-dispatch of a failed Shillinq AP voucher sync. The
 * endpoint re-fetches the expense, re-dispatches the approval event through
 * the AP service and records the new outcome on the expense.
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
 * @spec openspec/changes/archive/2026-06-14-pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ShillinqApServiceInterface;
use OCA\Pipelinq\Util\ContainerObjectFind;
use OCA\Pipelinq\Util\ContainerObjectSave;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Manual Shillinq AP re-dispatch controller.
 *
 * Gated with #[AuthorizedAdminSetting]; only Nextcloud administrators can
 * trigger a re-dispatch of an expense to the Shillinq AP webhook
 * (REQ-AP-003 Scenario 11 + REQ-AP-006 Scenario 18).
 *
 * @spec openspec/changes/archive/2026-06-14-pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */
class ShillinqApController extends Controller {
	use ContainerObjectFind;
	use ContainerObjectSave;

	/**
	 * Log line when reading the object fails.
	 *
	 * @var string
	 */
	private const FETCH_FAILURE_MESSAGE = 'Pipelinq: failed to load expense for AP retry';

	/**
	 * Log line when saving the object fails.
	 *
	 * @var string
	 */
	private const PERSIST_FAILURE_MESSAGE = 'Pipelinq: failed to persist expense AP retry status';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ShillinqApServiceInterface $apService The Shillinq AP service.
	 * @param ContainerInterface $container The DI container (OpenRegister ObjectService lookup).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private ShillinqApServiceInterface $apService,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Manually re-dispatch an expense to the Shillinq AP webhook.
	 *
	 * Only expenses currently in apSyncStatus=failed may be retried
	 * (REQ-AP-003 Scenario 11 — admin-corrective workflow). A retry while
	 * synced would create a duplicate AP voucher; a retry while pending is
	 * pre-empted by the original in-flight delivery.
	 *
	 * @param string $id The expense UUID to re-dispatch.
	 *
	 * @return JSONResponse The new AP sync status, or an error.
	 *
	 * @spec openspec/changes/archive/2026-06-14-pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
	 */
	#[AuthorizedAdminSetting(AppIdentity::APP_ID)]
	public function retry(string $id): JSONResponse {
		if ($this->apService->shouldDispatch() === false) {
			return new JSONResponse(['error' => 'Shillinq is not installed.'], 400);
		}

		$scope = $this->expenseScope(id: $id);
		if ($scope === null) {
			return new JSONResponse(['error' => 'Expense schema configuration is incomplete.'], 400);
		}

		[$register, $schema] = $scope;
		$expense = $this->findObjectData(register: $register, schema: $schema, uuid: $id);
		if ($expense === null) {
			return new JSONResponse(['error' => 'Expense not found.'], 404);
		}

		return $this->redispatch(register: $register, schema: $schema, id: $id, expense: $expense);
	}//end retry()

	/**
	 * The register and expense schema, or null when either or the id is missing.
	 *
	 * @param string $id The expense UUID.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function expenseScope(string $id): array|null {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'expense_schema', '');
		if ($register === '' || $schema === '' || $id === '') {
			return null;
		}

		return [$register, $schema];
	}//end expenseScope()

	/**
	 * Re-dispatch a failed expense and record the outcome.
	 *
	 * @param string               $register The register id.
	 * @param string               $schema   The schema id.
	 * @param string               $id       The expense UUID.
	 * @param array<string, mixed> $expense  The expense data.
	 *
	 * @return JSONResponse
	 */
	private function redispatch(string $register, string $schema, string $id, array $expense): JSONResponse {
		// Only failed entries may be retried (REQ-AP-003 Scenario 11).
		if (($expense['apSyncStatus'] ?? null) !== 'failed') {
			return new JSONResponse(
				['error' => 'Only failed AP syncs can be retried.', 'apSyncStatus' => $expense['apSyncStatus'] ?? null],
				400
			);
		}

		// Reset to pending before re-dispatch (REQ-AP-003 Scenario 11).
		$expense['apSyncStatus'] = 'pending';
		$this->saveObjectData(register: $register, schema: $schema, uuid: $id, data: $expense);

		$outcome = $this->dispatchExpense(expense: $expense, id: $id);
		$expense = $this->withRetryOutcome(expense: $expense, outcome: $outcome);
		$this->saveObjectData(register: $register, schema: $schema, uuid: $id, data: $expense);

		return $this->retryResponse(expense: $expense);
	}//end redispatch()

	/**
	 * Send the expense to the Shillinq AP webhook.
	 *
	 * @param array<string, mixed> $expense The expense data.
	 * @param string               $id      The expense UUID.
	 *
	 * @return string 'synced' when the dispatch succeeded, otherwise 'failed'.
	 */
	private function dispatchExpense(array $expense, string $id): string {
		$approvedBy = (string)($expense['approvedBy'] ?? '');
		$approvedAt = (string)($expense['approvedAt'] ?? $this->apService->now());

		$success = $this->apService->dispatchApEvent(
			expense: $expense + ['uuid' => $id],
			approvedBy: $approvedBy,
			approvedAt: $approvedAt
		);

		if ($success === true) {
			return 'synced';
		}

		return 'failed';
	}//end dispatchExpense()

	/**
	 * The expense with its AP sync status after the dispatch.
	 *
	 * @param array<string, mixed> $expense The expense data.
	 * @param string               $outcome 'synced' or 'failed'.
	 *
	 * @return array<string, mixed>
	 */
	private function withRetryOutcome(array $expense, string $outcome): array {
		$expense['apSyncStatus'] = $outcome;
		if ($outcome === 'synced') {
			$expense['apSyncedAt'] = $this->apService->now();
		}

		return $expense;
	}//end withRetryOutcome()

	/**
	 * The response for a finished retry.
	 *
	 * @param array<string, mixed> $expense The expense data, with its outcome.
	 *
	 * @return JSONResponse
	 */
	private function retryResponse(array $expense): JSONResponse {
		if ($expense['apSyncStatus'] === 'synced') {
			return new JSONResponse(
				[
					'apSyncStatus' => 'synced',
					'apSyncedAt' => $expense['apSyncedAt'],
				]
			);
		}

		return new JSONResponse(['apSyncStatus' => 'failed', 'error' => 'AP dispatch failed.'], 502);
	}//end retryResponse()
}//end class
