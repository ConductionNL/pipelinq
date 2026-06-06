<?php

/**
 * Pipelinq AutomationVariableController.
 *
 * REST endpoints exposing automation runtime state and variable bindings.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AutomationVariableService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for /api/automations/runtime + /api/automations/{id}/variables.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.4
 */
class AutomationVariableController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                  $request         Request.
     * @param AutomationVariableService $variableService Variable service.
     * @param IUserSession              $userSession     User session.
     */
    public function __construct(
        IRequest $request,
        private AutomationVariableService $variableService,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/automations/runtime — list active automations w/ state (paginated).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.4
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $page  = max(1, (int) $this->request->getParam('page', 1));
        $limit = max(1, min(200, (int) $this->request->getParam('limit', 50)));

        $rows = $this->variableService->getActiveAutomations();
        $enriched = [];
        foreach ($rows as $row) {
            $id    = (string) ($row['id'] ?? $row['slug'] ?? $row['uuid'] ?? '');
            $state = ($id !== '') ? $this->variableService->getRuntimeState($id) : [];
            $enriched[] = [
                'automationId'      => $id,
                'name'              => (string) ($row['name'] ?? ''),
                'lastRun'           => ($row['lastRun'] ?? null),
                'runCount'          => (int) ($row['runCount'] ?? 0),
                'lastTriggerEntity' => ($state['triggerEntity'] ?? null),
                'lastStatus'        => ($state['status'] ?? null),
            ];
        }

        $total  = count($enriched);
        $offset = (($page - 1) * $limit);
        $slice  = array_slice($enriched, $offset, $limit);
        $pages  = ($limit > 0) ? (int) ceil($total / $limit) : 0;

        return new JSONResponse([
            'results' => $slice,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
        ]);
    }//end index()

    /**
     * GET /api/automations/{id}/variables — variable bindings of last execution.
     *
     * @param string $id Automation slug or UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.4
     */
    #[NoAdminRequired]
    public function variables(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }
        $variables = $this->variableService->getVariableBindings($id);
        return new JSONResponse(['variables' => $variables]);
    }//end variables()
}//end class
