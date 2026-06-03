<?php

/**
 * Pipelinq PosPaymentController.
 *
 * Thin controller for the POS multi-tender payment surface: tender-type
 * configuration (admin-gated create/update/delete; open read) and per-transaction
 * tenders (add / list / remove). All business logic, validation and per-object
 * authorization live in PosPaymentService.
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
 * @spec openspec/changes/pos-split-tender/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosPaymentService;
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
 * Controller for POS multi-tender payment endpoints.
 *
 * Authorization model:
 *   - Tender-type reads (list/detail) require only an authenticated user.
 *   - Tender-type writes (create/update/delete) require a Nextcloud admin; the
 *     gate is enforced here and fails closed.
 *   - Tender add/list/remove require an authenticated user; the per-transaction
 *     ownership/group/admin check (closing the IDOR) and the settled-transaction
 *     refusal are enforced inside PosPaymentService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Thin controller wiring the
 *  request, payment service, session, group manager, l10n and logger it needs.
 *
 * @spec openspec/changes/pos-split-tender/tasks.md#4.1
 */
class PosPaymentController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request      The request.
     * @param PosPaymentService $service      The POS payment service.
     * @param IUserSession      $userSession  The user session.
     * @param IGroupManager     $groupManager The group manager (admin gate).
     * @param IL10N             $l10n         The localization service.
     * @param LoggerInterface   $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private PosPaymentService $service,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List the active tender types (sorted by sortOrder).
     *
     * @return JSONResponse The active tender types.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.2
     */
    #[NoAdminRequired]
    public function tenderTypes(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => ['tenderTypes' => $this->service->getActiveTenderTypes()],
            label: 'tenderTypes'
        );
    }//end tenderTypes()

    /**
     * Get a single tender type by id.
     *
     * @param string $id The tender type UUID.
     *
     * @return JSONResponse The tender type.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.3
     */
    #[NoAdminRequired]
    public function showTenderType(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => ['tenderType' => $this->service->getTenderType(id: $id)],
            label: 'showTenderType'
        );
    }//end showTenderType()

    /**
     * Create a tender type (admin only).
     *
     * @return JSONResponse The created tender type (HTTP 201) or an error.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.4
     */
    #[NoAdminRequired]
    public function createTenderType(): JSONResponse
    {
        $gate = $this->requireAdmin();
        if ($gate instanceof JSONResponse) {
            return $gate;
        }

        return $this->run(
            action: fn (): array => ['tenderType' => $this->service->createTenderType(input: $this->tenderTypeInput())],
            label: 'createTenderType',
            successStatus: Http::STATUS_CREATED
        );
    }//end createTenderType()

    /**
     * Update a tender type (admin only).
     *
     * @param string $id The tender type UUID.
     *
     * @return JSONResponse The updated tender type or an error.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.5
     */
    #[NoAdminRequired]
    public function updateTenderType(string $id): JSONResponse
    {
        $gate = $this->requireAdmin();
        if ($gate instanceof JSONResponse) {
            return $gate;
        }

        return $this->run(
            action: fn (): array => [
                'tenderType' => $this->service->updateTenderType(id: $id, input: $this->tenderTypeInput()),
            ],
            label: 'updateTenderType'
        );
    }//end updateTenderType()

    /**
     * Delete a tender type (admin only; blocked when tenders reference it).
     *
     * @param string $id The tender type UUID.
     *
     * @return JSONResponse An empty 204 or an error.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#4.6
     */
    #[NoAdminRequired]
    public function destroyTenderType(string $id): JSONResponse
    {
        $gate = $this->requireAdmin();
        if ($gate instanceof JSONResponse) {
            return $gate;
        }

        try {
            $this->service->deleteTenderType(id: $id);
            return new JSONResponse([], Http::STATUS_NO_CONTENT);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error('PosPaymentController::destroyTenderType failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end destroyTenderType()

    /**
     * List the tenders on a transaction.
     *
     * @param string $transactionId The transaction UUID.
     *
     * @return JSONResponse The tenders.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#5.1
     */
    #[NoAdminRequired]
    public function tenders(string $transactionId): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => [
                'tenders'        => $this->service->getTendersForTransaction(transactionId: $transactionId),
                'reconciliation' => $this->service->validateTenderSum(transactionId: $transactionId),
            ],
            label: 'tenders'
        );
    }//end tenders()

    /**
     * Add a tender to a transaction.
     *
     * @param string $transactionId The transaction UUID.
     *
     * @return JSONResponse The created tender (HTTP 201) plus the updated reconciliation.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#5.2
     */
    #[NoAdminRequired]
    public function addTender(string $transactionId): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $input = [
            'tenderType' => (string) $this->request->getParam('tenderType', ''),
            'amount'     => $this->request->getParam('amount', 0),
            'reference'  => (string) $this->request->getParam('reference', ''),
            'notes'      => (string) $this->request->getParam('notes', ''),
            'sortOrder'  => (int) $this->request->getParam('sortOrder', 0),
        ];

        return $this->run(
            action: function () use ($transactionId, $input, $uid): array {
                $tender = $this->service->addTender(transactionId: $transactionId, tender: $input, userId: $uid);
                return [
                    'tender'         => $tender,
                    'reconciliation' => $this->service->validateTenderSum(transactionId: $transactionId),
                ];
            },
            label: 'addTender',
            successStatus: Http::STATUS_CREATED
        );
    }//end addTender()

    /**
     * Remove a tender from a transaction.
     *
     * @param string $transactionId The transaction UUID.
     * @param string $tenderId      The tender UUID.
     *
     * @return JSONResponse An empty 204 or an error.
     *
     * @spec openspec/changes/pos-split-tender/tasks.md#5.3
     */
    #[NoAdminRequired]
    public function removeTender(string $transactionId, string $tenderId): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        try {
            $this->service->removeTender(transactionId: $transactionId, tenderId: $tenderId, userId: $uid);
            return new JSONResponse([], Http::STATUS_NO_CONTENT);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error('PosPaymentController::removeTender failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end removeTender()

    /**
     * Collect the tender-type fields from the request body.
     *
     * @return array<string, mixed> The raw tender-type input.
     */
    private function tenderTypeInput(): array
    {
        return [
            'name'              => (string) $this->request->getParam('name', ''),
            'code'              => (string) $this->request->getParam('code', ''),
            'description'       => (string) $this->request->getParam('description', ''),
            'glAccount'         => (string) $this->request->getParam('glAccount', ''),
            'requiresReference' => (bool) $this->request->getParam('requiresReference', false),
            'requiresPin'       => (bool) $this->request->getParam('requiresPin', false),
            'allowsChange'      => (bool) $this->request->getParam('allowsChange', false),
            'isActive'          => (bool) $this->request->getParam('isActive', true),
            'sortOrder'         => (int) $this->request->getParam('sortOrder', 0),
        ];
    }//end tenderTypeInput()

    /**
     * Require an authenticated user, returning their UID or a 401 response.
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
     * Require a Nextcloud admin, returning null-equivalent on success.
     *
     * @return JSONResponse|null A 401/403 response on failure, or null when the
     *                           caller is an authenticated admin.
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
                ['error' => $this->l10n->t('Administrator privileges are required to manage tender types')],
                Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }//end requireAdmin()

    /**
     * Run a service action with shared OCS-exception → HTTP mapping.
     *
     * @param callable $action        The action returning the response payload array.
     * @param string   $label         A short label for log context.
     * @param int      $successStatus The HTTP status on success (default 200).
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label, int $successStatus=Http::STATUS_OK): JSONResponse
    {
        try {
            return new JSONResponse($action(), $successStatus);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('PosPaymentController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
