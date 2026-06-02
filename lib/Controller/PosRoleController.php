<?php

/**
 * Pipelinq PosRoleController.
 *
 * Thin controller for POS role management. Reads (index/show) are available to
 * any authenticated user so the POS terminal and staff admin form can list and
 * preview roles; writes (create/update/destroy) are gated to app admins via
 * #[AuthorizedAdminSetting]. All business rules (discount bound, delete-while-
 * assigned guard) live in PosRoleService, scoped to this app's own schema so a
 * foreign id resolves to a 404 (IDOR-safe). No stack traces reach the client.
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
use OCA\Pipelinq\Service\PosRoleService;
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
use Psr\Log\LoggerInterface;

/**
 * Controller for POS role endpoints.
 *
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
 */
class PosRoleController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest        $request The request.
     * @param PosRoleService  $service The POS role service.
     * @param IL10N           $l10n    The localization service.
     * @param LoggerInterface $logger  The logger.
     */
    public function __construct(
        IRequest $request,
        private PosRoleService $service,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List all POS roles (any authenticated user).
     *
     * @return JSONResponse The roles.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        return $this->run(action: fn (): array => ['roles' => $this->service->listRoles()], label: 'index');
    }//end index()

    /**
     * Get a single POS role (any authenticated user).
     *
     * @param string $id The role UUID.
     *
     * @return JSONResponse The role.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        return $this->run(action: fn (): array => ['role' => $this->service->getRole(id: $id)], label: 'show');
    }//end show()

    /**
     * Create a POS role (admin only).
     *
     * @return JSONResponse The created role.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function create(): JSONResponse
    {
        $data = $this->roleParams();
        return $this->run(
            action: fn (): array => ['role' => $this->service->saveRole(data: $data)],
            label: 'create',
            status: Http::STATUS_CREATED
        );
    }//end create()

    /**
     * Update a POS role (admin only).
     *
     * @param string $id The role UUID.
     *
     * @return JSONResponse The updated role.
     *
     * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#4
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function update(string $id): JSONResponse
    {
        $data = $this->roleParams();
        return $this->run(
            action: fn (): array => ['role' => $this->service->saveRole(data: $data, id: $id)],
            label: 'update'
        );
    }//end update()

    /**
     * Delete a POS role (admin only).
     *
     * @param string $id The role UUID.
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
                $this->service->deleteRole(id: $id);
                return ['status' => 'deleted'];
            },
            label: 'destroy'
        );
    }//end destroy()

    /**
     * Read the role fields from the request body.
     *
     * @return array<string, mixed> The role data.
     */
    private function roleParams(): array
    {
        return [
            'name'               => (string) $this->request->getParam('name', ''),
            'description'        => (string) $this->request->getParam('description', ''),
            'canVoid'            => $this->boolParam(key: 'canVoid'),
            'maxDiscountPercent' => (int) $this->request->getParam('maxDiscountPercent', 0),
            'canRefund'          => $this->boolParam(key: 'canRefund'),
            'canNoSale'          => $this->boolParam(key: 'canNoSale'),
        ];
    }//end roleParams()

    /**
     * Read a boolean request parameter (tolerating "true"/"false"/1/0).
     *
     * @param string $key The parameter name.
     *
     * @return bool The parsed boolean.
     */
    private function boolParam(string $key): bool
    {
        $value = $this->request->getParam($key, false);
        if (is_bool($value) === true) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }//end boolParam()

    /**
     * Run an action with shared error handling.
     *
     * Maps the service's OCS exceptions to HTTP status codes (404 not found,
     * 422 invalid input, 403 forbidden). Unexpected errors are logged and
     * returned as a generic 500 (no stack trace to the client).
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
            $this->logger->error('PosRoleController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
