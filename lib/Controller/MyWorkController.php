<?php

/**
 * Pipelinq MyWorkController.
 *
 * REST endpoints for My work's sections that are not the work list itself.
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
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use InvalidArgumentException;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\WorklistService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * My work's figures for the signed-in user.
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md
 */
class MyWorkController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request         The HTTP request.
	 * @param WorklistService $worklistService The worklist service.
	 * @param IUserSession    $userSession     The session.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly WorklistService $worklistService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/my-work/week-stats: the "Your week so far" figures of the
	 * session user's own tickets. The user is always the session user; a
	 * caller cannot ask for another user's week. `?scope=` other than `mine`
	 * is refused with 400 until team scopes exist.
	 *
	 * @return JSONResponse The figures, or an error envelope.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-kpi-summary-tiles-on-my-work-req-mw-090
	 */
	#[NoAdminRequired]
	public function weekStats(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$scope = (string)$this->request->getParam('scope', 'mine');
		try {
			return new JSONResponse($this->worklistService->getWeekStats(userId: $user->getUID(), scope: $scope));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => 'Invalid scope'], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->warning(
				message: '[MyWorkController] weekStats failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(['message' => 'Week figures unavailable'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end weekStats()
}//end class
