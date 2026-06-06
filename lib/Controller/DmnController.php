<?php

/**
 * Pipelinq DmnController.
 *
 * REST endpoints for executing and listing DMN decision tables via the
 * OpenRegister WorkflowEngineRegistry.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\DmnDecisionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for /api/dmn endpoints. All endpoints require admin.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.3
 */
class DmnController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request          Request.
     * @param DmnDecisionService $decisionService  DMN service.
     * @param IUserSession       $userSession      User session.
     * @param IGroupManager      $groupManager     Group manager.
     */
    public function __construct(
        IRequest $request,
        private DmnDecisionService $decisionService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * POST /api/dmn/evaluate — run a decision table.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.3
     */
    #[NoAdminRequired]
    public function evaluate(): JSONResponse
    {
        if ($this->assertAdmin() === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        $decisionTableId = (string) $this->request->getParam('decisionTableId', '');
        $inputData       = (array) $this->request->getParam('inputData', []);

        try {
            $result = $this->decisionService->evaluateDecision($decisionTableId, $inputData);
        } catch (\InvalidArgumentException) {
            return new JSONResponse(['message' => 'Invalid decisionTableId'], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable) {
            return new JSONResponse(['message' => 'DMN evaluation failed'], Http::STATUS_BAD_REQUEST);
        }
        return new JSONResponse($result);
    }//end evaluate()

    /**
     * GET /api/dmn/tables — list available DMN decision tables.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.3
     */
    #[NoAdminRequired]
    public function listTables(): JSONResponse
    {
        if ($this->assertAdmin() === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        return new JSONResponse(['results' => $this->decisionService->listDecisionTables()]);
    }//end listTables()

    /**
     * Assert the caller is admin.
     *
     * @return bool
     */
    private function assertAdmin(): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }
        return $this->groupManager->isAdmin($user->getUID());
    }//end assertAdmin()
}//end class
