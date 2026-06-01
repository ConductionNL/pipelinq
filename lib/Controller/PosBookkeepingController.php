<?php

/**
 * Pipelinq PosBookkeepingController.
 *
 * Controller for POS end-of-day bookkeeping actions: manual trigger/resubmit
 * of a posJournalEntryOutbound to Shillinq.
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
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for POS bookkeeping submission endpoints.
 *
 * Authorization model: only authenticated users with the configured
 * POS accounting group (or admins) may trigger manual Shillinq submission.
 * Object-level access is delegated to PosBookkeepingService which validates
 * status transitions, preventing unauthorized state mutation.
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.2
 */
class PosBookkeepingController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest              $request      The HTTP request.
     * @param PosBookkeepingService $service      The bookkeeping service.
     * @param IUserSession          $userSession  The current user session.
     * @param IGroupManager         $groupManager The NC group manager.
     * @param LoggerInterface       $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private PosBookkeepingService $service,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Manually trigger or resubmit a posJournalEntryOutbound to Shillinq.
     *
     * Requires the authenticated user to hold the POS accounting role (member of
     * 'pos-accounting' group) or be a Nextcloud admin. Returns 202 on acceptance,
     * 403 if unauthorized, 404 if outbound message not found, 422 if precondition fails.
     *
     * @return JSONResponse The submission result.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.2
     */
    #[NoAdminRequired]
    public function post(): JSONResponse
    {
        // Require authenticated user.
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Verify accounting role.
        if ($this->hasAccountingRole(userId: $user->getUID()) === false) {
            return new JSONResponse(
                ['error' => 'Accounting role required to submit journal entries'],
                Http::STATUS_FORBIDDEN
            );
        }

        $outboundMessageId = (string) $this->request->getParam('outboundMessageId', '');
        if ($outboundMessageId === '') {
            return new JSONResponse(
                ['error' => 'outboundMessageId is required'],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        try {
            $result = $this->service->postToShillinq(outboundMessageId: $outboundMessageId);
            return new JSONResponse($result, Http::STATUS_ACCEPTED);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error(
                'PosBookkeepingController::post failed',
                ['exception' => $e->getMessage()]
            );
            return new JSONResponse(
                ['error' => 'An unexpected error occurred'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }
    }//end post()

    /**
     * Check whether a user has the POS accounting role.
     *
     * A user qualifies if they are a Nextcloud admin OR a member of the
     * 'pos-accounting' group (configurable via admin settings).
     *
     * @param string $userId The user ID to check.
     *
     * @return bool True if the user has the accounting role.
     *
     * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#2.2
     */
    private function hasAccountingRole(string $userId): bool
    {
        // NC admins always qualify.
        if ($this->groupManager->isAdmin(userId: $userId) === true) {
            return true;
        }

        // Check POS accounting group membership.
        $group = $this->groupManager->get(gid: 'pos-accounting');
        if ($group !== null && $group->inGroup(user: $this->userSession->getUser()) === true) {
            return true;
        }

        return false;
    }//end hasAccountingRole()
}//end class
