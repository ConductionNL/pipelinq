<?php

/**
 * Pipelinq PosStaffReportController.
 *
 * Read-only endpoint for the per-staff POS sales report. Restricted to POS
 * managers / admins via PosAccessPolicy, since per-staff revenue is sensitive
 * commission data. Aggregation lives in PosStaffReportService; no stack traces
 * reach the client.
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
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10
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
 * Controller for the per-staff POS sales report.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10
 */
class PosStaffReportController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request      The request.
     * @param PosStaffReportService $service      The report service.
     * @param PosAccessPolicy       $accessPolicy The POS access policy.
     * @param IUserSession          $userSession  The user session.
     * @param IL10N                 $l10n         The localization service.
     * @param LoggerInterface       $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private PosStaffReportService $service,
        private PosAccessPolicy $accessPolicy,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the per-staff sales report (POS manager / admin only).
     *
     * @return JSONResponse The report rows.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#10
     */
    #[NoAdminRequired]
    public function staffSales(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        if ($this->accessPolicy->isManager(userId: $user->getUID()) === false) {
            return new JSONResponse(
                ['error' => $this->l10n->t('You are not permitted to view staff sales')],
                Http::STATUS_FORBIDDEN
            );
        }

        try {
            return new JSONResponse(['report' => $this->service->staffSalesReport()]);
        } catch (\Throwable $e) {
            $this->logger->error('PosStaffReportController::staffSales failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end staffSales()
}//end class
