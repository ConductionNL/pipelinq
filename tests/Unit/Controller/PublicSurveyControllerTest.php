<?php

/**
 * Contract tests for PublicSurveyController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PublicSurveyController;
use OCA\Pipelinq\Service\SurveyResponseService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The wire contract of the token-addressed public survey.
 */
class PublicSurveyControllerTest extends TestCase {
	/**
	 * The response service double.
	 *
	 * @var SurveyResponseService
	 */
	private SurveyResponseService $responses;

	/**
	 * Every argument list `submit` was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $submitted = [];

	/**
	 * Build the controller over doubles.
	 *
	 * @return PublicSurveyController The controller under test.
	 */
	private function controller(): PublicSurveyController {
		$this->responses = $this->getMockBuilder(SurveyResponseService::class)
			->disableOriginalConstructor()
			->onlyMethods(['show', 'submit'])
			->getMock();
		$this->responses->method('submit')->willReturnCallback(
			function (string $token, array $answers, ?bool $optOut = null): array {
				$this->submitted[] = ['token' => $token, 'answers' => $answers, 'optOut' => $optOut];

				return ['status' => 201, 'response' => ['id' => 'resp-1']];
			}
		);

		return new PublicSurveyController(
			$this->createMock(IRequest::class),
			$this->responses,
		);
	}//end controller()

	/**
	 * An open token answers the survey at 200.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
	 */
	public function testAnOpenTokenAnswersTheSurvey(): void {
		$controller = $this->controller();
		$this->responses->method('show')->willReturn(
			['status' => 200, 'state' => 'open', 'survey' => ['title' => 'KTO']]
		);

		$response = $controller->showInvitation(token: 'tok-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('KTO', $response->getData()['survey']['title']);
	}//end testAnOpenTokenAnswersTheSurvey()

	/**
	 * A token nobody holds is a 404, and the body says nothing about a survey.
	 *
	 * @return void
	 */
	public function testAnUnknownTokenIsANotFoundAndNamesNoSurvey(): void {
		$controller = $this->controller();
		$this->responses->method('show')->willReturn(['status' => 404, 'state' => 'unknown']);

		$response = $controller->showInvitation(token: 'tok-1');

		$this->assertSame(404, $response->getStatus());
		$this->assertArrayNotHasKey('survey', $response->getData());
	}//end testAnUnknownTokenIsANotFoundAndNamesNoSurvey()

	/**
	 * A spent token is gone, not missing: 410 tells the holder the link is
	 * dead rather than mistyped.
	 *
	 * @return void
	 */
	public function testASpentTokenIsGoneRatherThanMissing(): void {
		$controller = $this->controller();
		$this->responses->method('show')->willReturn(['status' => 410, 'state' => 'closed']);

		$response = $controller->showInvitation(token: 'tok-1');

		$this->assertSame(410, $response->getStatus());
		$this->assertSame('closed', $response->getData()['state']);
	}//end testASpentTokenIsGoneRatherThanMissing()

	/**
	 * An accepted answer is a 201 carrying the stored response.
	 *
	 * @return void
	 */
	public function testAnAcceptedAnswerIsCreated(): void {
		$controller = $this->controller();

		$response = $controller->submitInvitation(token: 'tok-1', answers: ['recommend' => 9]);

		$this->assertSame(201, $response->getStatus());
		$this->assertSame('resp-1', $response->getData()['response']['id']);
	}//end testAnAcceptedAnswerIsCreated()

	/**
	 * A form that carried no answer to the opt-out question passes null, not
	 * false. Nobody stated a preference, which is not the same as stating the
	 * preference to keep being contacted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function testAnUnansweredOptOutIsNullRatherThanFalse(): void {
		$controller = $this->controller();

		$controller->submitInvitation(token: 'tok-1', answers: []);

		$this->assertNull($this->submitted[0]['optOut']);
	}//end testAnUnansweredOptOutIsNullRatherThanFalse()

	/**
	 * A ticked opt-out reaches the service as true.
	 *
	 * @return void
	 */
	public function testATickedOptOutReachesTheService(): void {
		$controller = $this->controller();

		$controller->submitInvitation(token: 'tok-1', answers: [], optOut: true);

		$this->assertTrue($this->submitted[0]['optOut']);
	}//end testATickedOptOutReachesTheService()
}//end class
