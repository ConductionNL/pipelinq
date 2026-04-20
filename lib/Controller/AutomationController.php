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
 * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3
 */
class AutomationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request           The request.
     * @param AutomationService $automationService The automation service.
     * @param IL10N             $l10n              The localization service.
     */
    public function __construct(
        IRequest $request,
        private AutomationService $automationService,
        private IL10N $l10n,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get available triggers and actions metadata.
     *
     * @return JSONResponse The automation metadata.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
     */
    public function metadata(): JSONResponse
    {
        return new JSONResponse(
                [
                    'triggers' => $this->automationService->getValidTriggers(),
                    'actions'  => $this->automationService->getValidActions(),
                ]
                );
    }//end metadata()

    /**
     * List all automations.
     *
     * @return JSONResponse The list of automations.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3
     */
    public function index(): JSONResponse
    {
        try {
            $automations = $this->automationService->listAutomations();
            return new JSONResponse(['automations' => $automations]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to list automations')],
                500
            );
        }
    }//end index()

    /**
     * Get a single automation by ID.
     *
     * @param string $id The automation UUID.
     *
     * @return JSONResponse The automation details.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3
     */
    public function show(string $id): JSONResponse
    {
        try {
            $automation = $this->automationService->getAutomation($id);
            if ($automation === null) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Automation not found')],
                    404
                );
            }

            return new JSONResponse(['automation' => $automation]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to get automation')],
                500
            );
        }
    }//end show()

    /**
     * Create a new automation.
     *
     * @return JSONResponse The created automation.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3
     */
    public function create(): JSONResponse
    {
        try {
            $data = $this->request->getParams();

            if (empty($data['name'] ?? '') === true) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Automation name is required')],
                    400
                );
            }

            if (empty($data['trigger'] ?? '') === true) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Automation trigger is required')],
                    400
                );
            }

            // Ensure isActive defaults to true if not specified.
            if (isset($data['isActive']) === false) {
                $data['isActive'] = true;
            }

            $automation = $this->automationService->saveAutomation($data);
            return new JSONResponse(['automation' => $automation], 201);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to create automation')],
                500
            );
        }//end try
    }//end create()

    /**
     * Update an existing automation.
     *
     * @param string $id The automation UUID.
     *
     * @return JSONResponse The updated automation.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3
     */
    public function update(string $id): JSONResponse
    {
        try {
            $data           = $this->request->getParams();
            $data['id']     = $id;

            $automation = $this->automationService->saveAutomation($data);
            return new JSONResponse(['automation' => $automation]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to update automation')],
                500
            );
        }
    }//end update()

    /**
     * Delete an automation.
     *
     * @param string $id The automation UUID.
     *
     * @return JSONResponse Success response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3
     */
    public function destroy(string $id): JSONResponse
    {
        try {
            $this->automationService->deleteAutomation($id);
            return new JSONResponse(['success' => true]);
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Automation not found: '.$id) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Automation not found')],
                    404
                );
            }

            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to delete automation')],
                500
            );
        }
    }//end destroy()

    /**
     * Get execution history for an automation.
     *
     * @param string $id The automation UUID.
     *
     * @return JSONResponse The execution history.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3
     */
    public function history(string $id): JSONResponse
    {
        try {
            // Verify automation exists.
            $automation = $this->automationService->getAutomation($id);
            if ($automation === null) {
                return new JSONResponse(
                    ['error' => $this->l10n->t('Automation not found')],
                    404
                );
            }

            // TODO: Implement history filtering from automationLog objects.
            // For now, return empty history as logs are stored as separate objects.
            return new JSONResponse(['history' => [], 'automationId' => $id]);
        } catch (\Exception $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to get execution history')],
                500
            );
        }
    }//end history()

    /**
     * Test an automation's conditions against sample entity data.
     *
     * @return JSONResponse The test result.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3
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
    }//end test()
}//end class
