<?php

/**
 * Pipelinq TemplateVariableController.
 *
 * Tells the marketer, before a mail is sent, how many of its recipients would
 * get no value for each variable the template uses.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\BlastServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ComplianceServiceInterface;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\Marketing\EmptyVariableCounter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * GET /api/templates/{id}/empty-variables: the recipients without a value, per variable.
 *
 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-002
 */
class TemplateVariableController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request           The request.
	 * @param ComplianceServiceInterface $complianceService The template reader.
	 * @param BlastServiceInterface      $blastService      Resolves an audience the way the send does.
	 * @param EmptyVariableCounter       $counter           Counts the recipients without a value.
	 * @param IUserSession               $userSession       The user session.
	 * @param ObjectOwnerAccessPolicy    $policy            The CRM privilege policy.
	 */
	public function __construct(
		IRequest $request,
		private readonly ComplianceServiceInterface $complianceService,
		private readonly BlastServiceInterface $blastService,
		private readonly EmptyVariableCounter $counter,
		private readonly IUserSession $userSession,
		private readonly ObjectOwnerAccessPolicy $policy,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * For a template and an audience, how many recipients lack each variable it uses.
	 *
	 * The audience is a `segmentId` or a `listId` query parameter, gated for
	 * consent as the send gates it. Without either the answer has no gaps.
	 *
	 * @param string $id Template UUID or slug.
	 *
	 * @return JSONResponse `{recipients, variables, gaps: [{variable, count}]}`, 401, 403 or 404.
	 *
	 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-002
	 */
	#[NoAdminRequired]
	public function emptyVariables(string $id): JSONResponse {
		$gate = new CrmCallerGate(userSession: $this->userSession, policy: $this->policy);
		$uid  = $gate->privilegedUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$template = $this->complianceService->getTemplateById(templateId: $id);
		if ($template === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$recipients = $this->blastService->audienceRecipients(
			segmentId: trim((string)$this->request->getParam('segmentId', '')),
			listId: trim((string)$this->request->getParam('listId', '')),
			channel: (string)($template['channel'] ?? 'email'),
		);

		return new JSONResponse(
			[
				'recipients' => count($recipients),
				'variables' => $this->counter->usedVariables(template: $template),
				'gaps' => $this->counter->count(template: $template, recipients: $recipients),
			]
		);
	}//end emptyVariables()
}//end class
