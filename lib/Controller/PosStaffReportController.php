<?php

/**
 * Pipelinq PosStaffReportController.
 *
 * Thin controller for the per-staff sales report endpoint
 * (pos-staff-pin-permissions REQ-PSP-008). All aggregation is server-side
 * in PosStaffReportService.
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
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosStaffReportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the per-staff sales report.
 *
 * Authorization: the report aggregates across all staff and exposes
 * commission-relevant totals, so it is restricted to POS managers / admins
 * (PosAccessPolicy::isManager, which already gates the BTW report).
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10.2
 */
class PosStaffReportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PosStaffReportService $service The report service.
	 * @param PosAccessPolicy $policy The POS access policy (manager predicate).
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private PosStaffReportService $service,
		private PosAccessPolicy $policy,
		private IUserSession $userSession,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Per-staff sales aggregation report.
	 *
	 * Returns rows of {staffMemberId, displayName, transactionCount, total,
	 * totalTax} aggregated over all fiscally-final POS transactions
	 * (confirmed / settled / refunded). Refunds are netted out.
	 *
	 * @return JSONResponse The report payload.
	 *
	 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10.2
	 */
	#[NoAdminRequired]
	public function staffSales(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->policy->isManager(userId: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Manager privileges required')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$report = $this->service->staffSalesReport();
		} catch (\Throwable $e) {
			$this->logger->error('PosStaffReportController::staffSales failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse(['report' => $report]);
	}//end staffSales()
}//end class
