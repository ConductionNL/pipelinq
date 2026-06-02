<?php

/**
 * Pipelinq ProjectHierarchyController.
 *
 * Thin controller exposing the server-authoritative project work-breakdown
 * summary (resolved billable flags, rolled-up hours, budget status) and a
 * cycle-safe parent-validation endpoint. All computation and scoping live in
 * ProjectHierarchyService; CRUD on the four schemas is handled by OpenRegister's
 * generic object endpoints.
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
 * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ProjectHierarchyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for project-hierarchy read/validation endpoints.
 *
 * Authorization model: every action requires an authenticated user (a CRM
 * capability, gated via #[NoAdminRequired] plus an explicit session check). The
 * project key is resolved server-side inside ProjectHierarchyService against
 * this app's own register + the four project schemas, so a caller can never
 * reach objects outside the project hierarchy (no IDOR). All figures returned
 * are computed server-side and are never trusted from the client.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-007
 */
class ProjectHierarchyController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                $request     The request.
     * @param ProjectHierarchyService $service     The hierarchy service.
     * @param IUserSession            $userSession The user session.
     * @param IL10N                   $l10n        The localization service.
     * @param LoggerInterface         $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private ProjectHierarchyService $service,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Return the server-authoritative WBS summary for one project.
     *
     * @param string $projectKey The project key (slug/uuid).
     *
     * @return JSONResponse The project summary, or 404 when the project is absent.
     *
     * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-007
     * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-008
     */
    #[NoAdminRequired]
    public function summary(string $projectKey): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        return $this->run(
            action: fn (): array => $this->service->getProjectSummary(projectKey: $projectKey),
            label: 'summary'
        );
    }//end summary()

    /**
     * Validate a proposed parent reference for a WBS child, rejecting cycles.
     *
     * @return JSONResponse {valid: true} on success, or 422 with an error message.
     *
     * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-002
     */
    #[NoAdminRequired]
    public function validateParent(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        $level     = (string) $this->request->getParam('level', '');
        $childKey  = (string) $this->request->getParam('childKey', '');
        $parentKey = (string) $this->request->getParam('parentKey', '');

        return $this->run(
            action: function () use ($level, $childKey, $parentKey): array {
                $this->service->assertValidParent(level: $level, childKey: $childKey, proposedParentKey: $parentKey);
                return ['valid' => true];
            },
            label: 'validateParent'
        );
    }//end validateParent()

    /**
     * Run an action with shared error handling.
     *
     * @param callable $action The action to run.
     * @param string   $label  A short label for log context.
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label): JSONResponse
    {
        try {
            return new JSONResponse($action());
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('ProjectHierarchyController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
