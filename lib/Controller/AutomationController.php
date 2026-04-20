<?php

/**
 * Pipelinq AutomationController.
 *
 * Controller for CRM workflow automation management.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AutomationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Controller for automation CRUD and metadata endpoints.
 *
 * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
 */
class AutomationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request           The request.
     * @param AutomationService $automationService The automation service.
     * @param IL10N             $l10n              The localization service.
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function __construct(
        IRequest $request,
        private AutomationService $automationService,
        private IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }

    /**
     * Get available triggers and actions metadata.
     *
     * @return JSONResponse The automation metadata.
     *
     * @NoAdminRequired
     */
    public function metadata(): JSONResponse
    {
        return new JSONResponse(
            [
                    'triggers' => $this->automationService->getValidTriggers(),
                    'actions'  => $this->automationService->getValidActions(),
                ]
        );
    }

    /**
     * List all automations.
     *
     * @return JSONResponse The list of automations.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function index(): JSONResponse
    {
        $limit = (int) $this->request->getParam('_limit', 100);
        $offset = (int) $this->request->getParam('_offset', 0);

        $automations = $this->automationService->listAutomations(
            params: [
                '_limit' => $limit,
                '_offset' => $offset,
            ]
        );

        return new JSONResponse(['success' => true, 'data' => $automations]);
    }

    /**
     * Get a single automation.
     *
     * @param string $id The automation ID.
     *
     * @return JSONResponse The automation data.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function show(string $id): JSONResponse
    {
        $automation = $this->automationService->getAutomation(id: $id);
        if ($automation === null) {
            return new JSONResponse(['error' => 'Automation not found'], 404);
        }

        return new JSONResponse(['success' => true, 'data' => $automation]);
    }

    /**
     * Create a new automation.
     *
     * @return JSONResponse The created automation.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function create(): JSONResponse
    {
        $data = $this->request->getParams();

        $automation = $this->automationService->saveAutomation(data: $data);
        if ($automation === null) {
            return new JSONResponse(['error' => 'Failed to create automation'], 400);
        }

        return new JSONResponse(['success' => true, 'data' => $automation], 201);
    }

    /**
     * Update an automation.
     *
     * @param string $id The automation ID.
     *
     * @return JSONResponse The updated automation.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function update(string $id): JSONResponse
    {
        $automation = $this->automationService->getAutomation(id: $id);
        if ($automation === null) {
            return new JSONResponse(['error' => 'Automation not found'], 404);
        }

        $data = $this->request->getParams();
        $automation = array_merge($automation, $data);

        $updated = $this->automationService->saveAutomation(data: $automation);
        if ($updated === null) {
            return new JSONResponse(['error' => 'Failed to update automation'], 400);
        }

        return new JSONResponse(['success' => true, 'data' => $updated]);
    }

    /**
     * Delete an automation.
     *
     * @param string $id The automation ID.
     *
     * @return JSONResponse The delete result.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function destroy(string $id): JSONResponse
    {
        $success = $this->automationService->deleteAutomation(id: $id);
        if (!$success) {
            return new JSONResponse(['error' => 'Failed to delete automation'], 400);
        }

        return new JSONResponse(['success' => true]);
    }

    /**
     * Get execution history for an automation.
     *
     * @param string $id The automation ID.
     *
     * @return JSONResponse The execution history.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function history(string $id): JSONResponse
    {
        $limit = (int) $this->request->getParam('_limit', 50);
        $offset = (int) $this->request->getParam('_offset', 0);

        $history = $this->automationService->getExecutionHistory(
            automationId: $id,
            params: [
                '_limit' => $limit,
                '_offset' => $offset,
            ]
        );

        return new JSONResponse(['success' => true, 'data' => $history]);
    }

    /**
     * Test an automation's conditions against sample entity data.
     *
     * @return JSONResponse The test result.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function test(): JSONResponse
    {
        $automation = $this->request->getParam('automation', []);
        $trigger    = $this->request->getParam('trigger', '');
        $entityData = $this->request->getParam('entityData', []);

        $matches = $this->automationService->matchesConditions(
            automation: $automation,
            trigger: $trigger,
            entityData: $entityData
        );

        return new JSONResponse(
            [
                    'matches' => $matches,
                    'payload' => $this->automationService->buildWebhookPayload(
                        automation: $automation,
                        trigger: $trigger,
                        entityData: $entityData
                    ),
                ]
        );
    }
}
