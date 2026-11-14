<?php

/**
 * ProgrammeController: closing an estimation cycle, linking work, and how an
 * object that cannot be read is refused.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\ProgrammeController;
use OCA\Pipelinq\Kernel\Contracts\ProgrammeEstimationServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ProgrammePortfolioServiceInterface;
use OCP\App\IAppManager;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Controller\ProgrammeController
 */
class ProgrammeControllerCycleTest extends TestCase {

	/**
	 * Portfolio service double.
	 *
	 * @var ProgrammePortfolioServiceInterface&MockObject
	 */
	private ProgrammePortfolioServiceInterface&MockObject $portfolio;

	/**
	 * Estimation service double.
	 *
	 * @var ProgrammeEstimationServiceInterface&MockObject
	 */
	private ProgrammeEstimationServiceInterface&MockObject $estimation;

	/**
	 * Object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * App manager double.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $apps;

	/**
	 * Set up the doubles and the controller's collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->portfolio = $this->createMock(ProgrammePortfolioServiceInterface::class);
		$this->estimation = $this->createMock(ProgrammeEstimationServiceInterface::class);
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->apps = $this->createMock(IAppManager::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return ProgrammeController
	 */
	private function controller(): ProgrammeController {
		return new ProgrammeController($this->createMock(IRequest::class), $this->portfolio, $this->estimation, $this->objects, $this->apps);
	}//end controller()

	/**
	 * An entity that serialises to the value given.
	 *
	 * @param mixed $data The serialised value.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(mixed $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);

		return $entity;
	}//end entity()

	/**
	 * Closing a cycle hands the cycle and its tasks to the estimation, and keeps its status.
	 *
	 * @return void
	 */
	public function testCloseCycleClosesTheCycleWithItsTasks(): void {
		$this->objects->expects($this->once())->method('find')->with(id: 'cy-1')->willReturn($this->entity(['id' => 'cy-1', 'name' => 'Sprint 4']));
		$this->portfolio->expects($this->once())->method('read')->with(schemaKey: 'programmeTask_schema', filters: ['cycle' => 'cy-1'])->willReturn([['id' => 't-1']]);
		$this->estimation->expects($this->once())->method('closeCycle')
			->with(cycle: ['id' => 'cy-1', 'name' => 'Sprint 4'], tasks: [['id' => 't-1']])
			->willReturn(['status' => 201, 'closed' => true, 'carriedOver' => 0]);

		$response = $this->controller()->closeCycle(' cy-1 ');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['closed' => true, 'carriedOver' => 0], $response->getData());
	}//end testCloseCycleClosesTheCycleWithItsTasks()

	/**
	 * A refusal from the estimation keeps its status; no status means 200.
	 *
	 * @return void
	 */
	public function testCloseCycleKeepsTheEstimationsStatus(): void {
		$this->objects->method('find')->willReturn($this->entity(['id' => 'cy-1']));
		$this->estimation->method('closeCycle')->willReturnOnConsecutiveCalls(['status' => 409, 'error' => 'Already closed'], ['closed' => true]);

		$refused = $this->controller()->closeCycle('cy-1');
		$this->assertSame(409, $refused->getStatus());
		$this->assertSame(['error' => 'Already closed'], $refused->getData());
		$this->assertSame(200, $this->controller()->closeCycle('cy-1')->getStatus());
	}//end testCloseCycleKeepsTheEstimationsStatus()

	/**
	 * A cycle that is blank, missing, unreadable or not an object is refused, and nothing is closed.
	 *
	 * @return void
	 */
	public function testAnUnreadableCycleIsRefused(): void {
		$this->estimation->expects($this->never())->method('closeCycle');
		$this->objects->method('find')->willReturnCallback(
			function (string $id): ?ObjectEntityInterface {
				return match ($id) {
					'missing' => null,
					'broken' => throw new RuntimeException('no access'),
					default => $this->entity('a string'),
				};
			}
		);

		foreach (['  ', 'missing', 'broken', 'scalar'] as $id) {
			$response = $this->controller()->closeCycle($id);
			$this->assertSame(403, $response->getStatus(), $id);
			$this->assertSame(['error' => 'You may not read this cycle.'], $response->getData(), $id);
		}
	}//end testAnUnreadableCycleIsRefused()

	/**
	 * Linking work passes every field on and answers with the service's status.
	 *
	 * @return void
	 */
	public function testLinkWorkPassesEveryField(): void {
		$this->portfolio->expects($this->once())->method('linkWork')
			->with(programmeId: 'p-1', domainObjectType: 'decidiq.motion', domainObjectRef: 'm-9', title: 'Motion 9')
			->willReturn(['status' => 201, 'workItem' => ['id' => 'w-1']]);

		$response = $this->controller()->linkWork('p-1', 'decidiq.motion', 'm-9', 'Motion 9');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(['workItem' => ['id' => 'w-1']], $response->getData());
	}//end testLinkWorkPassesEveryField()

	/**
	 * When the enabled apps cannot be read, work items are presented with none.
	 *
	 * @return void
	 */
	public function testWorkItemsWithoutTheAppListPresentNoInstalledApps(): void {
		$this->apps->method('getEnabledApps')->willThrowException(new RuntimeException('app store down'));
		$this->portfolio->method('workItemsOf')->with(programmeId: 'p-1')->willReturn([['id' => 'w-1']]);
		$this->portfolio->expects($this->once())->method('presentWorkItem')
			->with(workItem: ['id' => 'w-1'], resolvedTitles: [], installedApps: [])
			->willReturn(['id' => 'w-1', 'title' => 'Motion 9']);

		$this->assertSame(['workItems' => [['id' => 'w-1', 'title' => 'Motion 9']]], $this->controller()->workItems('p-1')->getData());
	}//end testWorkItemsWithoutTheAppListPresentNoInstalledApps()
}//end class
