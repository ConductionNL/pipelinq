<?php

/**
 * Pipelinq PosTransactionController.
 *
 * Thin controller for POS transaction lifecycle actions (confirm, settle,
 * refund, park, resume). All business logic and authorization live in
 * PosTransactionService.
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
 * @spec openspec/changes/pos-transaction-core/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosTransactionService;
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
 * Controller for POS transaction lifecycle endpoints.
 *
 * Authorization model: every action requires an authenticated user. The
 * cashier-level transitions (confirm/settle/park/resume) and refund are applied
 * through OpenRegister's TransitionEngine, which runs the registered
 * per-object lifecycle guards: confirm/settle/park/resume require the
 * transaction's own cashier, a POS-group member, or an admin (closing the
 * IDOR), and refund requires a POS manager / admin. taxReport (a cross-object
 * report) is manager-only, enforced in the service.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/pos-transaction-core/tasks.md#2.2
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#3.1
 */
class PosTransactionController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request     The request.
     * @param PosTransactionService $service     The POS transaction service.
     * @param IUserSession          $userSession The user session.
     * @param IL10N                 $l10n        The localization service.
     * @param LoggerInterface       $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private PosTransactionService $service,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Confirm a draft or parked transaction.
     *
     * @param string $id The transaction UUID.
     *
     * @return JSONResponse The updated transaction.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.2
     */
    #[NoAdminRequired]
    public function confirm(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => $this->service->confirmTransaction(id: $id, userId: $uid),
            label: 'confirm'
        );
    }//end confirm()

    /**
     * Settle a confirmed transaction.
     *
     * @param string $id The transaction UUID.
     *
     * @return JSONResponse The updated transaction.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.2
     */
    #[NoAdminRequired]
    public function settle(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => $this->service->settleTransaction(id: $id, userId: $uid),
            label: 'settle'
        );
    }//end settle()

    /**
     * Refund / void a confirmed or settled transaction (manager only).
     *
     * @param string $id The transaction UUID.
     *
     * @return JSONResponse The updated transaction.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.2
     */
    #[NoAdminRequired]
    public function refund(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $reason = (string) $this->request->getParam('reason', '');
        return $this->run(
            action: fn (): array => $this->service->refundTransaction(id: $id, reason: $reason, userId: $uid),
            label: 'refund'
        );
    }//end refund()

    /**
     * Park a draft transaction.
     *
     * @param string $id The transaction UUID.
     *
     * @return JSONResponse The updated transaction.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.2
     */
    #[NoAdminRequired]
    public function park(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => $this->service->parkTransaction(id: $id, userId: $uid),
            label: 'park'
        );
    }//end park()

    /**
     * Resume a parked transaction.
     *
     * @param string $id The transaction UUID.
     *
     * @return JSONResponse The updated transaction.
     *
     * @spec openspec/changes/pos-transaction-core/tasks.md#2.2
     */
    #[NoAdminRequired]
    public function resume(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => $this->service->resumeTransaction(id: $id, userId: $uid),
            label: 'resume'
        );
    }//end resume()

    /**
     * Per-rate BTW compliance report for shillinq GL posting.
     *
     * Aggregates every fiscally-final transaction (confirmed / settled /
     * refunded) into a per-rate base + tax split. Refunds are netted out.
     * Read-only and computed server-side from the persisted, server-authoritative
     * breakdowns — no client figures are trusted. An optional `status` query
     * param narrows the report (e.g. only `settled`).
     *
     * @return JSONResponse The aggregated report.
     *
     * @spec openspec/specs/pos-nl-btw-engine/spec.md
     */
    #[NoAdminRequired]
    public function taxReport(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $statusParam = (string) $this->request->getParam('status', '');
        $status      = null;
        if ($statusParam !== '') {
            $status = $statusParam;
        }

        return $this->run(
            action: fn (): array => $this->service->taxReport(status: $status, userId: $uid),
            label: 'taxReport',
            key: 'report'
        );
    }//end taxReport()

    /**
     * Require an authenticated user, returning their UID.
     *
     * Returns a 401 JSONResponse when no user is in the session. Every
     * lifecycle endpoint calls this before acting; object-level access is then
     * scoped to this app's own posTransaction schema inside the service (a
     * transaction in another app/register resolves to a 404), preventing IDOR.
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
     * @param string   $key    The response envelope key (default 'transaction').
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label, string $key='transaction'): JSONResponse
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
            $this->logger->error('PosTransactionController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
