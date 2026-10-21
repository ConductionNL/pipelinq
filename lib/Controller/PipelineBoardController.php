<?php

/**
 * Pipelinq PipelineBoardController.
 *
 * The cards of one pipeline's board, for the Pipeline page's board view.
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
 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PipelineBoardService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the pipeline board's cards.
 *
 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
 */
class PipelineBoardController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest             $request      The HTTP request.
	 * @param PipelineBoardService $boardService The board reader.
	 * @param IUserSession         $userSession  Active user session.
	 * @param LoggerInterface      $logger       Logger.
	 */
	public function __construct(
		IRequest $request,
		private PipelineBoardService $boardService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/pipeline-items?pipeline=<id>[&assignee=<uid>][&status=<s>].
	 *
	 * Every record the pipeline applies to (the default pipeline when none is
	 * named), read with the caller's own permissions (OpenRegister RBAC), as
	 * `{ results, total, pipeline }`. A pipeline the caller cannot read -> 404.
	 *
	 * @return JSONResponse The cards, or an error envelope.
	 *
	 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
	 */
	#[NoAdminRequired]
	public function items(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$pipelineId = $this->request->getParam('pipeline', '');
		if (is_string($pipelineId) === false) {
			return new JSONResponse(['message' => 'Invalid pipeline'], Http::STATUS_BAD_REQUEST);
		}

		$filters = [];
		foreach (PipelineBoardService::CARD_FILTERS as $key) {
			$value = $this->request->getParam($key, '');
			if (is_string($value) === true && $value !== '') {
				$filters[$key] = $value;
			}
		}

		try {
			$items = $this->boardService->items(pipelineId: $pipelineId, filters: $filters);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[PipelineBoardController] items failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Pipeline board unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($items === null) {
			return new JSONResponse(['message' => 'Pipeline not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($items);
	}//end items()
}//end class
