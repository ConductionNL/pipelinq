<?php

/**
 * Pipelinq SlaAttainmentController.
 *
 * Read-only SLA attainment reporting endpoint (REQ-006).
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
 * @link https://codeberg.org/Conduction/pipelinq
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SlaAttainmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves SLA attainment statistics to authenticated users (REQ-006).
 *
 * The computation is fully server-authoritative: the client supplies only the
 * time-bucket and grouping; all met/breached accounting is derived from the
 * server-managed slaStatus on tracked objects.
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-006
 */
class SlaAttainmentController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request           The request.
     * @param SlaAttainmentService $attainmentService The attainment service.
     * @param IUserSession         $userSession       The user session.
     * @param IL10N                $l10n              The localization service.
     */
    public function __construct(
        IRequest $request,
        private SlaAttainmentService $attainmentService,
        private IUserSession $userSession,
        private IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the SLA attainment report.
     *
     * Query params: `bucket` (day|week|month|quarter), the matching
     * `date|week|month|quarter` value, `groupBy` (policy|customer|tier|team),
     * and optional `policy` filter.
     *
     * @return JSONResponse The attainment report or an error.
     *
     * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-006
     */
    #[NoAdminRequired]
    public function attainment(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

        $params = [
            'bucket'  => (string) $this->request->getParam('bucket', 'quarter'),
            'groupBy' => (string) $this->request->getParam('groupBy', 'policy'),
            'policy'  => (string) $this->request->getParam('policy', ''),
            'quarter' => $this->request->getParam('quarter'),
            'month'   => $this->request->getParam('month'),
            'week'    => $this->request->getParam('week'),
            'date'    => $this->request->getParam('date'),
        ];

        try {
            $report = $this->attainmentService->report(params: array_filter($params, static fn ($v): bool => $v !== null));
            return new JSONResponse($report);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $this->l10n->t('Failed to compute attainment')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end attainment()
}//end class
