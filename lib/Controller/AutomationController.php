<?php

/**
 * Pipelinq AutomationController.
 *
 * Thin controller for CRM workflow automations: list / show / create / update /
 * delete plus per-automation execution history. List and show are available to
 * any authenticated CRM user; every mutation requires a Nextcloud admin
 * (HTTP 403 otherwise). All business logic lives in AutomationService /
 * AutomationVariableService — controller methods stay thin and never leak a
 * stack trace to the client.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AutomationCrudService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for automation CRUD and history endpoints.
 *
 * Authorization model: reads (index / show / history) require an authenticated
 * user; writes (create / update / destroy) require admin (REQ-NFR-005). Reads
 * are scoped to this app's own register/schema inside the service, so a caller
 * can never read or mutate an automation belonging to another app (IDOR-safe).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Standard NC controller
 *  collaborators (request, two services, session, group manager, l10n, logger).
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
 */
class AutomationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request      The request.
     * @param AutomationCrudService $crudService  The automation CRUD service.
     * @param IUserSession          $userSession  The user session.
     * @param IGroupManager         $groupManager The group manager.
     * @param IL10N                 $l10n         The localization service.
     * @param LoggerInterface       $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private AutomationCrudService $crudService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List automations (paginated).
     *
     * @return JSONResponse The paginated automation list.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $guard = $this->requireUser();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        $page    = (int) $this->request->getParam('page', 1);
        $trigger = (string) $this->request->getParam('trigger', '');

        return $this->run(
            action: fn (): array => $this->crudService->list(page: $page, trigger: $trigger),
            label: 'index'
        );
    }//end index()

    /**
     * Show a single automation.
     *
     * @param string $id The automation UUID.
     *
     * @return JSONResponse The automation.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $guard = $this->requireUser();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        return $this->run(
            action: fn (): array => ['automation' => $this->crudService->get(id: $id)],
            label: 'show',
            key: null
        );
    }//end show()

    /**
     * Create a new automation (admin only).
     *
     * @return JSONResponse The created automation.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        $data = $this->request->getParams();

        return $this->run(
            action: fn (): array => ['automation' => $this->crudService->create(data: $data)],
            label: 'create',
            key: null,
            success: Http::STATUS_CREATED
        );
    }//end create()

    /**
     * Update an existing automation (admin only).
     *
     * @param string $id The automation UUID.
     *
     * @return JSONResponse The updated automation.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        $data = $this->request->getParams();

        return $this->run(
            action: fn (): array => ['automation' => $this->crudService->update(id: $id, data: $data)],
            label: 'update',
            key: null
        );
    }//end update()

    /**
     * Activate an automation (admin only).
     *
     * @param string $id The automation UUID.
     *
     * @return JSONResponse The updated automation.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function activate(string $id): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        return $this->run(
            action: fn (): array => ['automation' => $this->crudService->setActive(id: $id, active: true)],
            label: 'activate',
            key: null
        );
    }//end activate()

    /**
     * Deactivate an automation (admin only).
     *
     * @param string $id The automation UUID.
     *
     * @return JSONResponse The updated automation.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function deactivate(string $id): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        return $this->run(
            action: fn (): array => ['automation' => $this->crudService->setActive(id: $id, active: false)],
            label: 'deactivate',
            key: null
        );
    }//end deactivate()

    /**
     * Delete an automation (admin only).
     *
     * @param string $id The automation UUID.
     *
     * @return JSONResponse The deletion result.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $guard = $this->requireAdmin();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        return $this->run(
            action: fn (): array => ['status' => $this->crudService->delete(id: $id)],
            label: 'destroy',
            key: null
        );
    }//end destroy()

    /**
     * Return the automationLog history for an automation.
     *
     * @param string $id The automation UUID.
     *
     * @return JSONResponse The execution history.
     *
     * @spec openspec/changes/crm-workflow-automation/tasks.md#task-3.1
     */
    #[NoAdminRequired]
    public function history(string $id): JSONResponse
    {
        $guard = $this->requireUser();
        if ($guard instanceof JSONResponse) {
            return $guard;
        }

        return $this->run(
            action: fn (): array => ['history' => $this->crudService->history(id: $id)],
            label: 'history',
            key: null
        );
    }//end history()

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

    /**
     * Require an authenticated Nextcloud admin.
     *
     * @return null|JSONResponse A 401/403 response when not an admin, else null.
     */
    private function requireAdmin(): ?JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        if ($this->groupManager->isAdmin($user->getUID()) === false) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Administrator privileges required')],
                Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }//end requireAdmin()

    /**
     * Run an action with shared error handling and a JSON envelope.
     *
     * @param callable    $action  The action returning the response payload.
     * @param string      $label   A short label for log context.
     * @param string|null $key     Optional envelope key wrapping the payload.
     * @param int         $success The success HTTP status.
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label, ?string $key='data', int $success=Http::STATUS_OK): JSONResponse
    {
        try {
            $payload = $action();
            if ($key !== null) {
                $payload = [$key => $payload];
            }

            return new JSONResponse($payload, $success);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\OCP\AppFramework\OCS\OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('AutomationController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
