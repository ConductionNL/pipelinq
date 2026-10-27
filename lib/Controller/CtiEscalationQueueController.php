<?php

/**
 * Pipelinq CtiEscalationQueueController.
 *
 * Lists the escalation queues an agent can choose when closing a call.
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
 * @spec openspec/changes/cti-escalation-queues/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\CtiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GET /api/cti/escalation-queues: the queues by id and label.
 *
 * @spec openspec/changes/cti-escalation-queues/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
 */
class CtiEscalationQueueController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                $request     The request.
	 * @param CtiService              $ctiService  The CTI service.
	 * @param IUserSession            $userSession The user session.
	 * @param ObjectOwnerAccessPolicy $policy      Who may use the CTI surfaces.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private CtiService $ctiService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The escalation queues an agent can choose when closing a call.
	 *
	 * @return JSONResponse `{ queues: [{ id, label }] }`.
	 *
	 * @spec openspec/changes/cti-escalation-queues/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		// Same posture as the disposition: only an agent who may close a call sees where it can go.
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['queues' => $this->ctiService->escalationQueues()]);
	}//end index()
}//end class
