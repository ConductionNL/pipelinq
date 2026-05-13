<?php

/**
 * Pipelinq RoutingController.
 *
 * Controller exposing skill-based routing suggestions for queued requests
 * and leads. Read-only aggregation endpoint — no CRUD here.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/skill-routing/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\RoutingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for routing suggestion API.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/skill-routing/tasks.md#task-2
 */
#[NoAdminRequired]
class RoutingController extends Controller
{
    /**
     * Valid entity types accepted by the suggestions endpoint.
     *
     * @var array<int, string>
     */
    private const VALID_ENTITY_TYPES = ['request', 'lead'];

    /**
     * Constructor.
     *
     * @param IRequest        $request        The request.
     * @param RoutingService  $routingService The routing service.
     * @param LoggerInterface $logger         The logger.
     */
    public function __construct(
        IRequest $request,
        private RoutingService $routingService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Get ranked agent suggestions for a queued request or lead.
     *
     * Query params:
     *   - entityType: 'request' | 'lead'
     *   - entityId:   UUID
     *
     * @return JSONResponse Shape on success: { suggestions, atCapacity, noMatch }.
     *                      On validation failure: 400 with { message }.
     *                      On unexpected failure: 500 with { message: 'Operation failed' }.
     *
     * @spec openspec/changes/skill-routing/tasks.md#task-2.2
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function getSuggestions(): JSONResponse
    {
        $entityType = (string) $this->request->getParam('entityType', '');
        $entityId   = (string) $this->request->getParam('entityId', '');

        if ($entityType === '' || $entityId === '') {
            return new JSONResponse(
                ['message' => 'entityType and entityId are required'],
                Http::STATUS_BAD_REQUEST
            );
        }

        if (in_array($entityType, self::VALID_ENTITY_TYPES, true) === false) {
            return new JSONResponse(
                ['message' => 'Invalid entityType'],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            $result = $this->routingService->getSuggestedAgents(
                entityType: $entityType,
                entityId: $entityId
            );
        } catch (\Throwable $e) {
            // NEVER expose $e->getMessage() to client — log full context here.
            $this->logger->error(
                'RoutingController: getSuggestions failed',
                [
                    'exception'  => $e,
                    'entityType' => $entityType,
                    'entityId'   => $entityId,
                ]
            );
            return new JSONResponse(
                ['message' => 'Operation failed'],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }

        return new JSONResponse($result, Http::STATUS_OK);
    }//end getSuggestions()
}//end class
