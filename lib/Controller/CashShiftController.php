<?php

/**
 * Pipelinq CashShiftController.
 *
 * Thin controller for POS cash drawer management lifecycle actions (open shift,
 * record drop, record count, approve diff, reject diff). CRUD operations on
 * cashShift / cashDrop / cashCount / cashDiff objects are handled by
 * OpenRegister's generic object API; these endpoints cover the lifecycle
 * transitions only.
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
 * @spec openspec/changes/pos-cash-management/tasks.md#4.1
 * @spec openspec/changes/pos-cash-management/tasks.md#10.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\CashShiftService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
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
 * Controller for POS cash drawer management lifecycle endpoints.
 *
 * Authorization model: all cash management actions require an authenticated
 * user. Manager-only actions (approveDiff / rejectDiff) additionally require
 * the POS manager group membership or Nextcloud admin, enforced by
 * CashShiftService via PosAccessPolicy. Per-object access (cashier can only
 * act on their own shift) is enforced in the service, closing the IDOR.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the collaborators a
 *  lifecycle controller legitimately needs (service, session, i18n, logger).
 *
 * @spec openspec/changes/pos-cash-management/tasks.md#4.1
 */
class CashShiftController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request     The request.
     * @param CashShiftService $service     The cash shift service.
     * @param IUserSession     $userSession The user session.
     * @param IL10N            $l10n        The localization service.
     * @param LoggerInterface  $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private CashShiftService $service,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Open a new cash shift with a declared float.
     *
     * @return JSONResponse The newly created cashShift object.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function openShift(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $drawer      = (string) $this->request->getParam('drawer', '');
        $floatAmount = (float) $this->request->getParam('floatAmount', 0);
        $reference   = (string) $this->request->getParam('reference', '');
        $notes       = (string) $this->request->getParam('notes', '');

        return $this->run(
            action: fn (): array => $this->service->openShift(
                drawer: $drawer,
                operator: $uid,
                floatAmount: $floatAmount,
                reference: $reference,
                notes: $notes,
            ),
            label: 'openShift',
            key: 'shift'
        );
    }//end openShift()

    /**
     * Record a mid-shift cash drop on an open shift.
     *
     * @param string $id The shift UUID.
     *
     * @return JSONResponse The newly created cashDrop object.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function recordDrop(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $amount = (float) $this->request->getParam('amount', 0);
        $reason = (string) $this->request->getParam('reason', '');

        return $this->run(
            action: fn (): array => $this->service->recordDrop(
                shiftId: $id,
                amount: $amount,
                droppedBy: $uid,
                reason: $reason,
            ),
            label: 'recordDrop',
            key: 'drop'
        );
    }//end recordDrop()

    /**
     * Record a blind count and close the shift (calculates diff automatically).
     *
     * @param string $id The shift UUID.
     *
     * @return JSONResponse The cashCount and cashDiff objects.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function recordCount(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $amount = (float) $this->request->getParam('amount', 0);
        $notes  = (string) $this->request->getParam('notes', '');

        return $this->run(
            action: fn (): array => $this->service->recordCount(
                shiftId: $id,
                amount: $amount,
                countedBy: $uid,
                notes: $notes,
            ),
            label: 'recordCount',
            key: 'result'
        );
    }//end recordCount()

    /**
     * Approve a pending cash variance diff (manager / admin only).
     *
     * @param string $id The shift UUID.
     *
     * @return JSONResponse The updated cashDiff object.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function approveDiff(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => $this->service->approveDiff(
                diffId: $id,
                approver: $uid,
            ),
            label: 'approveDiff',
            key: 'diff'
        );
    }//end approveDiff()

    /**
     * Reject a pending cash variance diff and reopen the shift (manager / admin only).
     *
     * @param string $id The shift UUID.
     *
     * @return JSONResponse The updated cashDiff object.
     *
     * @spec openspec/changes/pos-cash-management/tasks.md#4.1
     */
    #[NoAdminRequired]
    public function rejectDiff(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $reason = (string) $this->request->getParam('reason', '');

        return $this->run(
            action: fn (): array => $this->service->rejectDiff(
                diffId: $id,
                approver: $uid,
                reason: $reason,
            ),
            label: 'rejectDiff',
            key: 'diff'
        );
    }//end rejectDiff()

    /**
     * Require an authenticated user, returning their UID.
     *
     * Returns a 401 JSONResponse when no user is in the session. Every
     * lifecycle endpoint calls this before acting; object-level access is then
     * scoped to this app's own cashShift schema inside the service, preventing
     * IDOR.
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
     * Run a lifecycle action with shared error handling.
     *
     * Maps the service's OCS exceptions to HTTP status codes (404 not found,
     * 422 invalid transition / bad input, 403 manager-only).
     *
     * @param callable $action The action to run.
     * @param string   $label  A short label for log context.
     * @param string   $key    The response envelope key (default 'shift').
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label, string $key='shift'): JSONResponse
    {
        try {
            return new JSONResponse([$key => $action()]);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('CashShiftController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
