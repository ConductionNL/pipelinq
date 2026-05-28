<?php

/**
 * Pipelinq ReportingController.
 *
 * Controller for contact moment reporting and SLA configuration.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-49
 * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-50
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ReportingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for reporting endpoints and SLA configuration.
 */
class ReportingController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          The request.
     * @param ReportingService $reportingService The reporting service.
     * @param IUserSession     $userSession      The user session.
     * @param IL10N            $l10n             The localization service.
     */
    public function __construct(
        IRequest $request,
        private ReportingService $reportingService,
        private IUserSession $userSession,
        private IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get SLA configuration.
     *
     * @return JSONResponse The SLA targets.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-49
     */
    public function getSla(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $targets = $this->reportingService->getAllSlaTargets();
            return new JSONResponse(['targets' => $targets]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to load SLA configuration')],
                500,
            );
        }
    }//end getSla()

    /**
     * Update SLA configuration.
     *
     * Admin-only endpoint (requires admin settings permission).
     *
     * @return JSONResponse The updated SLA targets.
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-49
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function updateSla(): JSONResponse
    {
        try {
            $targets = $this->request->getParam('targets', []);

            if (is_array($targets) === false) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Invalid SLA configuration')],
                    400,
                );
            }

            $skipped = 0;
            foreach ($targets as $channel => $metrics) {
                if (is_array($metrics) === false) {
                    continue;
                }

                foreach ($metrics as $metric => $value) {
                    $accepted = $this->reportingService->setSlaTarget(
                        channel: $channel,
                        metric: $metric,
                        value: (string) $value,
                    );
                    if ($accepted === false) {
                        $skipped++;
                    }
                }
            }

            if ($skipped > 0) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('One or more unknown channel or metric values were skipped')],
                    400,
                );
            }

            return new JSONResponse(
                    [
                        'success' => true,
                        'targets' => $this->reportingService->getAllSlaTargets(),
                    ]
                    );
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to update SLA configuration')],
                500,
            );
        }//end try
    }//end updateSla()

    /**
     * Export reporting data as CSV.
     *
     * @return DataDownloadResponse|JSONResponse The CSV download or error.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-pipelinq/tasks.md#task-50
     */
    public function exportCsv(): DataDownloadResponse|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        // CSV export requires OpenRegister data integration.
        // Returning 501 until OR contactmoment retrieval is wired.
        return new JSONResponse(
            ['error' => $this->l10n->t('Export not yet implemented')],
            Http::STATUS_NOT_IMPLEMENTED,
        );
    }//end exportCsv()
}//end class
