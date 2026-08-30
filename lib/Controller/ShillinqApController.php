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
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ShillinqApService;
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
 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
 */
class ShillinqApController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ShillinqApService $apService The Shillinq AP service.
	 * @param ContainerInterface $container The DI container (OpenRegister ObjectService lookup).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private ShillinqApService $apService,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
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
	 * @spec openspec/changes/pipelinq-expense-to-shillinq-ap/specs.md#REQ-AP-003
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function retry(string $id): JSONResponse {
		if ($this->apService->shouldDispatch() === false) {
			return new JSONResponse(['error' => 'Shillinq AP integration is not configured.'], 400);
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'expense_schema', '');
		if ($register === '' || $schema === '' || $id === '') {
			return new JSONResponse(['error' => 'Expense schema configuration is incomplete.'], 400);
		}

		$expense = $this->fetchExpense(register: $register, schema: $schema, uuid: $id);
		if ($expense === null) {
			return new JSONResponse(['error' => 'Expense not found.'], 404);
		}

		// Only failed entries may be retried (REQ-AP-003 Scenario 11).
		if (($expense['apSyncStatus'] ?? null) !== 'failed') {
			return new JSONResponse(
				['error' => 'Only failed AP syncs can be retried.', 'apSyncStatus' => $expense['apSyncStatus'] ?? null],
				400
			);
		}

		// Reset to pending before re-dispatch (REQ-AP-003 Scenario 11).
		$expense['apSyncStatus'] = 'pending';
		$this->persist(register: $register, schema: $schema, uuid: $id, data: $expense);

		$approvedBy = (string)($expense['approvedBy'] ?? '');
		$approvedAt = (string)($expense['approvedAt'] ?? $this->apService->now());

		$success = $this->apService->dispatchApEvent(
			expense: $expense + ['uuid' => $id],
			approvedBy: $approvedBy,
			approvedAt: $approvedAt
		);

		if ($success === true) {
			$expense['apSyncStatus'] = 'synced';
			$expense['apSyncedAt'] = $this->apService->now();
			$this->persist(register: $register, schema: $schema, uuid: $id, data: $expense);

			return new JSONResponse(
				[
					'apSyncStatus' => 'synced',
					'apSyncedAt' => $expense['apSyncedAt'],
				]
			);
		}

		$expense['apSyncStatus'] = 'failed';
		$this->persist(register: $register, schema: $schema, uuid: $id, data: $expense);

		return new JSONResponse(['apSyncStatus' => 'failed', 'error' => 'AP dispatch failed.'], 502);
	}//end retry()

	/**
	 * Fetch an expense's data by UUID.
	 *
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param string $uuid The expense UUID.
	 *
	 * @return array<string, mixed>|null The expense data, or null when not found.
	 */
	private function fetchExpense(string $register, string $schema, string $uuid): ?array {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$object = $objectService->find(id: $uuid, register: $register, schema: $schema);
			if ($object === null) {
				return null;
			}

			return $object->getObject();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to load expense for AP retry',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
			return null;
		}//end try
	}//end fetchExpense()

	/**
	 * Persist the mutated expense data back to OpenRegister.
	 *
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param string $uuid The expense UUID.
	 * @param array<string, mixed> $data The mutated expense data.
	 *
	 * @return void
	 */
	private function persist(string $register, string $schema, string $uuid, array $data): void {
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
			$objectService->saveObject(
				object: $data,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: $uuid
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: failed to persist expense AP retry status',
				['exception' => $e->getMessage(), 'uuid' => $uuid]
			);
		}//end try
	}//end persist()
}//end class
