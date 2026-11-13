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
 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\CtiService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * GET /api/cti/escalation-queues: the queues by id and label.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
 */
class CtiEscalationQueueController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                $request     The request.
	 * @param CtiService              $ctiService  The CTI service.
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private CtiService $ctiService,
		private CrmPrivilegeCheck $privilege,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The escalation queues an agent can choose when closing a call.
	 *
	 * @return JSONResponse `{ queues: [{ id, label }] }`.
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		// Same posture as the disposition: only an agent who may close a call sees where it can go.
		$refusal = $this->privilege->refusal();
		if ($refusal !== null) {
			return $refusal;
		}

		return new JSONResponse(['queues' => $this->ctiService->escalationQueues()]);
	}//end index()
}//end class
