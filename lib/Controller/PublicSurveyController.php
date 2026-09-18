<?php

/**
 * Pipelinq PublicSurveyController.
 *
 * The two routes a respondent reaches with the token from their invitation.
 * Public, because the recipient is a member of the public and has no account
 * here, and rate limited per anonymous caller, because a public endpoint that
 * takes a token is a public endpoint somebody will guess at.
 *
 * Every refusal reads the same from outside: a token that never existed, one
 * already answered and one that expired must not be told apart by anybody
 * guessing, beyond the one distinction a real respondent needs, which is that
 * a closed survey says it is closed.
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
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SurveyResponseService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Open and answer a survey by its per-invitation token.
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
 */
class PublicSurveyController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SurveyResponseService $responses Opens and accepts responses.
	 */
	public function __construct(
		IRequest $request,
		private readonly SurveyResponseService $responses,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The survey behind a token.
	 *
	 * @param string $token The per-invitation token.
	 *
	 * @return JSONResponse The survey, or the state that stopped it.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 300)]
	public function showInvitation(string $token): JSONResponse {
		return $this->respond(result: $this->responses->show(token: $token));
	}//end showInvitation()

	/**
	 * Answer a survey against a token.
	 *
	 * @param string $token The per-invitation token.
	 * @param array<string, mixed> $answers The answers, keyed by question key.
	 * @param bool $optOut Whether the respondent asked never to be asked again.
	 *
	 * @return JSONResponse The response, or the state that stopped it.
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 300)]
	public function submitInvitation(string $token, array $answers = [], bool $optOut = false): JSONResponse {
		return $this->respond(
			result: $this->responses->submit(token: $token, answers: $answers, optOut: $optOut)
		);
	}//end submitInvitation()

	/**
	 * Turn a service result into a JSON response.
	 *
	 * @param array<string, mixed> $result The service's answer.
	 *
	 * @return JSONResponse The response.
	 */
	private function respond(array $result): JSONResponse {
		$status = (int)($result['status'] ?? 200);
		unset($result['status']);

		return new JSONResponse($result, $status);
	}//end respond()
}//end class
