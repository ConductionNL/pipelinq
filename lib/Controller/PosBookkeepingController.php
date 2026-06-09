<?php

/**
 * Pipelinq PosBookkeepingController.
 *
 * Thin controller for the POS end-of-day bookkeeping submission endpoint.
 * Authorisation, server-authoritative payload building and the
 * idempotent Shillinq POST live in PosBookkeepingService; this controller is
 * the HTTP edge. Plain CRUD on posZReport / posJournalEntryOutbound /
 * glAccountMapping is handled by OpenRegister's generic object API.
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
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosBookkeepingService;
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
 * Controller for the POS bookkeeping submission action.
 *
 * Authorization model: requires an authenticated user; the service enforces
 * the POS-manager (accounting role) predicate server-side and fails closed.
 * On success the service returns the updated outbound message including its
 * new status and (when scheduled) nextRetryAt.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.2
 */
class PosBookkeepingController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request     The request.
     * @param PosBookkeepingService $service     The bookkeeping service.
     * @param IUserSession          $userSession The user session.
     * @param IL10N                 $l10n        The localization service.
     * @param LoggerInterface       $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private PosBookkeepingService $service,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Submit (or resubmit) a posJournalEntryOutbound to Shillinq.
     *
     * Reads the outboundMessageId from the request body; the service performs
     * the role check, builds the GL payload, posts with the idempotency
     * header, handles 2xx/4xx/5xx and returns the persisted outbound.
     *
     * @return JSONResponse The persisted outbound message envelope, or an error.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.2
     */
    #[NoAdminRequired]
    public function post(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $outboundMessageId = (string) $this->request->getParam('outboundMessageId', '');
        if ($outboundMessageId === '') {
            return new JSONResponse(
                ['error' => $this->l10n->t('outboundMessageId is required')],
                Http::STATUS_BAD_REQUEST
            );
        }

        return $this->run(
            action: fn (): array => [
                'outbound' => $this->service->postToShillinq(
                    outboundMessageId: $outboundMessageId,
                    userId: $uid
                ),
            ],
            label: 'post'
        );
    }//end post()

    /**
     * Require an authenticated user, returning their UID.
     *
     * Returns a 401 JSONResponse when no user is in the session.
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
     * Maps the service's OCS exceptions to HTTP status codes:
     * 404 not found, 422 invalid transition / bad input, 403 manager-only.
     *
     * @param callable $action The action to run.
     * @param string   $label  A short label for log context.
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label): JSONResponse
    {
        try {
            return new JSONResponse($action(), Http::STATUS_ACCEPTED);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('PosBookkeepingController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
