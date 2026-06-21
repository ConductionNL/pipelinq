<?php

/**
 * Pipelinq PosRoleController.
 *
 * Thin controller for POS role CRUD. Read actions are NoAdminRequired so the
 * POS terminal can resolve role names for any active session; write actions
 * (create/update/destroy) require an admin user.
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
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosRoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for POS role CRUD endpoints.
 *
 * Authorization model: every action requires an authenticated user. Read
 * actions (index/show) are open to any logged-in user (POS terminal needs to
 * resolve role data); create/update/destroy require admin membership and run
 * a per-object schema-scope check inside the service so an attacker cannot
 * pivot to objects in another app's register.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
 */
class PosRoleController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request      The request.
     * @param PosRoleService  $service      The POS role service.
     * @param IUserSession    $userSession  The user session.
     * @param IGroupManager   $groupManager The group manager (admin gate).
     * @param IL10N           $l10n         The localization service.
     * @param LoggerInterface $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private PosRoleService $service,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all POS roles.
     *
     * @return JSONResponse The role list.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => ['roles' => $this->service->listRoles()],
            label: 'index'
        );
    }//end index()

    /**
     * Get a single POS role.
     *
     * @param string $id The role UUID.
     *
     * @return JSONResponse The role.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => ['role' => $this->service->getRole(id: $id)],
            label: 'show'
        );
    }//end show()

    /**
     * Create a POS role (admin only).
     *
     * @return JSONResponse The created role.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4.1
     */
    public function create(): JSONResponse
    {
        $forbidden = $this->requireAdmin();
        if ($forbidden instanceof JSONResponse) {
            return $forbidden;
        }

        return $this->run(
            action: fn (): array => ['role' => $this->service->saveRole(data: $this->payload())],
            label: 'create'
        );
    }//end create()

    /**
     * Update a POS role (admin only).
     *
     * @param string $id The role UUID.
     *
     * @return JSONResponse The updated role.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4.1
     */
    public function update(string $id): JSONResponse
    {
        $forbidden = $this->requireAdmin();
        if ($forbidden instanceof JSONResponse) {
            return $forbidden;
        }

        // Per-object schema-scope check (ADR-005 Rule 3): the service throws
        // 404 if $id is not a posRole in this app's register, preventing IDOR.
        $this->service->getRole(id: $id);

        return $this->run(
            action: fn (): array => ['role' => $this->service->saveRole(data: $this->payload(), id: $id)],
            label: 'update'
        );
    }//end update()

    /**
     * Delete a POS role (admin only).
     *
     * @param string $id The role UUID.
     *
     * @return JSONResponse Empty payload on success.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4.1
     */
    public function destroy(string $id): JSONResponse
    {
        $forbidden = $this->requireAdmin();
        if ($forbidden instanceof JSONResponse) {
            return $forbidden;
        }

        return $this->run(
            action: function () use ($id): array {
                $this->service->deleteRole(id: $id);
                return ['deleted' => true];
            },
            label: 'destroy'
        );
    }//end destroy()

    /**
     * Read the role payload from the request body.
     *
     * @return array<string, mixed> The request payload.
     */
    private function payload(): array
    {
        return [
            'name'               => (string) $this->request->getParam('name', ''),
            'description'        => (string) $this->request->getParam('description', ''),
            'canVoid'            => (bool) $this->request->getParam('canVoid', false),
            'maxDiscountPercent' => (int) $this->request->getParam('maxDiscountPercent', 0),
            'canRefund'          => (bool) $this->request->getParam('canRefund', false),
            'canNoSale'          => (bool) $this->request->getParam('canNoSale', false),
        ];
    }//end payload()

    /**
     * Require an authenticated user.
     *
     * @return string|JSONResponse The acting user UID, or a 401 response.
     */
    private function requireUserId(): string|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        return $user->getUID();
    }//end requireUserId()

    /**
     * Require an authenticated admin user.
     *
     * @return null|JSONResponse Null when the caller is an admin; 401/403 otherwise.
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
                ['error' => $this->l10n->t('Admin privileges required')],
                Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }//end requireAdmin()

    /**
     * Run an action with shared error handling.
     *
     * @param callable $action The action.
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
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('PosRoleController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
