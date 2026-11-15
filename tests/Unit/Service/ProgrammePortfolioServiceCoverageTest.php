<?php

/**
 * Coverage tests for ProgrammePortfolioService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ProgrammePortfolioService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Exercises the mode resolution, link rules, progress modes and the
 * OpenRegister read/write seams of ProgrammePortfolioService.
 *
 * @covers \OCA\Pipelinq\Service\ProgrammePortfolioService
 */
class ProgrammePortfolioServiceCoverageTest extends TestCase {
	/**
	 * App-config values the double answers, keyed by config key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The object-service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up a fully-configured instance.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = [
			'register' => 'reg-1',
			'programmeTask_schema' => 'sch-task',
			'programmeWorkItem_schema' => 'sch-workitem',
		];
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the service over the doubles.
	 *
	 * @return ProgrammePortfolioService The service under test.
	 */
	private function service(): ProgrammePortfolioService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return ($this->config[$key] ?? $default);
			}
		);

		return new ProgrammePortfolioService(
			appConfig: $appConfig,
			objectService: $this->objectService,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * An unknown configured default falls back to fromTasks.
	 *
	 * @return void
	 */
	public function testUnknownDefaultModeFallsBackToFromTasks(): void {
		$this->config[ProgrammePortfolioService::DEFAULT_MODE_KEY] = 'guesswork';

		$this->assertSame(expected: 'fromTasks', actual: $this->service()->modeFor(programme: []));
	}//end testUnknownDefaultModeFallsBackToFromTasks()

	/**
	 * A configured default is trimmed and honoured.
	 *
	 * @return void
	 */
	public function testConfiguredDefaultModeIsTrimmedAndUsed(): void {
		$this->config[ProgrammePortfolioService::DEFAULT_MODE_KEY] = '  manual ';

		$this->assertSame(expected: 'manual', actual: $this->service()->modeFor(programme: []));
	}//end testConfiguredDefaultModeIsTrimmedAndUsed()

	/**
	 * An unknown per-programme override falls through to the default.
	 *
	 * @return void
	 */
	public function testUnknownOverrideUsesDefault(): void {
		$this->config[ProgrammePortfolioService::DEFAULT_MODE_KEY] = 'fromEffort';

		$this->assertSame(expected: 'fromEffort', actual: $this->service()->modeFor(programme: ['progressMode' => 'random']));
	}//end testUnknownOverrideUsesDefault()

	/**
	 * A blank object type is refused.
	 *
	 * @return void
	 */
	public function testMayLinkRefusesBlankType(): void {
		$answer = $this->service()->mayLink('  ', 'uuid-1', 'prog-1', []);

		$this->assertSame(
			expected: ['allowed' => false, 'reason' => 'A programme, an object type and an object are required.'],
			actual: $answer
		);
	}//end testMayLinkRefusesBlankType()

	/**
	 * A blank object reference is refused.
	 *
	 * @return void
	 */
	public function testMayLinkRefusesBlankRef(): void {
		$answer = $this->service()->mayLink('procest:case', ' ', 'prog-1', []);

		$this->assertFalse(condition: $answer['allowed']);
		$this->assertSame(expected: 'A programme, an object type and an object are required.', actual: $answer['reason']);
	}//end testMayLinkRefusesBlankRef()

	/**
	 * A blank programme id is refused.
	 *
	 * @return void
	 */
	public function testMayLinkRefusesBlankProgramme(): void {
		$answer = $this->service()->mayLink('procest:case', 'uuid-1', '   ', []);

		$this->assertFalse(condition: $answer['allowed']);
	}//end testMayLinkRefusesBlankProgramme()

	/**
	 * A link to the same object in the same programme is refused as a duplicate.
	 *
	 * @return void
	 */
	public function testMayLinkRefusesSameProgrammeDuplicate(): void {
		$links = [
			['domainObjectType' => 'procest:case', 'domainObjectRef' => 'uuid-1', 'programme' => 'prog-1'],
		];

		$answer = $this->service()->mayLink(' procest:case ', ' uuid-1 ', ' prog-1 ', $links);

		$this->assertSame(expected: ['allowed' => false, 'reason' => 'This work is already in this programme.'], actual: $answer);
	}//end testMayLinkRefusesSameProgrammeDuplicate()

	/**
	 * A link held by another programme is refused, naming that programme.
	 *
	 * @return void
	 */
	public function testMayLinkNamesTheHoldingProgramme(): void {
		$links = [
			['domainObjectType' => 'other:thing', 'domainObjectRef' => 'uuid-1', 'programme' => 'prog-x'],
			['domainObjectType' => 'procest:case', 'domainObjectRef' => 'uuid-2', 'programme' => 'prog-y'],
			['domainObjectType' => 'procest:case', 'domainObjectRef' => 'uuid-1', 'programme' => 'prog-9'],
		];

		$answer = $this->service()->mayLink('procest:case', 'uuid-1', 'prog-1', $links);

		$this->assertSame(expected: ['allowed' => false, 'reason' => 'This work is already in programme prog-9.'], actual: $answer);
	}//end testMayLinkNamesTheHoldingProgramme()

	/**
	 * Links to other objects do not block a new link.
	 *
	 * @return void
	 */
	public function testMayLinkAllowsUnrelatedLinks(): void {
		$links = [
			['domainObjectType' => 'procest:case', 'domainObjectRef' => 'uuid-2', 'programme' => 'prog-1'],
			[],
		];

		$answer = $this->service()->mayLink('procest:case', 'uuid-1', 'prog-1', $links);

		$this->assertSame(expected: ['allowed' => true, 'reason' => ''], actual: $answer);
	}//end testMayLinkAllowsUnrelatedLinks()

	/**
	 * A resolved title labels the row and marks it resolved.
	 *
	 * @return void
	 */
	public function testPresentWorkItemResolved(): void {
		$row = $this->service()->presentWorkItem(
			workItem: [
				'id' => 'wi-1',
				'programme' => 'prog-1',
				'domainObjectType' => 'procest:case',
				'domainObjectRef' => 'uuid-1',
				'title' => 'Stale title',
			],
			resolvedTitles: ['uuid-1' => 'Live title'],
			installedApps: ['procest'],
		);

		$this->assertSame(
			expected: [
				'id' => 'wi-1',
				'programme' => 'prog-1',
				'domainObjectType' => 'procest:case',
				'domainObjectRef' => 'uuid-1',
				'app' => 'procest',
				'title' => 'Live title',
				'resolved' => true,
			],
			actual: $row
		);
	}//end testPresentWorkItemResolved()

	/**
	 * When the owning app is absent the stored title is shown, unresolved.
	 *
	 * @return void
	 */
	public function testPresentWorkItemMissingAppKeepsStoredTitle(): void {
		$row = $this->service()->presentWorkItem(
			workItem: [
				'uuid' => 'wi-2',
				'domainObjectType' => 'procest:case',
				'domainObjectRef' => 'uuid-1',
				'title' => '  Stored title ',
			],
			resolvedTitles: [],
			installedApps: ['pipelinq'],
		);

		$this->assertSame(expected: 'wi-2', actual: $row['id']);
		$this->assertSame(expected: '', actual: $row['programme']);
		$this->assertSame(expected: 'Stored title', actual: $row['title']);
		$this->assertFalse(condition: $row['resolved']);
	}//end testPresentWorkItemMissingAppKeepsStoredTitle()

	/**
	 * An installed app that answered nothing falls back to the reference label.
	 *
	 * @return void
	 */
	public function testPresentWorkItemInstalledButUnansweredFallsBackToRef(): void {
		$row = $this->service()->presentWorkItem(
			workItem: [
				'domainObjectType' => 'procest:case',
				'domainObjectRef' => 'uuid-7',
				'title' => 'Stored title',
			],
			resolvedTitles: [],
			installedApps: ['procest'],
		);

		$this->assertSame(expected: '', actual: $row['id']);
		$this->assertSame(expected: 'uuid-7', actual: $row['title']);
		$this->assertFalse(condition: $row['resolved']);
	}//end testPresentWorkItemInstalledButUnansweredFallsBackToRef()

	/**
	 * Without an installed-apps list the reference is the label.
	 *
	 * @return void
	 */
	public function testPresentWorkItemWithoutAppListUsesRef(): void {
		$row = $this->service()->presentWorkItem(
			workItem: ['domainObjectType' => 'procest:case', 'domainObjectRef' => 'uuid-8', 'title' => 'T'],
		);

		$this->assertSame(expected: 'uuid-8', actual: $row['title']);
		$this->assertSame(expected: 'procest', actual: $row['app']);
	}//end testPresentWorkItemWithoutAppListUsesRef()

	/**
	 * Manual mode with a typed figure reports it as computable.
	 *
	 * @return void
	 */
	public function testManualProgressEntered(): void {
		$result = $this->service()->progressFor(programme: ['progressMode' => 'manual', 'manualProgress' => '42']);

		$this->assertSame(expected: ['mode' => 'manual', 'progress' => 42, 'computable' => true, 'reason' => ''], actual: $result);
	}//end testManualProgressEntered()

	/**
	 * Manual mode without a figure says so rather than reporting zero.
	 *
	 * @return void
	 */
	public function testManualProgressNotEntered(): void {
		$result = $this->service()->progressFor(programme: ['progressMode' => 'manual', 'manualProgress' => 'n/a']);

		$this->assertSame(
			expected: ['mode' => 'manual', 'progress' => null, 'computable' => false, 'reason' => 'No progress has been entered.'],
			actual: $result
		);
	}//end testManualProgressNotEntered()

	/**
	 * FromTasks with no tasks is not computable.
	 *
	 * @return void
	 */
	public function testFromTasksWithoutTasks(): void {
		$result = $this->service()->progressFor(programme: ['progressMode' => 'fromTasks'], tasks: []);

		$this->assertSame(
			expected: [
				'mode' => 'fromTasks',
				'progress' => null,
				'computable' => false,
				'reason' => 'This programme has no tasks to derive progress from.',
			],
			actual: $result
		);
	}//end testFromTasksWithoutTasks()

	/**
	 * FromTasks rounds the closed share to a whole percentage.
	 *
	 * @return void
	 */
	public function testFromTasksRoundsClosedShare(): void {
		$tasks = [['status' => 'closed'], ['status' => 'open'], []];

		$result = $this->service()->progressFor(programme: ['progressMode' => 'fromTasks'], tasks: $tasks);

		$this->assertSame(expected: ['mode' => 'fromTasks', 'progress' => 33, 'computable' => true, 'reason' => ''], actual: $result);
	}//end testFromTasksRoundsClosedShare()

	/**
	 * FromEffort without readable hours is not computable (and not zero).
	 *
	 * @return void
	 */
	public function testFromEffortWithoutHours(): void {
		$result = $this->service()->progressFor(programme: ['progressMode' => 'fromEffort'], effort: null);

		$this->assertNull(actual: $result['progress']);
		$this->assertFalse(condition: $result['computable']);
		$this->assertSame(expected: 'fromEffort', actual: $result['mode']);
		$this->assertSame(expected: 'Hours cannot be read on this instance, so progress cannot be computed.', actual: $result['reason']);
	}//end testFromEffortWithoutHours()

	/**
	 * FromEffort with nothing estimated is not computable.
	 *
	 * @return void
	 */
	public function testFromEffortWithoutEstimate(): void {
		$result = $this->service()->progressFor(
			programme: ['progressMode' => 'fromEffort'],
			effort: ['booked' => 5, 'estimated' => 0],
		);

		$this->assertSame(
			expected: [
				'mode' => 'fromEffort',
				'progress' => null,
				'computable' => false,
				'reason' => 'Nothing has been estimated, so progress cannot be computed.',
			],
			actual: $result
		);
	}//end testFromEffortWithoutEstimate()

	/**
	 * FromEffort derives booked over estimated.
	 *
	 * @return void
	 */
	public function testFromEffortComputesShare(): void {
		$result = $this->service()->progressFor(
			programme: ['progressMode' => 'fromEffort'],
			effort: ['booked' => 3, 'estimated' => 8],
		);

		$this->assertSame(expected: ['mode' => 'fromEffort', 'progress' => 38, 'computable' => true, 'reason' => ''], actual: $result);
	}//end testFromEffortComputesShare()

	/**
	 * FromEffort with no booked hours reports zero, which is then true.
	 *
	 * @return void
	 */
	public function testFromEffortWithoutBookedIsZero(): void {
		$result = $this->service()->progressFor(
			programme: ['progressMode' => 'fromEffort'],
			effort: ['estimated' => 10],
		);

		$this->assertSame(expected: 0, actual: $result['progress']);
		$this->assertTrue(condition: $result['computable']);
	}//end testFromEffortWithoutBookedIsZero()

	/**
	 * WorkItemsOf filters on the trimmed programme within the configured schema.
	 *
	 * @return void
	 */
	public function testWorkItemsOfQueriesByProgramme(): void {
		$this->objectService->expects($this->once())
			->method('findAll')
			->with(
				[
					'filters' => ['register' => 'reg-1', 'schema' => 'sch-workitem', 'programme' => 'prog-1'],
					'limit' => 500,
				]
			)
			->willReturn([['id' => 'wi-1']]);

		$this->assertSame(expected: [['id' => 'wi-1']], actual: $this->service()->workItemsOf(' prog-1 '));
	}//end testWorkItemsOfQueriesByProgramme()

	/**
	 * TasksOf reads the task schema.
	 *
	 * @return void
	 */
	public function testTasksOfQueriesTaskSchema(): void {
		$this->objectService->expects($this->once())
			->method('findAll')
			->with(
				[
					'filters' => ['register' => 'reg-1', 'schema' => 'sch-task', 'programme' => 'prog-2'],
					'limit' => 500,
				]
			)
			->willReturn([]);

		$this->assertSame(expected: [], actual: $this->service()->tasksOf('prog-2'));
	}//end testTasksOfQueriesTaskSchema()

	/**
	 * Read normalises arrays and JSON-serialisable entities, dropping the rest.
	 *
	 * @return void
	 */
	public function testReadNormalisesRows(): void {
		$entity = new class implements JsonSerializable {
			/**
			 * Serialise.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'from-entity'];
			}//end jsonSerialize()
		};
		$scalarEntity = new class implements JsonSerializable {
			/**
			 * Serialise to a non-array.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'scalar';
			}//end jsonSerialize()
		};

		$this->objectService->method('findAll')->willReturn(
			[['id' => 'plain'], $entity, $scalarEntity, 'junk', new \stdClass()]
		);

		$rows = $this->service()->read(schemaKey: 'programmeTask_schema', filters: []);

		$this->assertSame(expected: [['id' => 'plain'], ['id' => 'from-entity']], actual: $rows);
	}//end testReadNormalisesRows()

	/**
	 * Read refuses to query when the register is not configured.
	 *
	 * @return void
	 */
	public function testReadUnconfiguredRegisterReturnsEmpty(): void {
		$this->config['register'] = '';
		$this->objectService->expects($this->never())->method('findAll');
		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'ProgrammePortfolioService: the programme surface is not configured',
				['schemaKey' => 'programmeTask_schema']
			);

		$this->assertSame(expected: [], actual: $this->service()->read(schemaKey: 'programmeTask_schema', filters: []));
	}//end testReadUnconfiguredRegisterReturnsEmpty()

	/**
	 * Read refuses to query when the schema is not configured.
	 *
	 * @return void
	 */
	public function testReadUnconfiguredSchemaReturnsEmpty(): void {
		$this->objectService->expects($this->never())->method('findAll');

		$this->assertSame(expected: [], actual: $this->service()->read(schemaKey: 'programmeCycle_schema', filters: []));
	}//end testReadUnconfiguredSchemaReturnsEmpty()

	/**
	 * A failing read is logged and degrades to an empty list.
	 *
	 * @return void
	 */
	public function testReadFailureIsLoggedAndEmpty(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'ProgrammePortfolioService: the read failed',
				['schemaKey' => 'programmeTask_schema', 'exception' => 'db down']
			);

		$this->assertSame(expected: [], actual: $this->service()->read(schemaKey: 'programmeTask_schema', filters: ['x' => 1]));
	}//end testReadFailureIsLoggedAndEmpty()

	/**
	 * Write saves the object into the configured register and schema.
	 *
	 * @return void
	 */
	public function testWriteSavesObject(): void {
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with(['a' => 1], [], 'reg-1', 'sch-task', 'uuid-5');

		$this->assertTrue(condition: $this->service()->write(schemaKey: 'programmeTask_schema', object: ['a' => 1], uuid: 'uuid-5'));
	}//end testWriteSavesObject()

	/**
	 * Write refuses when the schema is not configured.
	 *
	 * @return void
	 */
	public function testWriteUnconfiguredReturnsFalse(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$this->assertFalse(condition: $this->service()->write(schemaKey: 'programmeCycle_schema', object: ['a' => 1]));
	}//end testWriteUnconfiguredReturnsFalse()

	/**
	 * A failing write is logged and reported as false.
	 *
	 * @return void
	 */
	public function testWriteFailureIsLogged(): void {
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('nope'));
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'ProgrammePortfolioService: the write failed',
				['schemaKey' => 'programmeTask_schema', 'exception' => 'nope']
			);

		$this->assertFalse(condition: $this->service()->write(schemaKey: 'programmeTask_schema', object: []));
	}//end testWriteFailureIsLogged()

	/**
	 * LinkWork checks existing references and saves a trimmed work item.
	 *
	 * @return void
	 */
	public function testLinkWorkCreatesWorkItem(): void {
		$this->objectService->expects($this->once())
			->method('findAll')
			->with(
				[
					'filters' => [
						'register' => 'reg-1',
						'schema' => 'sch-workitem',
						'domainObjectType' => 'procest:case',
						'domainObjectRef' => 'uuid-1',
					],
					'limit' => 500,
				]
			)
			->willReturn([]);
		$expected = [
			'programme' => 'prog-1',
			'domainObjectType' => 'procest:case',
			'domainObjectRef' => 'uuid-1',
			'title' => 'My case',
		];
		$this->objectService->expects($this->once())
			->method('saveObject')
			->with($expected, [], 'reg-1', 'sch-workitem', null);

		$result = $this->service()->linkWork(' prog-1 ', ' procest:case ', ' uuid-1 ', ' My case ');

		$this->assertSame(expected: ['status' => 201, 'workItem' => $expected], actual: $result);
	}//end testLinkWorkCreatesWorkItem()

	/**
	 * LinkWork answers 409 when another programme already holds the work.
	 *
	 * @return void
	 */
	public function testLinkWorkConflict(): void {
		$this->objectService->method('findAll')->willReturn(
			[['domainObjectType' => 'procest:case', 'domainObjectRef' => 'uuid-1', 'programme' => 'prog-2']]
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->service()->linkWork('prog-1', 'procest:case', 'uuid-1');

		$this->assertSame(expected: ['status' => 409, 'error' => 'This work is already in programme prog-2.'], actual: $result);
	}//end testLinkWorkConflict()

	/**
	 * LinkWork answers 409 for missing input without touching storage writes.
	 *
	 * @return void
	 */
	public function testLinkWorkRefusesBlankInput(): void {
		$this->objectService->expects($this->never())->method('saveObject');

		$result = $this->service()->linkWork('prog-1', '', 'uuid-1');

		$this->assertSame(expected: 409, actual: $result['status']);
		$this->assertSame(expected: 'A programme, an object type and an object are required.', actual: $result['error']);
	}//end testLinkWorkRefusesBlankInput()

	/**
	 * LinkWork answers 500 when the save fails.
	 *
	 * @return void
	 */
	public function testLinkWorkSaveFailure(): void {
		$this->objectService->method('findAll')->willReturn([]);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('boom'));

		$result = $this->service()->linkWork('prog-1', 'procest:case', 'uuid-1');

		$this->assertSame(expected: ['status' => 500, 'error' => 'The work item could not be saved.'], actual: $result);
	}//end testLinkWorkSaveFailure()
}//end class
