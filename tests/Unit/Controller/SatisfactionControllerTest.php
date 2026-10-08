<?php

/**
 * Contract tests for SatisfactionController.
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

use OCA\Pipelinq\Controller\SatisfactionController;
use OCA\Pipelinq\Service\SatisfactionAggregationService;
use OCA\Pipelinq\Service\SurveyDispatchService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The wire contract of the per-client satisfaction panel.
 */
class SatisfactionControllerTest extends TestCase {
	/**
	 * The aggregation double.
	 *
	 * @var SatisfactionAggregationService
	 */
	private SatisfactionAggregationService $aggregation;

	/**
	 * The dispatch double.
	 *
	 * @var SurveyDispatchService
	 */
	private SurveyDispatchService $dispatch;

	/**
	 * The filters the dispatch double was asked to read with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $readWith = [];

	/**
	 * Build the controller over doubles.
	 *
	 * @return SatisfactionController The controller under test.
	 */
	private function controller(): SatisfactionController {
		$this->aggregation = $this->getMockBuilder(SatisfactionAggregationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['forClient'])
			->getMock();

		$this->dispatch = $this->getMockBuilder(SurveyDispatchService::class)
			->disableOriginalConstructor()
			->onlyMethods(['read', 'responseRate'])
			->getMock();
		$this->dispatch->method('read')->willReturnCallback(
			function (array $filters): array {
				$this->readWith[] = $filters;

				return [];
			}
		);
		$this->dispatch->method('responseRate')->willReturn(['delivered' => 0, 'responded' => 0, 'rate' => 0.0]);

		return new SatisfactionController(
			$this->createMock(IRequest::class),
			$this->aggregation,
			$this->dispatch,
		);
	}//end controller()

	/**
	 * An empty panel is answered as empty at 200, not as a 404.
	 *
	 * A client nobody has surveyed yet is a normal state of the world, and a
	 * 404 would read as "this client does not exist".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
	 */
	public function testAnUnsurveyedClientIsAnEmptyPanelNotAMissingOne(): void {
		$controller = $this->controller();
		$this->aggregation->method('forClient')->willReturn(
			['empty' => true, 'responseCount' => 0, 'nps' => null, 'trend' => 'unknown', 'verbatims' => []]
		);

		$response = $controller->clientPanel(clientId: 'client-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['empty']);
		$this->assertNull($response->getData()['nps']);
	}//end testAnUnsurveyedClientIsAnEmptyPanelNotAMissingOne()

	/**
	 * The panel hands the aggregate through unchanged.
	 *
	 * @return void
	 */
	public function testThePanelAnswersTheAggregate(): void {
		$controller = $this->controller();
		$this->aggregation->method('forClient')->willReturn(
			['empty' => false, 'responseCount' => 4, 'nps' => 25.0, 'trend' => 'up', 'verbatims' => []]
		);

		$response = $controller->clientPanel(clientId: 'client-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(25.0, $response->getData()['nps']);
		$this->assertSame('up', $response->getData()['trend']);
	}//end testThePanelAnswersTheAggregate()

	/**
	 * A blank survey id reads every invitation rather than filtering on ''.
	 *
	 * @return void
	 */
	public function testABlankSurveyIdDoesNotFilterOnAnEmptyReference(): void {
		$controller = $this->controller();

		$controller->responseRate(surveyId: '  ');

		$this->assertSame([[]], $this->readWith);
	}//end testABlankSurveyIdDoesNotFilterOnAnEmptyReference()

	/**
	 * A named survey filters on it, trimmed.
	 *
	 * @return void
	 */
	public function testANamedSurveyFiltersOnIt(): void {
		$controller = $this->controller();

		$controller->responseRate(surveyId: ' survey-1 ');

		$this->assertSame([['surveyRef' => 'survey-1']], $this->readWith);
	}//end testANamedSurveyFiltersOnIt()
}//end class
