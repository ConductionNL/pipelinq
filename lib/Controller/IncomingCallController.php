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

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\Cti\IncomingCallFinder;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

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
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param IncomingCallFinder $incomingCalls Finds the open inbound call on an agent's line.
	 */
	public function __construct(
		IRequest $request,
		private CrmPrivilegeCheck $privilege,
		private IncomingCallFinder $incomingCalls,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
		// Same posture as the screen pop itself: a caller's number is CRM data.
		$refusal = $this->privilege->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$uid = $this->privilege->actingUserId();

		return new JSONResponse($this->incomingCalls->forAgent(userId: $uid));
	}//end show()
}//end class
