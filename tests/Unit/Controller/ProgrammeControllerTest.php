<?php

/**
 * Contract tests for ProgrammeController.
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

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\ProgrammeController;
use OCA\Pipelinq\Service\ProgrammeEstimationService;
use OCA\Pipelinq\Service\ProgrammePortfolioService;
use OCP\App\IAppManager;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The wire contract of the programme portfolio surface.
 */
class ProgrammeControllerTest extends TestCase {
	/**
	 * The portfolio double.
	 *
	 * @var ProgrammePortfolioService
	 */
	private ProgrammePortfolioService $portfolio;

	/**
	 * The estimation double.
	 *
	 * @var ProgrammeEstimationService
	 */
	private ProgrammeEstimationService $estimation;

	/**
	 * The app manager double.
	 *
	 * @var IAppManager
	 */
	private IAppManager $appManager;

	/**
	 * The object the store double answers with, or null for "unreadable".
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $stored = null;

	/**
	 * The effort argument progressFor() was called with.
	 *
	 * @var array<int, mixed>
	 */
	private array $effortSeen = [];

	/**
	 * Build the controller over doubles.
	 *
	 * @return ProgrammeController The controller under test.
	 */
	private function controller(): ProgrammeController {
		$this->portfolio = $this->getMockBuilder(ProgrammePortfolioService::class)
			->disableOriginalConstructor()
			->onlyMethods(['presentWorkItem', 'workItemsOf', 'linkWork', 'progressFor', 'tasksOf', 'read'])
			->getMock();
		$this->portfolio->method('progressFor')->willReturnCallback(
			function (array $programme, array $tasks = [], ?array $effort = null): array {
				$this->effortSeen[] = $effort;

				return ['mode' => 'fromTasks', 'progress' => 50, 'computable' => true, 'reason' => ''];
			}
		);
		$this->portfolio->method('tasksOf')->willReturn([]);
		$this->portfolio->method('read')->willReturn([]);

		$this->estimation = $this->getMockBuilder(ProgrammeEstimationService::class)
			->disableOriginalConstructor()
			->onlyMethods(['closeCycle', 'chartFor'])
			->getMock();

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (): ?ObjectEntityInterface {
				if ($this->stored === null) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($this->stored);

				return $entity;
			}
		);

		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('getEnabledApps')->willReturn(['pipelinq']);

		return new ProgrammeController(
			$this->createMock(IRequest::class),
			$this->portfolio,
			$this->estimation,
			$objectService,
			$this->appManager,
		);
	}//end controller()

	/**
	 * The work-item list answers under `workItems`, presented row by row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-project-shall-hold-work-it-does-not-own-by-reference-req-prj-002
	 */
	public function testWorkItemsAnswersThePresentedRows(): void {
		$controller = $this->controller();
		$this->portfolio->method('workItemsOf')->willReturn([['domainObjectRef' => 'zaak-1']]);
		$this->portfolio->method('presentWorkItem')->willReturn(
			['id' => 'wi-1', 'domainObjectRef' => 'zaak-1', 'resolved' => false]
		);

		$response = $controller->workItems(programmeId: 'prog-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertCount(1, $response->getData()['workItems']);
		$this->assertFalse($response->getData()['workItems'][0]['resolved']);
	}//end testWorkItemsAnswersThePresentedRows()

	/**
	 * A refused link carries its status rather than answering 200.
	 *
	 * @return void
	 */
	public function testARefusedLinkKeepsItsStatus(): void {
		$controller = $this->controller();
		$this->portfolio->method('linkWork')->willReturn(
			['status' => 409, 'error' => 'This work is already in programme prog-2.']
		);

		$response = $controller->linkWork(
			programmeId: 'prog-1',
			domainObjectType: 'dossiq:case',
			domainObjectRef: 'zaak-1',
		);

		$this->assertSame(409, $response->getStatus());
		$this->assertStringContainsString('prog-2', $response->getData()['error']);
	}//end testARefusedLinkKeepsItsStatus()

	/**
	 * A programme the caller may not read is a 403, and no progress is
	 * computed for it.
	 *
	 * @return void
	 */
	public function testProgressRefusesAnUnreadableProgramme(): void {
		$controller = $this->controller();
		$this->stored = null;
		$this->portfolio->expects($this->never())->method('tasksOf');

		$response = $controller->progress(programmeId: 'prog-1');

		$this->assertSame(403, $response->getStatus());
	}//end testProgressRefusesAnUnreadableProgramme()

	/**
	 * Without humaniq the effort is null, so the figure can say it cannot be
	 * computed rather than reporting zero.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-progress-shall-declare-which-mode-produced-it-req-prj-003
	 */
	public function testWithoutHumaniqNoEffortIsOffered(): void {
		$controller = $this->controller();
		$this->stored = ['id' => 'prog-1'];
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		$response = $controller->progress(programmeId: 'prog-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame([null], $this->effortSeen);
	}//end testWithoutHumaniqNoEffortIsOffered()

	/**
	 * With humaniq present the effort pair is offered.
	 *
	 * @return void
	 */
	public function testWithHumaniqAnEffortPairIsOffered(): void {
		$controller = $this->controller();
		$this->stored = ['id' => 'prog-1'];
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$controller->progress(programmeId: 'prog-1');

		$this->assertSame([['booked' => 0, 'estimated' => 0]], $this->effortSeen);
	}//end testWithHumaniqAnEffortPairIsOffered()

	/**
	 * A cycle the caller may not read answers 403 and draws no chart.
	 *
	 * @return void
	 */
	public function testTheCycleChartRefusesAnUnreadableCycle(): void {
		$controller = $this->controller();
		$this->stored = null;
		$this->estimation->expects($this->never())->method('chartFor');

		$response = $controller->cycleChart(cycleId: 'cycle-1');

		$this->assertSame(403, $response->getStatus());
	}//end testTheCycleChartRefusesAnUnreadableCycle()

	/**
	 * A readable cycle answers its chart at 200.
	 *
	 * @return void
	 */
	public function testTheCycleChartAnswersTheChart(): void {
		$controller = $this->controller();
		$this->stored = ['id' => 'cycle-1'];
		$this->estimation->method('chartFor')->willReturn(['points' => [['day' => '2026-09-01', 'remaining' => 8]]]);

		$response = $controller->cycleChart(cycleId: 'cycle-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(8, $response->getData()['points'][0]['remaining']);
	}//end testTheCycleChartAnswersTheChart()
}//end class
