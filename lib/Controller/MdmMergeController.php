<?php

/**
 * Pipelinq MdmMergeController.
 *
 * Data-steward / admin-gated merge tooling: preview, execute and reverse merges
 * of duplicate Master Entities. Merges are server-authoritative and audited;
 * the controller never trusts a client-supplied golden record — it only passes
 * the two ids and the reason to the MergeService (REQ-MDM-004).
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
 * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Mdm\DuplicateDetectionService;
use OCA\Pipelinq\Service\Mdm\MergeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for Master Entity merge operations.
 */
class MdmMergeController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                  $request     The request.
     * @param MergeService              $merge       The merge service.
     * @param DuplicateDetectionService $detection   The duplicate-detection service.
     * @param IUserSession              $userSession The user session.
     * @param LoggerInterface           $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private MergeService $merge,
        private DuplicateDetectionService $detection,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List duplicate candidates for an entity type (steward dashboard).
     *
     * @param string $entityType The entity type.
     *
     * @return JSONResponse The candidate list.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-002
     */
    #[NoAdminRequired]
    public function candidates(string $entityType): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $candidates = $this->detection->detectDuplicates($entityType);
        } catch (\Throwable $e) {
            $this->logger->warning('MDM candidates failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['message' => 'Duplicate detection failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['candidates' => $candidates]);
    }//end candidates()

    /**
     * Preview a merge (read-only, no side effects).
     *
     * @return JSONResponse The merge preview.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-004
     */
    #[NoAdminRequired]
    public function preview(): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $from = (string) $this->request->getParam('fromMasterId', '');
        $into = (string) $this->request->getParam('intoMasterId', '');
        if ($from === '' || $into === '') {
            return new JSONResponse(['message' => 'fromMasterId and intoMasterId are required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            return new JSONResponse($this->merge->previewMerge($from, $into));
        } catch (RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('MDM preview failed', ['exception' => $e]);
            return new JSONResponse(['message' => 'Merge preview failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end preview()

    /**
     * Execute a merge (data-steward / admin only).
     *
     * @return JSONResponse The created merge-operation.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-004
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function execute(): JSONResponse
    {
        $from   = (string) $this->request->getParam('fromMasterId', '');
        $into   = (string) $this->request->getParam('intoMasterId', '');
        $reason = (string) $this->request->getParam('mergeReason', 'data-stewardship-review');
        if ($from === '' || $into === '') {
            return new JSONResponse(['message' => 'fromMasterId and intoMasterId are required'], Http::STATUS_BAD_REQUEST);
        }

        $user   = $this->userSession->getUser();
        $userId = 'unknown';
        if ($user !== null) {
            $userId = $user->getUID();
        }

        try {
            $operation = $this->merge->executeMerge($from, $into, $userId, $reason);
            return new JSONResponse(['success' => true, 'mergeOperation' => $operation]);
        } catch (RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('MDM merge execute failed', ['exception' => $e]);
            return new JSONResponse(['message' => 'Merge failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end execute()

    /**
     * Reverse a merge within the 30-day window (data-steward / admin only).
     *
     * @param string $mergeOperationId The merge-operation uuid.
     *
     * @return JSONResponse The updated merge-operation.
     *
     * @spec openspec/changes/master-data-management/specs.md#REQ-MDM-004
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function reverse(string $mergeOperationId): JSONResponse
    {
        $user   = $this->userSession->getUser();
        $userId = 'unknown';
        if ($user !== null) {
            $userId = $user->getUID();
        }

        try {
            $operation = $this->merge->reverseMerge($mergeOperationId, $userId);
            return new JSONResponse(['success' => true, 'mergeOperation' => $operation]);
        } catch (RuntimeException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('MDM merge reverse failed', ['exception' => $e]);
            return new JSONResponse(['message' => 'Reversal failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end reverse()
}//end class
