<?php

/**
 * Pipelinq BerichtenboxAdminController.
 *
 * Admin-only operations for the Berichtenbox bridge: manually re-queue a failed
 * message and read aggregate delivery statistics. Both endpoints require the
 * Nextcloud app admin-settings permission (ADR-005); no per-citizen BSN data is
 * exposed.
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-OPERATIONS-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BerichtenboxStatsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Admin endpoints for Berichtenbox delivery operations.
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#REQ-OPERATIONS-010
 */
class BerichtenboxAdminController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                 $request      The request.
     * @param BerichtenboxStatsService $statsService The stats / retry service.
     * @param LoggerInterface          $logger       The logger.
     */
    public function __construct(
        IRequest $request,
        private BerichtenboxStatsService $statsService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Manually re-queue a failed message for another dispatch attempt.
     *
     * @param string $id The message ID.
     *
     * @return JSONResponse The result.
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function retry(string $id): JSONResponse
    {
        try {
            $success = $this->statsService->requeue(messageId: $id);
            if ($success === false) {
                return new JSONResponse(['error' => 'not found or not retryable'], Http::STATUS_NOT_FOUND);
            }

            return new JSONResponse(['success' => true]);
        } catch (Throwable $e) {
            $this->logger->error('Berichtenbox: manual retry failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => 'retry failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end retry()

    /**
     * Return aggregate delivery statistics.
     *
     * @return JSONResponse The statistics.
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function stats(): JSONResponse
    {
        try {
            return new JSONResponse($this->statsService->stats());
        } catch (Throwable $e) {
            $this->logger->error('Berichtenbox: stats failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(['error' => 'stats unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }//end stats()
}//end class
