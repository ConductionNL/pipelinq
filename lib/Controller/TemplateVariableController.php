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
use OCA\Pipelinq\Service\Marketing\EmptyVariableCounter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * GET /api/templates/{id}/empty-variables: the recipients without a value, per variable.
 *
 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-002
 */
class TemplateVariableController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest             $request The request.
	 * @param EmptyVariableCounter $counter Reads the template and audience and counts the gaps.
	 * @param CrmCallerGate        $gate    Lets only a CRM-privileged user through.
	 */
	public function __construct(
		IRequest $request,
		private readonly EmptyVariableCounter $counter,
		private readonly CrmCallerGate $gate,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * For a template and an audience, how many recipients lack each variable it uses.
	 *
	 * The audience is a `segmentId` or a `listId` query parameter.
	 *
	 * @param string $id Template UUID or slug.
	 *
	 * @return JSONResponse `{recipients, variables, gaps: [{variable, count}]}`, 401, 403 or 404.
	 *
	 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-002
	 */
	#[NoAdminRequired]
	public function emptyVariables(string $id): JSONResponse {
		$uid = $this->gate->privilegedUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$report = $this->counter->report(
			templateId: $id,
			segmentId: trim((string)$this->request->getParam('segmentId', '')),
			listId: trim((string)$this->request->getParam('listId', '')),
		);
		if ($report === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($report);
	}//end emptyVariables()
}//end class
