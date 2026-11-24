<?php

/**
 * WorklistController: the bucketed work items (JSON or CSV) and the summary
 * line, for the signed-in user.
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

use OCA\Pipelinq\Controller\WorklistController;
use OCA\Pipelinq\Kernel\Contracts\WorklistServiceInterface;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Controller\WorklistController
 */
class WorklistControllerItemsTest extends TestCase {

	/**
	 * Worklist service double.
	 *
	 * @var WorklistServiceInterface&MockObject
	 */
	private WorklistServiceInterface&MockObject $worklist;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->worklist = $this->createMock(WorklistServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller for a session user, with the request parameters given.
	 *
	 * @param array<string, string> $params The request parameters.
	 * @param bool                  $signed Whether a user is signed in.
	 *
	 * @return WorklistController
	 */
	private function controller(array $params = [], bool $signed = true): WorklistController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
		$user = null;
		if ($signed === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('agent');
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new WorklistController($request, $this->worklist, $session, $this->logger);
	}//end controller()

	/**
	 * Items default to every bucket, the deadline sort and open work only.
	 *
	 * @return void
	 */
	public function testItemsUseTheDefaults(): void {
		$this->worklist->expects($this->once())->method('items')
			->with(
				userId: 'agent',
				view: ['bucket' => '', 'lens' => '', 'sort' => 'deadline', 'done' => false, 'scope' => 'mine', 'member' => '', 'priority' => '', 'pipeline' => '']
			)
			->willReturn(['items' => [], 'counts' => []]);

		$response = $this->controller()->items();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(['items' => [], 'counts' => []], $response->getData());
	}//end testItemsUseTheDefaults()

	/**
	 * A bucket, a lens and a sort are passed on, and done=1 or done=true asks for all work.
	 *
	 * @return void
	 */
	public function testItemsPassTheFiltersAndTheDoneScope(): void {
		$views = [];
		$this->worklist->method('items')->willReturnCallback(
			static function (string $userId, array $view) use (&$views): array {
				$views[] = [$view['bucket'], $view['lens'], $view['sort'], $view['done']];
				return ['items' => []];
			}
		);

		$this->controller(['bucket' => 'waiting-on-me', 'lens' => 'team', 'sort' => 'priority', 'done' => '1'])->items();
		$this->controller(['bucket' => 'answer-today', 'done' => 'true'])->items();
		$this->controller(['bucket' => 'waiting-on-customer', 'done' => 'yes'])->items();

		$this->assertSame(
			[
				['waiting-on-me', 'team', 'priority', true],
				['answer-today', '', 'deadline', true],
				['waiting-on-customer', '', 'deadline', false],
			],
			$views
		);
	}//end testItemsPassTheFiltersAndTheDoneScope()

	/**
	 * An unknown bucket is refused before the service is asked.
	 *
	 * @return void
	 */
	public function testItemsRefuseAnUnknownBucket(): void {
		$this->worklist->expects($this->never())->method('items');

		$response = $this->controller(['bucket' => 'someday'])->items();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['message' => 'Invalid bucket'], $response->getData());
	}//end testItemsRefuseAnUnknownBucket()

	/**
	 * `?scope=` and `?member=` reach the service; a scope the user may not ask for is a 400.
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
	 *
	 * @return void
	 */
	public function testItemsPassTheScopeAndRefuseOneTheUserMayNotChoose(): void {
		$views = [];
		$this->worklist->method('items')->willReturnCallback(
			static function (string $userId, array $view) use (&$views): array {
				$views[] = [$view['scope'], $view['member']];
				if ($view['scope'] === 'organisation') {
					throw new \InvalidArgumentException(message: 'Unknown scope');
				}

				return ['items' => []];
			}
		);

		$this->controller(params: ['scope' => 'team', 'member' => 'bob'])->items();
		$refused = $this->controller(params: ['scope' => 'organisation'])->items();

		$this->assertSame(expected: [['team', 'bob'], ['organisation', '']], actual: $views);
		$this->assertSame(expected: 400, actual: $refused->getStatus());
		$this->assertSame(expected: ['message' => 'Invalid scope'], actual: $refused->getData());
	}//end testItemsPassTheScopeAndRefuseOneTheUserMayNotChoose()

