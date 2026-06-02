<?php

/**
 * Pipelinq RapportageController.
 *
 * Thin controller exposing aggregated lead/pipeline analytics. All
 * aggregation logic lives in RapportageService. Accessible to every
 * authenticated user (analytics is a business feature, not configuration).
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
 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\RapportageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for lead pipeline analytics endpoints.
 *
 * Authorization model: requires an authenticated user (Nextcloud rejects
 * anonymous requests with HTTP 401 before the controller runs). No admin
 * privilege is required — sales reps and managers both read analytics.
 *
 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
 */
class RapportageController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request The request.
     * @param RapportageService $service The analytics service.
     * @param IUserSession      $session The user session.
     * @param IL10N             $l10n    The localization service.
     * @param LoggerInterface   $logger  The logger.
     */
    public function __construct(
        IRequest $request,
        private RapportageService $service,
        private IUserSession $session,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return aggregated pipeline analytics for the current view.
     *
     * Query parameters (all optional): `pipeline`, `dateFrom`, `dateTo`.
     *
     * @return JSONResponse The analytics payload, or an error response.
     *
     * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
     * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-009
     */
    #[NoAdminRequired]
    public function getPipelineStats(): JSONResponse
    {
        if ($this->session->getUser() === null) {
            return new JSONResponse(['error' => 'Authentication required.'], Http::STATUS_UNAUTHORIZED);
        }

        $pipeline = $this->stringParam(name: 'pipeline');
        $dateFrom = $this->stringParam(name: 'dateFrom');
        $dateTo   = $this->stringParam(name: 'dateTo');

        try {
            $stats = $this->service->getPipelineStats(
                pipelineId: $pipeline,
                dateFrom: $dateFrom,
                dateTo: $dateTo
            );
        } catch (\Throwable $e) {
            $this->logger->error('Pipelinq: failed to build pipeline analytics', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => 'Failed to compute analytics.'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse($stats);
    }//end getPipelineStats()

    /**
     * Read an optional non-empty string query parameter.
     *
     * @param string $name The parameter name.
     *
     * @return string|null The trimmed value, or null when absent/empty.
     */
    private function stringParam(string $name): ?string
    {
        $value = $this->request->getParam($name);
        if (is_string($value) === false) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        return $value;
    }//end stringParam()
}//end class
