<?php

/**
 * Pipelinq TeamLoadController.
 *
 * GET /api/routing/team-load: per agent the open items against their
 * capacity, for the queue's Team today card (board PqWachtrij).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\TeamLoadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The team's load.
 *
 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1.3
 */
class TeamLoadController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                $request      The request.
	 * @param TeamLoadService         $teamLoad     The team's load.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param LoggerInterface         $logger       The logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly TeamLoadService $teamLoad,
		private readonly CrmPrivilegeCheck $privilege,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Per agent the open items against their capacity.
	 *
	 * Reads the assignment graph across the team, so it is a CRM capability,
	 * as the routing suggestions are.
	 *
	 * @return JSONResponse `{ results: [...] }` (see TeamLoadService::getTeamLoad()), or an error.
	 *
	 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1.3
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$refusal = $this->privilege->refusalAsMessage();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$rows = $this->teamLoad->getTeamLoad();
		} catch (Throwable $e) {
			$this->logger->error('TeamLoadController: getTeamLoad failed', ['exception' => $e]);
			return new JSONResponse(['message' => 'Operation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(['results' => $rows], Http::STATUS_OK);
	}//end index()
}//end class
