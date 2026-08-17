<?php

/**
 * Pipelinq RapportageController.
 *
 * Thin controller exposing the lead-management analytics endpoint.
 * Aggregation lives in RapportageService — this class only handles
 * routing, parameter validation and response shaping.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/lead-management/spec.md
 * @spec openspec/specs/lead-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\RapportageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Pipeline analytics endpoint controller.
 *
 * @spec openspec/specs/lead-management/spec.md
 */
class RapportageController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param RapportageService $rapportageService Analytics aggregation service.
	 * @param IUserSession $userSession The user session.
	 * @param ObjectOwnerAccessPolicy $accessPolicy Owner-based access policy.
	 */
	public function __construct(
		IRequest $request,
		private RapportageService $rapportageService,
		private IUserSession $userSession,
		private ObjectOwnerAccessPolicy $accessPolicy,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Aggregate lead analytics for the Rapportage dashboard.
	 *
	 * Available to any authenticated Nextcloud user (REQ-LM-009). The
	 * endpoint returns 401 for unauthenticated requests, 500 on aggregation
	 * failure with a static error string (no exception leakage).
	 *
	 * @return JSONResponse The analytics payload.
	 *
	 * @spec openspec/specs/lead-management/spec.md
	 * @spec openspec/specs/lead-management/spec.md
	 */
	#[NoAdminRequired]
	public function getPipelineStats(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Authentication required'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// Pipeline statistics aggregate the whole sales pipeline — a CRM
		// capability, not an any-authenticated-user one.
		if ($this->accessPolicy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(data: ['message' => 'Forbidden'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$pipelineId = (string)$this->request->getParam('pipelineId', '');
		$dateFrom = (string)$this->request->getParam('dateFrom', '');
		$dateTo = (string)$this->request->getParam('dateTo', '');

		$pipelineIdArg = null;
		if ($pipelineId !== '') {
			$pipelineIdArg = $pipelineId;
		}

		$dateFromArg = null;
		if ($dateFrom !== '') {
			$dateFromArg = $dateFrom;
		}

		$dateToArg = null;
		if ($dateTo !== '') {
			$dateToArg = $dateTo;
		}

		try {
			$stageValues = $this->rapportageService->getStageValues(pipelineId: $pipelineIdArg);
			$sourcePerformance = $this->rapportageService->getSourcePerformance(
				dateFrom: $dateFromArg,
				dateTo: $dateToArg,
			);
			$agingBuckets = $this->rapportageService->getAgingBuckets();
			$winLoss = $this->rapportageService->getWinLossAnalysis(
				dateFrom: $dateFromArg,
				dateTo: $dateToArg,
			);
		} catch (\Throwable) {
			return new JSONResponse(data: ['message' => 'Operation failed'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(
			data: [
				'stageValues' => $stageValues,
				'sourcePerformance' => $sourcePerformance,
				'agingBuckets' => $agingBuckets,
				'winLoss' => $winLoss,
			]
		);

	}//end getPipelineStats()
}//end class
