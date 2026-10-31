<?php

/**
 * Pipelinq IncomingCallController.
 *
 * Answers the incoming-call card: which call is on my line right now.
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
 * @spec openspec/changes/screen-pop-incoming-call/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Cti\IncomingCallFinder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The open inbound call on the signed-in agent's own line.
 *
 * Its own controller, beside CtiController: that one carries the webhook,
 * click-to-dial and the telephony settings, and has no room for another route.
 *
 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
 */
class IncomingCallController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param IUserSession $userSession The user session.
	 * @param ObjectOwnerAccessPolicy $policy Says who may see caller data.
	 * @param IncomingCallFinder $incomingCalls Finds the open inbound call on an agent's line.
	 */
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $policy,
		private IncomingCallFinder $incomingCalls,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The open inbound call on the signed-in agent's own line.
	 *
	 * The uid comes from the session, never from the request, so an agent can
	 * only ever ask about their own line.
	 *
	 * @return JSONResponse `{enabled, delayMs, call}`; `call` is null when no call is open.
	 *
	 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
		}

		// Same posture as the screen pop itself: a caller's number is CRM data.
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($this->incomingCalls->forAgent(userId: $user->getUID()));
	}//end show()
}//end class
