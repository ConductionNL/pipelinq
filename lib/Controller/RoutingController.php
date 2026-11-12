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
 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\RoutingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for routing suggestion API.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-2
 */
#[NoAdminRequired]
class RoutingController extends Controller {
	/**
	 * Valid entity types accepted by the suggestions endpoint.
	 *
	 * @var array<int, string>
	 */
	private const VALID_ENTITY_TYPES = ['request', 'lead'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param RoutingService $routingService The routing service.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private RoutingService $routingService,
		private CrmPrivilegeCheck $privilege,
		private LoggerInterface $logger,
	) {
		// @PublicPage — DI constructor (not HTTP-routable). The class-level
		// #[NoAdminRequired] applies to the action methods; this ctor is
		// wired by the Nextcloud app framework and never serves a request.
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
	 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-2.2
	 */
	#[NoAdminRequired]
	public function getSuggestions(): JSONResponse {
		// Routing suggestions read the assignment graph across the team — a
		// CRM capability, not an any-authenticated-user one.
		$refusal = $this->privilege->refusalAsMessage();
		if ($refusal !== null) {
			return $refusal;
		}

		$entityType = (string)$this->request->getParam('entityType', '');
		$entityId = (string)$this->request->getParam('entityId', '');
		$refusal = $this->entityRefusal(entityType: $entityType, entityId: $entityId);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->suggestionsFor(entityType: $entityType, entityId: $entityId);
	}//end getSuggestions()

	/**
	 * The 400 for a missing entity or an entity type routing does not know, or null.
	 *
	 * @param string $entityType The entity type.
	 * @param string $entityId   The entity id.
	 *
	 * @return JSONResponse|null The refusal.
	 */
	private function entityRefusal(string $entityType, string $entityId): ?JSONResponse {
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

		return null;
	}//end entityRefusal()

	/**
	 * The ranked agents for the entity, or a static 500 with the failure logged.
	 *
	 * @param string $entityType The entity type.
	 * @param string $entityId   The entity id.
	 *
	 * @return JSONResponse The suggestions, or the failure.
	 */
	private function suggestionsFor(string $entityType, string $entityId): JSONResponse {
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
					'exception' => $e,
					'entityType' => $entityType,
					'entityId' => $entityId,
				]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new JSONResponse($result, Http::STATUS_OK);
	}//end suggestionsFor()
}//end class
