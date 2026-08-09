<?php

/**
 * Pipelinq PosStaffController.
 *
 * Thin controller for POS staff CRUD and the PIN-based authentication
 * endpoint used by the POS terminal. All business logic, hashing and lockout
 * enforcement live in PosStaffService.
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
use OCA\Pipelinq\Service\PosStaffService;
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
 * Controller for POS staff CRUD + PIN authentication endpoints.
 *
 * Authorization model:
 *  - Read/write CRUD (index/show/create/update/destroy) require an
 *    authenticated admin. The service additionally enforces schema-scope
 *    (per-object) so an admin endpoint can never operate on a foreign id.
 *  - authenticate accepts any logged-in user (the POS terminal session) and
 *    delegates PIN verification + lockout to PosStaffService::validatePin.
 *  - Every response runs through PosStaffService which strips `pinHash`
 *    before serialising; the bcrypt hash never leaves the service.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
 */
class PosStaffController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request      The request.
     * @param PosStaffService $service      The POS staff service.
     * @param IUserSession    $userSession  The user session.
     * @param IGroupManager   $groupManager The group manager (admin gate).
     * @param IL10N           $l10n         The localization service.
     * @param LoggerInterface $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private PosStaffService $service,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all staff (admin only). The bcrypt pinHash is stripped by the service.
     *
     * @auth admin-only Enumerates every POS staff record on the instance; the body additionally enforces it with an explicit requireAdmin() guard.
     *
     * @return JSONResponse The staff list.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4.2
     */
    public function index(): JSONResponse
    {
        $forbidden = $this->requireAdmin();
        if ($forbidden instanceof JSONResponse) {
            return $forbidden;
        }

        return $this->run(
            action: fn (): array => ['staff' => $this->service->listStaff()],
            label: 'index'
        );
    }//end index()

    /**
     * Get a single staff record (admin only). The bcrypt pinHash is stripped.
     *
     * @auth admin-only Reads one POS staff record including its permission set; the body additionally enforces it with an explicit requireAdmin() guard.
     *
     * @param string $id The staff UUID.
     *
     * @return JSONResponse The staff record.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4.2
     */
    public function show(string $id): JSONResponse
    {
        $forbidden = $this->requireAdmin();
        if ($forbidden instanceof JSONResponse) {
            return $forbidden;
        }

        $uid = $this->userSession->getUser()?->getUID() ?? '';

        // Per-object schema-scope check (ADR-005 Rule 3).
        $this->service->authorizeStaff(staffId: $id, userId: $uid);

        return $this->run(
            action: fn (): array => ['staff' => $this->service->getStaff(id: $id)],
            label: 'show'
        );
    }//end show()

    /**
     * Create a staff record (admin only).
     *
     * @auth admin-only Creates a POS staff identity and its PIN credential; the body additionally enforces it with an explicit requireAdmin() guard.
     *
     * @return JSONResponse The created staff record.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4.2
     */
    public function create(): JSONResponse
    {
        $forbidden = $this->requireAdmin();
        if ($forbidden instanceof JSONResponse) {
            return $forbidden;
        }

        return $this->run(
            action: fn (): array => ['staff' => $this->service->saveStaff(data: $this->payload())],
            label: 'create'
        );
    }//end create()

    /**
     * Update a staff record (admin only).
     *
     * @auth admin-only Rewrites a POS staff identity, including its PIN and permissions; the body additionally enforces it with an explicit requireAdmin() guard.
     *
     * @param string $id The staff UUID.
     *
     * @return JSONResponse The updated staff record.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4.2
     */
    public function update(string $id): JSONResponse
    {
        $forbidden = $this->requireAdmin();
        if ($forbidden instanceof JSONResponse) {
            return $forbidden;
        }

        $uid = $this->userSession->getUser()?->getUID() ?? '';

        // Per-object schema-scope check (ADR-005 Rule 3).
        $this->service->authorizeStaff(staffId: $id, userId: $uid);

        return $this->run(
            action: fn (): array => ['staff' => $this->service->saveStaff(data: $this->payload(), id: $id)],
            label: 'update'
        );
    }//end update()

    /**
     * Delete a staff record (admin only).
     *
     * @auth admin-only Removes a POS staff identity and revokes its till access; the body additionally enforces it with an explicit requireAdmin() guard.
     *
     * @param string $id The staff UUID.
     *
     * @return JSONResponse Empty payload on success.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4.2
     */
    public function destroy(string $id): JSONResponse
    {
        $forbidden = $this->requireAdmin();
        if ($forbidden instanceof JSONResponse) {
            return $forbidden;
        }

        $uid = $this->userSession->getUser()?->getUID() ?? '';

        // Per-object schema-scope check (ADR-005 Rule 3).
        $this->service->authorizeStaff(staffId: $id, userId: $uid);

        return $this->run(
            action: function () use ($id): array {
                $this->service->deleteStaff(id: $id);
                return ['deleted' => true];
            },
            label: 'destroy'
        );
    }//end destroy()

    /**
     * Authenticate a staff member at a POS terminal using their PIN.
     *
     * Any logged-in user may call this endpoint (the cashier UI runs in their
     * Nextcloud session). PosStaffService::validatePin owns the lockout and
     * counter logic and returns the role permission matrix on success. The
     * bcrypt hash is never written to the response.
     *
     * @return JSONResponse The session envelope on success; 403 on lockout / bad PIN / inactive.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4.2
     */
    #[NoAdminRequired]
    public function authenticate(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $staffId = (string) $this->request->getParam('staffId', '');
        $pin     = (string) $this->request->getParam('pin', '');

        if ($staffId === '' || $pin === '') {
            return new JSONResponse(
                ['error' => $this->l10n->t('Staff and PIN are required')],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return $this->run(
            action: fn (): array => ['session' => $this->service->validatePin(staffId: $staffId, pin: $pin)],
            label: 'authenticate'
        );
    }//end authenticate()

    /**
     * Read the staff payload from the request body.
     *
     * @return array<string, mixed> The request payload.
     */
    private function payload(): array
    {
        $payload = [
            'displayName' => (string) $this->request->getParam('displayName', ''),
            'userId'      => (string) $this->request->getParam('userId', ''),
            'posRole'     => (string) $this->request->getParam('posRole', ''),
            'isActive'    => (bool) $this->request->getParam('isActive', true),
            'pin'         => (string) $this->request->getParam('pin', ''),
        ];

        return $payload;
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
            $this->logger->error('PosStaffController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
