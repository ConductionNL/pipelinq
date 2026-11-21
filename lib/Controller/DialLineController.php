<?php

/**
 * Pipelinq DialLineController.
 *
 * Answers the click-to-dial buttons: can I place a call, and from which extension.
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
 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\DialLineReaderInterface;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The signed-in agent's own dial line.
 *
 * Its own controller, beside CtiController, which carries the webhook,
 * click-to-dial itself and the telephony settings.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-click-to-dial-outbound-req-cti-003
 */
class DialLineController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param DialLineReaderInterface $dialLines Reads an agent's dial line.
	 */
	public function __construct(
		IRequest $request,
		private CrmPrivilegeCheck $privilege,
		private DialLineReaderInterface $dialLines,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The signed-in agent's dial line.
	 *
	 * The uid comes from the session, never from the request, so an agent
	 * only ever learns their own extension.
	 *
	 * @return JSONResponse `{enabled, extension, busy}`.
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-click-to-dial-outbound-req-cti-003
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		// Same posture as click-to-dial itself: placing a call is a CRM capability.
		$refusal = $this->privilege->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse($this->dialLines->forAgent(userId: $this->privilege->actingUserId()));
	}//end show()
}//end class
