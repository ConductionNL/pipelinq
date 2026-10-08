<?php

/**
 * Pipelinq SatisfactionController.
 *
 * The two reads a handler sees: one client's satisfaction panel, and the
 * response rate of a survey.
 *
 * The response rate names its suppressed and failed counts beside the
 * percentage rather than folding them into it, because a throttle that hides
 * inside a denominator looks exactly like disinterest.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SatisfactionAggregationService;
use OCA\Pipelinq\Service\SurveyDispatchService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Routes for the satisfaction panel and the response rate.
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
 */
class SatisfactionController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SatisfactionAggregationService $aggregation The per-client panel.
	 * @param SurveyDispatchService $dispatchService Reads invitations.
	 */
	public function __construct(
		IRequest $request,
		private readonly SatisfactionAggregationService $aggregation,
		private readonly SurveyDispatchService $dispatchService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * One client's satisfaction panel.
	 *
	 * @param string $clientId The client's uuid.
	 *
	 * @return JSONResponse The panel, empty state included.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
	 */
	#[NoAdminRequired]
	public function clientPanel(string $clientId): JSONResponse {
		return new JSONResponse($this->aggregation->forClient(clientId: $clientId), 200);
	}//end clientPanel()

	/**
	 * A survey's response rate, with its suppressed and failed counts.
	 *
	 * @param string $surveyId The survey's uuid, or '' for every survey.
	 *
	 * @return JSONResponse The figures.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
	 */
	#[NoAdminRequired]
	public function responseRate(string $surveyId = ''): JSONResponse {
		$filters = [];
		if (trim($surveyId) !== '') {
			$filters = ['surveyRef' => trim($surveyId)];
		}

		return new JSONResponse(
			$this->dispatchService->responseRate(
				invitations: $this->dispatchService->read(filters: $filters)
			),
			200
		);
	}//end responseRate()
}//end class
