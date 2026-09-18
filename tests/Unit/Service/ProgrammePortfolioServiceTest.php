<?php

/**
 * Unit tests for ProgrammePortfolioService and ProgrammeEstimationService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ProgrammeEstimationService;
use OCA\Pipelinq\Service\ProgrammePortfolioService;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the programme above the cases.
 */
class ProgrammePortfolioServiceTest extends TestCase {
	/**
	 * The rows the double holds, keyed by schema id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Every object written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * The instance's default progress mode.
	 *
	 * @var string
	 */
	private string $defaultMode = 'fromTasks';

	/**
	 * Build the portfolio service over doubles.
	 *
	 * @return ProgrammePortfolioService The service under test.
	 */
	private function portfolio(): ProgrammePortfolioService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'reg-1',
					'programme_schema' => 'sch-programme',
					'programmeTask_schema' => 'sch-task',
					'programmeWorkItem_schema' => 'sch-workitem',
					'programmeCycle_schema' => 'sch-cycle',
					ProgrammePortfolioService::DEFAULT_MODE_KEY => $this->defaultMode,
				];

				return ($values[$key] ?? $default);
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config): array {
				return ($this->rows[($config['filters']['schema'] ?? '')] ?? []);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->written[] = $object;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);

				return $entity;
			}
		);

		return new ProgrammePortfolioService(
			$appConfig,
			$objectService,
			$this->createMock(LoggerInterface::class),
		);
	}//end portfolio()

	/**
	 * Build the estimation service over the portfolio double.
	 *
	 * @return ProgrammeEstimationService The service under test.
	 */
	private function estimation(): ProgrammeEstimationService {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('maria');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ProgrammeEstimationService(
			$this->portfolio(),
			$session,
			$this->createMock(LoggerInterface::class),
		);
	}//end estimation()

	/**
	 * A case already in one programme cannot be put in a second.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-project-shall-hold-work-it-does-not-own-by-reference-req-prj-002
	 */
	public function testACaseCannotBeInTwoProgrammes(): void {
		$answer = $this->portfolio()->mayLink(
			domainObjectType: 'dossiq:zaak',
			domainObjectRef: 'zaak-1',
			programmeId: 'programme-b',
			existingLinks: [
				['programme' => 'programme-a', 'domainObjectType' => 'dossiq:zaak', 'domainObjectRef' => 'zaak-1'],
			],
		);

		$this->assertFalse($answer['allowed']);
		$this->assertStringContainsString('programme-a', $answer['reason']);
	}//end testACaseCannotBeInTwoProgrammes()

	/**
	 * A different object of the same type is not refused.
	 *
	 * The control: a rule that refused every link would pass the test above.
	 *
	 * @return void
	 */
	public function testADifferentObjectIsNotRefused(): void {
		$answer = $this->portfolio()->mayLink(
			domainObjectType: 'dossiq:zaak',
			domainObjectRef: 'zaak-2',
			programmeId: 'programme-b',
			existingLinks: [
				['programme' => 'programme-a', 'domainObjectType' => 'dossiq:zaak', 'domainObjectRef' => 'zaak-1'],
			],
		);

		$this->assertTrue($answer['allowed']);
	}//end testADifferentObjectIsNotRefused()

	/**
	 * A reference to an absent app is shown as unresolved, not hidden.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-project-shall-hold-work-it-does-not-own-by-reference-req-prj-002
	 */
	public function testAnUnresolvableReferenceIsShownNotSwallowed(): void {
		$row = $this->portfolio()->presentWorkItem(
			workItem: [
				'id' => 'wi-1',
				'domainObjectType' => 'dossiq:zaak',
				'domainObjectRef' => 'zaak-1',
				'title' => 'Bezwaar Kerkstraat',
			],
			resolvedTitles: [],
			installedApps: ['pipelinq'],
		);

		$this->assertFalse($row['resolved']);
		$this->assertSame('dossiq', $row['app']);
		$this->assertSame('zaak-1', $row['domainObjectRef']);
		$this->assertSame(
			'Bezwaar Kerkstraat',
			$row['title'],
			'What the item was called when it was linked keeps it visible.'
		);
	}//end testAnUnresolvableReferenceIsShownNotSwallowed()

	/**
	 * A resolved title wins over the stored fallback.
	 *
	 * @return void
	 */
	public function testAResolvedTitleWins(): void {
		$row = $this->portfolio()->presentWorkItem(
			workItem: ['domainObjectType' => 'dossiq:zaak', 'domainObjectRef' => 'zaak-1', 'title' => 'Oude titel'],
			resolvedTitles: ['zaak-1' => 'Nieuwe titel'],
			installedApps: ['pipelinq', 'dossiq'],
		);

		$this->assertTrue($row['resolved']);
		$this->assertSame('Nieuwe titel', $row['title']);
	}//end testAResolvedTitleWins()

	/**
	 * A derived figure moves when its source does, and names its mode.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-progress-shall-declare-which-mode-produced-it-req-prj-003
	 */
	public function testFromTasksMovesWithTheTasks(): void {
		$service = $this->portfolio();
		$tasks = [
			['status' => 'closed'],
			['status' => 'closed'],
			['status' => 'open'],
			['status' => 'open'],
		];

		$before = $service->progressFor(programme: ['progressMode' => 'fromTasks'], tasks: $tasks);
		$this->assertSame(50, $before['progress']);
		$this->assertSame('fromTasks', $before['mode']);

		$tasks[2]['status'] = 'closed';
		$after = $service->progressFor(programme: ['progressMode' => 'fromTasks'], tasks: $tasks);

		$this->assertSame(75, $after['progress'], 'Nobody typed this.');
	}//end testFromTasksMovesWithTheTasks()

	/**
	 * A typed figure is told apart from a derived one.
	 *
	 * @return void
	 */
	public function testATypedFigureNamesItself(): void {
		$answer = $this->portfolio()->progressFor(
			programme: ['progressMode' => 'manual', 'manualProgress' => 40]
		);

		$this->assertSame('manual', $answer['mode']);
		$this->assertSame(40, $answer['progress']);
	}//end testATypedFigureNamesItself()

	/**
	 * An uncomputable progress is said, not shown as zero.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-progress-shall-declare-which-mode-produced-it-req-prj-003
	 */
	public function testAnUncomputableProgressIsSaid(): void {
		$answer = $this->portfolio()->progressFor(
			programme: ['progressMode' => 'fromEffort'],
			tasks: [],
			effort: null,
		);

		$this->assertSame('fromEffort', $answer['mode']);
		$this->assertNull($answer['progress'], 'Zero would read as "nothing has been done".');
		$this->assertFalse($answer['computable']);
		$this->assertStringContainsString('cannot be computed', $answer['reason']);
	}//end testAnUncomputableProgressIsSaid()

	/**
	 * The per-programme mode overrides the instance default.
	 *
	 * @return void
	 */
	public function testThePerProgrammeModeOverridesTheDefault(): void {
		$this->defaultMode = 'manual';
		$service = $this->portfolio();

		$this->assertSame('manual', $service->modeFor(programme: []));
		$this->assertSame('fromTasks', $service->modeFor(programme: ['progressMode' => 'fromTasks']));
	}//end testThePerProgrammeModeOverridesTheDefault()

	/**
	 * A second active scale in one scope is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-an-estimation-scale-shall-be-administered-with-one-active-set-per-scope-req-prj-004
	 */
	public function testASecondActiveScaleInOneScopeIsRefused(): void {
		$answer = $this->estimation()->mayActivate(
			scale: ['id' => 'scale-2', 'scope' => 'unit-1', 'active' => true],
			existingScales: [['id' => 'scale-1', 'scope' => 'unit-1', 'active' => true, 'name' => 'Fibonacci']],
		);

		$this->assertFalse($answer['allowed']);
		$this->assertStringContainsString('Fibonacci', $answer['reason']);
	}//end testASecondActiveScaleInOneScopeIsRefused()

	/**
	 * Another scope, and re-activating the same scale, are both allowed.
	 *
	 * @return void
	 */
	public function testAnotherScopeIsAllowed(): void {
		$service = $this->estimation();
		$existing = [['id' => 'scale-1', 'scope' => 'unit-1', 'active' => true, 'name' => 'Fibonacci']];

		$this->assertTrue(
			$service->mayActivate(scale: ['id' => 'scale-2', 'scope' => 'unit-2', 'active' => true], existingScales: $existing)['allowed']
		);
		$this->assertTrue(
			$service->mayActivate(scale: ['id' => 'scale-1', 'scope' => 'unit-1', 'active' => true], existingScales: $existing)['allowed']
		);
	}//end testAnotherScopeIsAllowed()

	/**
	 * A retired scale still resolves an estimate written on it.
	 *
	 * @return void
	 */
	public function testARetiredScaleStillResolves(): void {
		$retired = ['id' => 'scale-1', 'active' => false, 'points' => [['label' => '5', 'weight' => 5]]];

		$this->assertSame(5.0, $this->estimation()->weightOf(scale: $retired, point: '5'));
	}//end testARetiredScaleStillResolves()

	/**
	 * Two roles, two numbers, one derived total.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-work-item-shall-be-estimable-per-role-with-a-derived-total-req-prj-005
	 */
	public function testTwoRolesTwoNumbersOneTotal(): void {
		$scales = [
			'scale-1' => [
				'id' => 'scale-1',
				'points' => [
					['label' => '3', 'weight' => 3],
					['label' => '5', 'weight' => 5],
					['label' => '8', 'weight' => 8],
				],
			],
		];

		$service = $this->estimation();

		$first = $service->totalFor(
			estimates: [
				['role' => 'juridisch', 'point' => '5', 'scale' => 'scale-1'],
				['role' => 'vakafdeling', 'point' => '3', 'scale' => 'scale-1'],
			],
			scales: $scales,
		);

		$this->assertSame(['juridisch' => 5.0, 'vakafdeling' => 3.0], $first['perRole']);
		$this->assertSame(8.0, $first['total']);

		// The total cannot drift from its parts, because nothing stores it.
		$second = $service->totalFor(
			estimates: [
				['role' => 'juridisch', 'point' => '8', 'scale' => 'scale-1'],
				['role' => 'vakafdeling', 'point' => '3', 'scale' => 'scale-1'],
			],
			scales: $scales,
		);

		$this->assertSame(11.0, $second['total']);
	}//end testTwoRolesTwoNumbersOneTotal()

	/**
	 * A closed cycle's chart reads its snapshot, whatever changed underneath.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/the-project-above-the-cases/specs/project-portfolio/spec.md#requirement-a-cycle-shall-snapshot-its-progress-when-it-closes-req-prj-006
	 */
	public function testAClosedCyclesChartStillReadsSixOfTen(): void {
		$service = $this->estimation();

		$tasks = array_merge(
			array_fill(0, 6, ['status' => 'closed']),
			array_fill(0, 4, ['status' => 'open']),
		);

		$closed = $service->closeCycle(cycle: ['id' => 'cycle-1', 'status' => 'open'], tasks: $tasks);
		$this->assertSame(200, $closed['status']);

		// April: somebody reopens an item. The chart must not move.
		$tasksAfter = array_merge(
			array_fill(0, 3, ['status' => 'closed']),
			array_fill(0, 7, ['status' => 'open']),
		);

		$chart = $service->chartFor(cycle: $closed['cycle'], tasks: $tasksAfter);

		$this->assertSame('snapshot', $chart['source']);
		$this->assertSame(6, $chart['chart']['done']);
		$this->assertSame(10, $chart['chart']['total']);
		$this->assertSame(3, $chart['live']['done'], 'The live figures sit beside it, not instead of it.');
	}//end testAClosedCyclesChartStillReadsSixOfTen()

	/**
	 * Each close gets its own version, and neither is lost.
	 *
	 * @return void
	 */
	public function testEachCloseGetsItsOwnVersion(): void {
		$service = $this->estimation();

		$first = $service->closeCycle(
			cycle: ['id' => 'cycle-1', 'status' => 'open'],
			tasks: [['status' => 'closed']],
		);

		$reopened = $first['cycle'];
		$reopened['status'] = 'open';

		$second = $service->closeCycle(
			cycle: $reopened,
			tasks: [['status' => 'closed'], ['status' => 'closed']],
		);

		$this->assertCount(2, $second['cycle']['snapshots']);
		$this->assertSame(1, $second['cycle']['snapshots'][0]['version']);
		$this->assertSame(2, $second['cycle']['snapshots'][1]['version']);
		$this->assertSame('maria', $second['cycle']['snapshots'][1]['closedBy']);
	}//end testEachCloseGetsItsOwnVersion()

	/**
	 * A carry-over moves the unfinished work and leaves the rest.
	 *
	 * @return void
	 */
	public function testACarryOverMovesOnlyTheUnfinishedWork(): void {
		$result = $this->estimation()->carryOver(
			targetCycleId: 'cycle-2',
			tasks: [
				['id' => 't1', 'status' => 'open', 'cycle' => 'cycle-1'],
				['id' => 't2', 'status' => 'closed', 'cycle' => 'cycle-1'],
				['id' => 't3', 'status' => 'inProgress', 'cycle' => 'cycle-1'],
			],
		);

		$this->assertSame(2, $result['moved']);
		$this->assertSame(['t1', 't3'], array_column($result['trail'], 'task'));
		$this->assertSame('cycle-1', $result['trail'][0]['from']);
		$this->assertSame('cycle-2', $result['trail'][0]['to']);
	}//end testACarryOverMovesOnlyTheUnfinishedWork()
}//end class