	/**
	 * format=csv downloads the items as a CSV file, one row each with its due date.
	 *
	 * @return void
	 */
	public function testItemsDownloadAsCsv(): void {
		$this->worklist->method('items')->willReturn(['items' => [
			['kind' => 'request', 'title' => 'Call back, "urgent"', 'priority' => 'high', 'dueAt' => '2026-11-15', 'bucket' => 'answer-today'],
			['kind' => 'task', 'title' => 'Send offer', 'priority' => 'normal', 'dueAt' => null, 'bucket' => 'waiting-on-me'],
		]]);

		$response = $this->controller(['format' => 'csv'])->items();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(
			"kind,title,priority,due,list\nrequest,\"Call back, \"\"urgent\"\"\",high,2026-11-15,answer-today\ntask,\"Send offer\",normal,,waiting-on-me\n",
			$response->render()
		);
	}//end testItemsDownloadAsCsv()

	/**
	 * A failing service answers 500 and is logged.
	 *
	 * @return void
	 */
	public function testItemsAnswer500WhenTheServiceFails(): void {
		$this->worklist->method('items')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')->with(message: '[WorklistController] items failed', context: ['error' => 'db down']);

		$response = $this->controller()->items();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['message' => 'Worklist unavailable'], $response->getData());
	}//end testItemsAnswer500WhenTheServiceFails()

	/**
	 * The summary line for the signed-in user, or 500 when it cannot be made.
	 *
	 * @return void
	 */
	public function testSummary(): void {
		$this->worklist->expects($this->exactly(2))->method('summary')->with(userId: 'agent')
			->willReturnCallback(
				static function (): string {
					static $calls = 0;
					$calls++;
					if ($calls === 2) {
						throw new RuntimeException('db down');
					}

					return '3 to answer today';
				}
			);
		$this->logger->expects($this->once())->method('warning')->with(message: '[WorklistController] summary failed', context: ['error' => 'db down']);

		$this->assertSame(['text' => '3 to answer today'], $this->controller()->summary()->getData());
		$this->assertSame(500, $this->controller()->summary()->getStatus());
	}//end testSummary()

	/**
	 * Without a session neither the items nor the summary are read.
	 *
	 * @return void
	 */
	public function testWithoutASessionTheAnswerIs401(): void {
		$this->worklist->expects($this->never())->method('items');
		$this->worklist->expects($this->never())->method('summary');

		$this->assertSame(401, $this->controller(signed: false)->items()->getStatus());
		$this->assertSame(401, $this->controller(signed: false)->summary()->getStatus());
	}//end testWithoutASessionTheAnswerIs401()
	/**
	 * The priority and pipeline filters are passed on as the page sends them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-priority-and-pipeline-filters-req-mw-200
	 */
	public function testItemsPassThePriorityAndPipelineFilters(): void {
		$this->worklist->expects($this->once())->method('items')
			->with(
				userId: 'agent',
				view: [
					'bucket'   => '',
					'lens'     => '',
					'sort'     => 'deadline',
					'done'     => false,
					'scope'    => 'mine',
					'member'   => '',
					'priority' => 'urgent,high',
					'pipeline' => 'pipe-sales',
				]
			)
			->willReturn(['items' => []]);

		$response = $this->controller(params: ['priority' => 'urgent,high', 'pipeline' => 'pipe-sales'])->items();

		$this->assertSame(expected: 200, actual: $response->getStatus());
	}//end testItemsPassThePriorityAndPipelineFilters()

	/**
	 * An unknown priority is refused before the worklist is read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-priority-and-pipeline-filters-req-mw-200
	 */
	public function testAnUnknownPriorityIs400(): void {
		$this->worklist->expects($this->never())->method('items');

		$refused = $this->controller(params: ['priority' => 'urgent,critical'])->items();

		$this->assertSame(expected: 400, actual: $refused->getStatus());
		$this->assertSame(expected: ['message' => 'Invalid priority'], actual: $refused->getData());
	}//end testAnUnknownPriorityIs400()
}//end class
