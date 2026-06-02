<?php

/**
 * Pipelinq PosStaffController.
 *
 * Thin controller for POS staff management and PIN authentication. Management
 * (index/show/create/update/destroy) is gated to app admins via
 * #[AuthorizedAdminSetting]; the PIN authentication endpoint is available to POS
 * operators (#[NoAdminRequired]) but is additionally restricted to members of
 * the POS group / admins through PosAccessPolicy, so an arbitrary authenticated
 * user cannot brute-force staff PINs. pinHash is never returned by any path; the
 * service strips it. No stack traces reach the client.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
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
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosStaffService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for POS staff endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the service, access
 *  policy, session, l10n and logger a staff/PIN controller legitimately needs.
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
     * @param PosAccessPolicy $accessPolicy The POS access policy.
     * @param IUserSession    $userSession  The user session.
     * @param IL10N           $l10n         The localization service.
     * @param LoggerInterface $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private PosStaffService $service,
        private PosAccessPolicy $accessPolicy,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all staff (admin only; pinHash stripped).
     *
     * @return JSONResponse The staff members.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function index(): JSONResponse
    {
        return $this->run(action: fn (): array => ['staff' => $this->service->listStaff()], label: 'index');
    }//end index()

    /**
     * Get a single staff member (admin only; pinHash stripped).
     *
     * @param string $id The staff UUID.
     *
     * @return JSONResponse The staff member.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function show(string $id): JSONResponse
    {
        return $this->run(action: fn (): array => ['staff' => $this->service->getStaff(id: $id)], label: 'show');
    }//end show()

    /**
     * Create a staff member (admin only).
     *
     * @return JSONResponse The created staff member (no pinHash).
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function create(): JSONResponse
    {
        $data = $this->staffParams();
        return $this->run(
            action: fn (): array => ['staff' => $this->service->saveStaff(data: $data)],
            label: 'create',
            status: Http::STATUS_CREATED
        );
    }//end create()

    /**
     * Update a staff member (admin only).
     *
     * @param string $id The staff UUID.
     *
     * @return JSONResponse The updated staff member (no pinHash).
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function update(string $id): JSONResponse
    {
        $data = $this->staffParams();
        return $this->run(
            action: fn (): array => ['staff' => $this->service->saveStaff(data: $data, id: $id)],
            label: 'update'
        );
    }//end update()

    /**
     * Delete a staff member (admin only).
     *
     * @param string $id The staff UUID.
     *
     * @return JSONResponse An empty success response.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function destroy(string $id): JSONResponse
    {
        return $this->run(
            action: function () use ($id): array {
                $this->service->deleteStaff(id: $id);
                return ['status' => 'deleted'];
            },
            label: 'destroy'
        );
    }//end destroy()

    /**
     * Authenticate a staff member by PIN and open a session payload.
     *
     * Available to POS operators (NoAdminRequired) but restricted to members of
     * the POS group / admins via PosAccessPolicy::isPosUser, so an arbitrary
     * authenticated user cannot probe staff PINs. The PIN is verified
     * server-side; only the resulting session payload (staffId, displayName,
     * permission matrix) is returned — never the hash.
     *
     * @return JSONResponse The session payload, or an auth error.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
     */
    #[NoAdminRequired]
    public function authenticate(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        if ($this->accessPolicy->isPosUser(userId: $user->getUID()) === false) {
            return new JSONResponse(
                ['error' => $this->l10n->t('You are not permitted to use the point of sale')],
                Http::STATUS_FORBIDDEN
            );
        }

        $staffId = (string) $this->request->getParam('staffId', '');
        $pin     = (string) $this->request->getParam('pin', '');

        return $this->run(
            action: fn (): array => $this->service->validatePin(staffId: $staffId, pin: $pin),
            label: 'authenticate'
        );
    }//end authenticate()

    /**
     * Read the staff fields from the request body.
     *
     * @return array<string, mixed> The staff data (may carry a plain-text `pin`).
     */
    private function staffParams(): array
    {
        return [
            'displayName' => (string) $this->request->getParam('displayName', ''),
            'userId'      => (string) $this->request->getParam('userId', ''),
            'posRole'     => (string) $this->request->getParam('posRole', ''),
            'pin'         => (string) $this->request->getParam('pin', ''),
            'isActive'    => $this->boolParam(key: 'isActive', default: true),
        ];
    }//end staffParams()

    /**
     * Read a boolean request parameter (tolerating "true"/"false"/1/0).
     *
     * @param string $key     The parameter name.
     * @param bool   $default The default when the parameter is absent.
     *
     * @return bool The parsed boolean.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $default is the absent-value fallback.
     */
    private function boolParam(string $key, bool $default=false): bool
    {
        $value = $this->request->getParam($key, null);
        if ($value === null) {
            return $default;
        }

        if (is_bool($value) === true) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }//end boolParam()

    /**
     * Run an action with shared error handling.
     *
     * Maps the service's OCS exceptions to HTTP status codes (404 not found,
     * 422 invalid input, 403 forbidden / inactive / locked / wrong PIN).
     * Unexpected errors are logged and returned as a generic 500 (no stack
     * trace to the client).
     *
     * @param callable $action The action returning the response payload.
     * @param string   $label  A short label for log context.
     * @param int      $status The success HTTP status.
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label, int $status=Http::STATUS_OK): JSONResponse
    {
        try {
            return new JSONResponse($action(), $status);
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
