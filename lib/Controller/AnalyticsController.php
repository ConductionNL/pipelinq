<?php

/**
 * Pipelinq AnalyticsController.
 *
 * Thin REST controller exposing the cross-module analytics summary
 * (Klantbeeld 360 dashboard) for authenticated users.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AnalyticsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the Klantbeeld 360 cross-module analytics summary.
 *
 * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.2
 */
class AnalyticsController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest         $request          The HTTP request.
     * @param AnalyticsService $analyticsService Analytics summary service.
     * @param IUserSession     $userSession      Active user session.
     * @param LoggerInterface  $logger           Logger.
     */
    public function __construct(
        IRequest $request,
        private AnalyticsService $analyticsService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * GET /api/analytics/summary.
     *
     * All authenticated users may view org-level KPIs. Period defaults to
     * "month" when omitted. Invalid period -> HTTP 400 with a static error
     * message. OpenRegister outage -> HTTP 500 with a static message
     * (never the underlying exception text).
     *
     * @return JSONResponse The summary payload, or an error envelope.
     *
     * @spec openspec/changes/klantbeeld-360/tasks.md#task-1.2
     */
    #[NoAdminRequired]
    public function summary(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $period = (string) $this->request->getParam('period', AnalyticsService::DEFAULT_PERIOD);

        try {
            $payload = $this->analyticsService->getSummary(period: $period);
            return new JSONResponse($payload);
        } catch (InvalidArgumentException) {
            return new JSONResponse(['message' => 'Invalid period'], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[AnalyticsController] summary failed',
                context: ['error' => $e->getMessage()]
            );
            return new JSONResponse(['message' => 'Analytics unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end summary()
}//end class
