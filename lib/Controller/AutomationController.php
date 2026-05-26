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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for automation CRUD and metadata endpoints.
 */
class AutomationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request           The request.
     * @param AutomationService $automationService The automation service.
     * @param IUserSession      $userSession       The user session.
     * @param IL10N             $l10n              The localization service.
     */
    public function __construct(
        IRequest $request,
        private AutomationService $automationService,
        private IUserSession $userSession,
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
     * @spec            openspec/changes/reverse-2026-05-26-be-automation/tasks.md#task-1
     */
    public function metadata(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

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
     * @spec            openspec/changes/reverse-2026-05-26-be-automation/tasks.md#task-2
     */
    public function test(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
        }

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
