<?php

/**
 * Pipelinq PosRefundController.
 *
 * Thin controller for POS refund lifecycle actions (confirm, reject). All
 * business logic and authorization live in PosRefundService. CRUD on
 * posRefund / posRefundLine is handled by OpenRegister's generic object API.
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
 * @spec openspec/changes/pos-refund-return/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosRefundService;
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
 * Controller for POS refund lifecycle endpoints.
 *
 * Authorization model: every action requires an authenticated user. Confirm
 * (complete) and reject are applied through OpenRegister's TransitionEngine,
 * which runs PosRefundManagerGuard: both require a POS manager / admin, and
 * complete additionally enforces the cumulative over-refund cap — all
 * server-side and fail closed.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/pos-refund-return/tasks.md#2.2
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.2
 */
class PosRefundController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request     The request.
     * @param PosRefundService $service     The POS refund service.
     * @param IUserSession     $userSession The user session.
     * @param IL10N            $l10n        The localization service.
     * @param LoggerInterface  $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private PosRefundService $service,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Confirm a pending refund and emit reversal + stock events (manager only).
     *
     * @param string $id The refund UUID.
     *
     * @return JSONResponse The updated refund.
     *
     * @spec openspec/changes/pos-refund-return/tasks.md#2.2
     */
    #[NoAdminRequired]
    public function confirm(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => $this->service->confirmRefund(id: $id, userId: $uid),
            label: 'confirm'
        );
    }//end confirm()

    /**
     * Reject a pending refund with a reason (manager only).
     *
     * @param string $id The refund UUID.
     *
     * @return JSONResponse The updated refund.
     *
     * @spec openspec/changes/pos-refund-return/tasks.md#2.2
     */
    #[NoAdminRequired]
    public function reject(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $reason = (string) $this->request->getParam('reason', '');
        return $this->run(
            action: fn (): array => $this->service->rejectRefund(id: $id, reason: $reason, userId: $uid),
            label: 'reject'
        );
    }//end reject()

    /**
     * Require an authenticated user, returning their UID.
     *
     * Returns a 401 JSONResponse when no user is in the session. Object-level
     * access is then scoped to this app's own posRefund schema inside the service
     * (a refund in another app/register resolves to a 404), preventing IDOR.
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
     * 422 invalid transition / bad input / over-refund, 403 manager-only).
     *
     * @param callable $action The action to run.
     * @param string   $label  A short label for log context.
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label): JSONResponse
    {
        try {
            return new JSONResponse(['refund' => $action()]);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('PosRefundController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
