<?php

/**
 * Pipelinq MdmAvgWorkflowController.
 *
 * Admin surface for the AVG (GDPR art. 17) right-of-deletion workflow
 * (REQ-MDM-009): initiate, approve-and-execute, and confirm the hard delete
 * after the cooling-off period. Every step is admin-gated and server-driven;
 * the client only supplies the target id and the GDPR request reference.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-009
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Mdm\AVGWorkflowService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for the AVG right-of-deletion workflow.
 */
class MdmAvgWorkflowController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request     The request.
     * @param AVGWorkflowService $avg         The AVG workflow service.
     * @param IUserSession       $userSession The user session.
     * @param LoggerInterface    $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private AVGWorkflowService $avg,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List entities eligible for hard delete (admin only).
     *
     * @return JSONResponse The candidate list.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-009
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function candidates(): JSONResponse
    {
        try {
            return new JSONResponse(['candidates' => $this->avg->listHardDeleteCandidates()]);
        } catch (\Throwable $e) {
            $this->logger->warning('MDM AVG candidates failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Could not list candidates'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end candidates()

    /**
     * Initiate a right-of-deletion request (admin only).
     *
     * @return JSONResponse The pending-review summary.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-009
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function initiate(): JSONResponse
    {
        $masterId      = (string) $this->request->getParam('masterEntityId', '');
        $gdprRequestId = (string) $this->request->getParam('gdprRequestId', '');
        if ($masterId === '' || $gdprRequestId === '') {
            return new JSONResponse(['message' => 'masterEntityId and gdprRequestId are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            return new JSONResponse($this->avg->initiateRightOfDeletion($masterId, $gdprRequestId));
        } catch (RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('MDM AVG initiate failed', ['exception' => $e]);
            return new JSONResponse(['message' => 'Initiation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end initiate()

    /**
     * Approve and execute a right-of-deletion (admin only).
     *
     * @return JSONResponse The execution summary.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-009
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function approve(): JSONResponse
    {
        $masterId      = (string) $this->request->getParam('masterEntityId', '');
        $gdprRequestId = (string) $this->request->getParam('gdprRequestId', '');
        if ($masterId === '' || $gdprRequestId === '') {
            return new JSONResponse(['message' => 'masterEntityId and gdprRequestId are required'], Http::STATUS_BAD_REQUEST);
        }

        $user   = $this->userSession->getUser();
        $userId = 'unknown';
        if ($user !== null) {
            $userId = $user->getUID();
        }

        try {
            return new JSONResponse($this->avg->approveAndExecuteRightOfDeletion($masterId, $gdprRequestId, $userId));
        } catch (RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('MDM AVG approve failed', ['exception' => $e]);
            return new JSONResponse(['message' => 'Approval failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end approve()

    /**
     * Confirm and execute the hard delete after cooling-off (admin only).
     *
     * @param string $masterEntityId The master entity uuid.
     *
     * @return JSONResponse The hard-delete summary.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-009
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function confirmHardDelete(string $masterEntityId): JSONResponse
    {
        $user   = $this->userSession->getUser();
        $userId = 'unknown';
        if ($user !== null) {
            $userId = $user->getUID();
        }

        try {
            return new JSONResponse($this->avg->confirmHardDelete($masterEntityId, $userId));
        } catch (RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('MDM AVG hard-delete failed', ['exception' => $e]);
            return new JSONResponse(['message' => 'Hard delete failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end confirmHardDelete()
}//end class
