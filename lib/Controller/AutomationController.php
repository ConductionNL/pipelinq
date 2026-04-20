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
 *
 * @spec openspec/changes/2026-03-20-crm-workflow-automation/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AutomationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for automation CRUD and metadata endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AutomationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request           The request.
     * @param AutomationService $automationService The automation service.
     * @param IL10N             $l10n              The localization service.
     * @param LoggerInterface   $logger            The logger.
     */
    public function __construct(
        IRequest $request,
        private AutomationService $automationService,
        private IL10N $l10n,
        private LoggerInterface $logger,
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

        if (empty($automation) === true || empty($trigger) === true || empty($entityData) === true) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Automation, trigger, and entityData are required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
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
        } catch (\Exception $e) {
            $this->logger->error('Automation test failed: '.$e->getMessage());
            return new JSONResponse(
                ['error' => $this->l10n->t('Automation test failed')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end test()

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
        if (empty($id) === true) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Automation ID is required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            // In production, fetch from OpenRegister automationLog objects
            // filtered by automation ID. For now, return an empty list as a
            // placeholder. The frontend will display the history table.
            return new JSONResponse(
                [
                    'automation_id' => $id,
                    'logs'          => [],
                    'total'         => 0,
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch automation history: '.$e->getMessage());
            return new JSONResponse(
                ['error' => $this->l10n->t('Failed to fetch execution history')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end history()
}//end class
