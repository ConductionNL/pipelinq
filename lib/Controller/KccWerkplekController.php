<?php

/**
 * Pipelinq KccWerkplekController.
 *
 * Thin REST controller exposing the KCC Werkplek workspace state aggregation
 * endpoint and the agent-availability toggle. All endpoints require an
 * authenticated NC session; the caller's user ID is the only trusted
 * identity for the availability mutation.
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
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\KccWerkplekService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * REST controller for the KCC Werkplek workspace state.
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
 */
class KccWerkplekController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param KccWerkplekService $werkplekService Workspace state service.
	 * @param IUserSession $userSession Active user session.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
	 */
	public function __construct(
		IRequest $request,
		private KccWerkplekService $werkplekService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * GET /api/kcc-werkplek/state.
	 *
	 * Returns the aggregated workspace state for the calling agent: assigned
	 * requests, open tasks, queue counts and the agent profile. Failures
	 * return a static error envelope — never the underlying exception text.
	 *
	 * @return JSONResponse The workspace state payload, or an error envelope.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
	 */
	#[NoAdminRequired]
	public function stateAction(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['message' => 'Authentication required'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		try {
			$payload = $this->werkplekService->getWorkspaceState($user->getUID());
			return new JSONResponse($payload);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[KccWerkplekController] state failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end stateAction()

	/**
	 * PUT /api/kcc-werkplek/availability.
	 *
	 * Toggles the calling agent's availability flag. The user ID is taken
	 * from the authenticated session — the request body must NOT supply a
	 * user ID. Errors return a static envelope; the agent's previous state
	 * is preserved when the save fails.
	 *
	 * @return JSONResponse The updated profile, or an error envelope.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/kcc-werkplek/tasks.md#task-2
	 */
	#[NoAdminRequired]
	public function setAvailabilityAction(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['message' => 'Authentication required'],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$param = $this->request->getParam('isAvailable', null);
		if ($param === null) {
			return new JSONResponse(
				['message' => 'isAvailable is required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		// Accept boolean, "true"/"false" strings or 0/1; reject everything else.
		$available = $this->parseAvailability(param: $param);
		if ($available === null) {
			return new JSONResponse(
				['message' => 'isAvailable must be a boolean'],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$result = $this->werkplekService->setAvailability($user->getUID(), $available);
			return new JSONResponse($result);
		} catch (\Throwable $e) {
			$this->logger->error(
				message: '[KccWerkplekController] setAvailability failed',
				context: ['error' => $e->getMessage()]
			);
			return new JSONResponse(
				['message' => 'Operation failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end setAvailabilityAction()

	/**
	 * Coerce the request's isAvailable param to a strict boolean.
	 *
	 * Accepts a real boolean, the strings "true"/"false"/"1"/"0" (case-insensitive),
	 * or the integers 0/1. Returns null for any other input so the caller can reject it.
	 *
	 * @param mixed $param Raw request value.
	 *
	 * @return bool|null The parsed availability, or null when the value is not accepted.
	 */
	private function parseAvailability(mixed $param): ?bool {
		if (is_bool($param) === true) {
			return $param;
		}

		if (is_string($param) === true && in_array(strtolower($param), ['true', 'false', '1', '0'], true) === true) {
			return in_array(strtolower($param), ['true', '1'], true);
		}

		if (is_int($param) === true && ($param === 0 || $param === 1)) {
			return ($param === 1);
		}

		return null;
	}//end parseAvailability()
}//end class
