<?php

/**
 * Pipelinq AutomationVariableController.
 *
 * Runtime variable query REST surface: list active automations with their
 * runtime state and return the variable bindings of an automation's most recent
 * execution. Backs external dashboards / n8n workflows that inspect automation
 * state. Requires authentication (HTTP 401 otherwise); reads are scoped to this
 * app's own register/schema in AutomationVariableService.
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
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for runtime automation variable queries.
 *
 * Authentication is required for every endpoint (REQ-VAR-004). Listing is
 * paginated; a never-executed automation yields an empty `variables` array with
 * HTTP 200 (REQ-VAR-002). No variable data is exposed in error responses.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Standard NC controller deps.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.4
 */
class AutomationVariableController extends Controller
{
    /**
     * Page size for the runtime list.
     *
     * @var int
     */
    private const PAGE_SIZE = 20;

    /**
     * Constructor.
     *
     * @param IRequest                  $request     The request.
     * @param AutomationVariableService $service     The runtime/variable service.
     * @param IUserSession              $userSession The user session.
     * @param IL10N                     $l10n        The localization service.
     * @param LoggerInterface           $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private AutomationVariableService $service,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List active automations with runtime state (paginated).
     *
     * @return JSONResponse The paginated runtime list.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.4
     */
    #[NoAdminRequired]
    public function runtime(): JSONResponse
    {
        $guard = $this->requireUser();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        try {
            $all   = $this->service->getActiveAutomations();
            $page  = max(1, (int) $this->request->getParam('page', 1));
            $total = count($all);
            $pages = max(1, (int) ceil(($total / self::PAGE_SIZE)));
            $slice = array_slice($all, (($page - 1) * self::PAGE_SIZE), self::PAGE_SIZE);

            return new JSONResponse(
                [
                    'results' => array_values($slice),
                    'total'   => $total,
                    'page'    => $page,
                    'pages'   => $pages,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('AutomationVariableController::runtime failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end runtime()

    /**
     * Return the variable bindings of an automation's most recent execution.
     *
     * @param string $id The automation UUID.
     *
     * @return JSONResponse The variable bindings.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.4
     */
    #[NoAdminRequired]
    public function variables(string $id): JSONResponse
    {
        $guard = $this->requireUser();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        try {
            $bindings = $this->service->getVariableBindings(automationId: $id);

            return new JSONResponse(['variables' => $bindings]);
        } catch (\Throwable $e) {
            $this->logger->error('AutomationVariableController::variables failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end variables()

    /**
     * Require an authenticated user.
     *
     * @return null|JSONResponse A 401 response when unauthenticated, else null.
     */
    private function requireUser(): ?JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        return null;
    }//end requireUser()
}//end class
