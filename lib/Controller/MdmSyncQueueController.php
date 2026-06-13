<?php

/**
 * Pipelinq MdmSyncQueueController.
 *
 * Admin surface for the outbound downstream sync queue (REQ-MDM-006): list /
 * filter items, inspect dead-letters, and manually re-queue a failed item.
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Mdm\SyncQueueService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for sync-queue administration.
 */
class MdmSyncQueueController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request     The request.
     * @param SyncQueueService $syncQueue   The sync queue service.
     * @param IUserSession     $userSession The user session.
     * @param LoggerInterface  $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private SyncQueueService $syncQueue,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List sync-queue items, optionally filtered (authenticated stewards).
     *
     * @return JSONResponse The item list.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-006
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $status = (string) $this->request->getParam('status', '');
        $target = (string) $this->request->getParam('targetSystem', '');

        $statusFilter = null;
        if ($status !== '') {
            $statusFilter = $status;
        }

        $targetFilter = null;
        if ($target !== '') {
            $targetFilter = $target;
        }

        try {
            $items = $this->syncQueue->listItems(
                status: $statusFilter,
                targetSystem: $targetFilter
            );
            return new JSONResponse(['items' => $items]);
        } catch (\Throwable $e) {
            $this->logger->warning('MDM sync-queue list failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Could not list sync queue'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end index()

    /**
     * Manually re-queue a failed / dead-letter item (admin only).
     *
     * @param string $itemId The item uuid.
     *
     * @return JSONResponse The updated item.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-006
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function retry(string $itemId): JSONResponse
    {
        try {
            $item = $this->syncQueue->retryItem($itemId);
        } catch (\Throwable $e) {
            $this->logger->error('MDM sync-queue retry failed', ['exception' => $e]);
            return new JSONResponse(['message' => 'Retry failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        if ($item === null) {
            return new JSONResponse(['message' => 'Sync queue item not found'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(['success' => true, 'item' => $item]);
    }//end retry()
}//end class
