<?php

/**
 * Pipelinq AutomationController.
 *
 * REST API for CRM workflow automation CRUD, history and activation.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AutomationService;
use OCA\Pipelinq\Service\AutomationVariableService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Controller for /api/automations endpoints.
 *
 * Mutation endpoints require admin via IGroupManager::isAdmin (REQ-NFR-005).
 * All work is delegated to AutomationService — controller methods stay thin.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
 */
class AutomationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                  $request           The request.
     * @param AutomationService         $automationService Automation service.
     * @param AutomationVariableService $variableService   Variable service (history).
     * @param IUserSession              $userSession       User session.
     * @param IGroupManager             $groupManager      Group manager.
     * @param IAppConfig                $appConfig         App config.
     * @param ContainerInterface        $container         DI container (OR ObjectService).
     */
    public function __construct(
        IRequest $request,
        private AutomationService $automationService,
        private AutomationVariableService $variableService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IAppConfig $appConfig,
        private ContainerInterface $container,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/automations — list automations (paginated).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }
        $page  = max(1, (int) $this->request->getParam('page', 1));
        $limit = max(1, min(200, (int) $this->request->getParam('limit', 50)));
        $data  = $this->automationService->listAutomations($page, $limit);
        return new JSONResponse($data);
    }//end index()

    /**
     * GET /api/automations/{id} — automation detail.
     *
     * @param string $id Automation slug or UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }
        $automation = $this->automationService->findAutomation($id);
        if ($automation === null) {
            return new JSONResponse(['message' => 'Automation not found'], Http::STATUS_NOT_FOUND);
        }
        return new JSONResponse($automation);
    }//end show()

    /**
     * POST /api/automations — create automation (admin only).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        if ($this->assertAdmin() === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        return $this->writeObject(null);
    }//end create()

    /**
     * PUT /api/automations/{id} — update automation (admin only).
     *
     * @param string $id Automation slug or UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        if ($this->assertAdmin() === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        return $this->writeObject($id);
    }//end update()

    /**
     * DELETE /api/automations/{id} — delete automation (admin only).
     *
     * @param string $id Automation slug or UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        if ($this->assertAdmin() === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        try {
            $register = $this->getRegister();
            $schema   = $this->getSchema('automation_schema');
            $this->getObjectService()
                ->setRegister($register)
                ->setSchema($schema)
                ->deleteObject(uuid: $id);
        } catch (\Throwable) {
            return new JSONResponse(['message' => 'Delete failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse(['message' => 'Deleted']);
    }//end destroy()

    /**
     * GET /api/automations/{id}/history — execution log entries.
     *
     * @param string $id Automation slug or UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function history(string $id): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }
        $entries = $this->loadHistory($id);
        return new JSONResponse([
            'results' => $entries,
            'total'   => count($entries),
            'page'    => 1,
            'pages'   => 1,
        ]);
    }//end history()

    /**
     * PUT /api/automations/{id}/activate — toggle isActive flag (admin only).
     *
     * @param string $id Automation slug or UUID.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function activate(string $id): JSONResponse
    {
        if ($this->assertAdmin() === false) {
            return new JSONResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }
        $current = $this->automationService->findAutomation($id);
        if ($current === null) {
            return new JSONResponse(['message' => 'Automation not found'], Http::STATUS_NOT_FOUND);
        }
        $current['isActive'] = (bool) $this->request->getParam('isActive', !($current['isActive'] ?? false));
        try {
            $this->getObjectService()->saveObject(
                $current,
                [],
                $this->getRegister(),
                $this->getSchema('automation_schema'),
                $id
            );
        } catch (\Throwable) {
            return new JSONResponse(['message' => 'Update failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse(['id' => $id, 'isActive' => $current['isActive']]);
    }//end activate()

    /**
     * Write the request body to the automation schema. Used by create/update.
     *
     * @param ?string $id Existing UUID or null when creating.
     *
     * @return JSONResponse
     */
    private function writeObject(?string $id): JSONResponse
    {
        $data = $this->request->getParams();
        unset($data['_route']);
        try {
            $entity = $this->getObjectService()->saveObject(
                $data,
                [],
                $this->getRegister(),
                $this->getSchema('automation_schema'),
                $id
            );
        } catch (\Throwable) {
            return new JSONResponse(['message' => 'Save failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        return new JSONResponse(
            is_object($entity) && method_exists($entity, 'jsonSerialize') ? $entity->jsonSerialize() : $data,
            $id === null ? Http::STATUS_CREATED : Http::STATUS_OK
        );
    }//end writeObject()

    /**
     * Fetch automationLog entries for an automation, sorted newest-first.
     *
     * @param string $automationId Automation slug or UUID.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadHistory(string $automationId): array
    {
        $register = $this->getRegister();
        $schema   = $this->getSchema('automationLog_schema');
        if ($register === '' || $schema === '') {
            return [];
        }
        try {
            $rows = $this->getObjectService()->findAll([
                'register' => $register,
                'schema'   => $schema,
                'filters'  => ['automation' => $automationId],
                'limit'    => 100,
            ]);
        } catch (\Throwable) {
            return [];
        }
        $entries = [];
        foreach ($rows as $row) {
            if (is_array($row) === true) {
                $entries[] = $row;
            } elseif (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $candidate = $row->jsonSerialize();
                if (is_array($candidate) === true) {
                    $entries[] = $candidate;
                }
            }
        }
        usort($entries, static function (array $a, array $b): int {
            return strcmp((string) ($b['triggeredAt'] ?? ''), (string) ($a['triggeredAt'] ?? ''));
        });
        return $entries;
    }//end loadHistory()

    /**
     * Return true if the current user is a Nextcloud admin.
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

    /**
     * Get the configured Pipelinq register slug.
     *
     * @return string
     */
    private function getRegister(): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'register', '');
    }//end getRegister()

    /**
     * Get a configured schema slug by key.
     *
     * @param string $key Config key.
     *
     * @return string
     */
    private function getSchema(string $key): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, '');
    }//end getSchema()

    /**
     * Resolve OpenRegister ObjectService.
     *
     * @return object
     *
     * @throws \RuntimeException When OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable) {
            throw new \RuntimeException('OpenRegister ObjectService is not available.');
        }
    }//end getObjectService()
}//end class
