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

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
use OCA\Pipelinq\Service\RapportageService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

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
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 */
	public function __construct(
		IRequest $request,
		private RapportageService $rapportageService,
		private CrmPrivilegeCheck $privilege,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);

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
		// Pipeline statistics aggregate the whole sales pipeline — a CRM
		// capability, not an any-authenticated-user one.
		$refusal = $this->privilege->refusalAsMessage();
		if ($refusal !== null) {
			return $refusal;
		}

		$pipelineId = $this->optionalParam(key: 'pipelineId');
		$dateFrom = $this->optionalParam(key: 'dateFrom');
		$dateTo = $this->optionalParam(key: 'dateTo');

		try {
			$stats = [
				'stageValues' => $this->rapportageService->getStageValues(pipelineId: $pipelineId),
				'sourcePerformance' => $this->rapportageService->getSourcePerformance(
					dateFrom: $dateFrom,
					dateTo: $dateTo,
				),
				'agingBuckets' => $this->rapportageService->getAgingBuckets(),
				'winLoss' => $this->rapportageService->getWinLossAnalysis(
					dateFrom: $dateFrom,
					dateTo: $dateTo,
				),
			];
		} catch (\Throwable) {
			return new JSONResponse(data: ['message' => 'Operation failed'], statusCode: Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse(data: $stats);
	}//end getPipelineStats()

	/**
	 * A request parameter, or null when it is absent or empty.
	 *
	 * @param string $key The parameter.
	 *
	 * @return string|null The value.
	 */
	private function optionalParam(string $key): ?string {
		$value = (string)$this->request->getParam($key, '');
		if ($value === '') {
			return null;
		}

		return $value;
	}//end optionalParam()
}//end class
