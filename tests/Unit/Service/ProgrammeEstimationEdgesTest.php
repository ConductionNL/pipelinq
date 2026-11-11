<?php

/**
 * ProgrammeEstimationService: every scale, estimate, cycle and carry-over rule.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Pipelinq\Service\ProgrammeEstimationService;
use OCA\Pipelinq\Service\ProgrammePortfolioService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The edges the scenario tests do not reach.
 */
class ProgrammeEstimationEdgesTest extends TestCase {

	/** @var array<int, array{0: string, 1: array<string, mixed>, 2: ?string}> */
	private array $writes = [];

	/**
	 * The service over a portfolio whose writes answer as told.
	 *
	 * @param array<int, bool>     $answers The write answers, in order (true when absent).
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return ProgrammeEstimationService
	 */
	private function service(array $answers = [], ?LoggerInterface $logger = null): ProgrammeEstimationService {
		$portfolio = $this->createMock(ProgrammePortfolioService::class);
		$portfolio->method('write')->willReturnCallback(
			function (string $schemaKey, array $object, ?string $uuid = null) use (&$answers): bool {
				$this->writes[] = [$schemaKey, $object, $uuid];
				return (array_shift($answers) ?? true);
			}
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('maria');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new ProgrammeEstimationService($portfolio, $session, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end service()

	/**
	 * Every activation rule: a scale needs a scope; only an active scale in
	 * the same (trimmed) scope blocks; the scale itself (by id or uuid) does
	 * not; an existing scale without an id always blocks; the reason names
	 * the scale, or its id without a name.
	 *
	 * @return void
	 */
	public function testEveryActivationRule(): void {
		$service = $this->service();
		$this->assertSame(['allowed' => false, 'reason' => 'A scale needs a scope.'], $service->mayActivate(scale: ['scope' => ' '], existingScales: []));

		$existing = [
			['id' => 'a', 'scope' => 'team', 'active' => false],
			['id' => 'b', 'scope' => 'other', 'active' => true],
			['id' => 'c', 'scope' => 'team', 'active' => 'yes'],
			['uuid' => ' self ', 'scope' => ' team ', 'active' => true, 'name' => 'Self'],
		];
		$this->assertSame(['allowed' => true, 'reason' => ''], $service->mayActivate(scale: ['uuid' => 'self', 'scope' => 'team '], existingScales: $existing));
		$this->assertSame(
			['allowed' => false, 'reason' => 'Scope team already has an active scale: Self.'],
			$service->mayActivate(scale: ['id' => 'new', 'scope' => 'team'], existingScales: $existing)
		);
		$this->assertSame(
			['allowed' => false, 'reason' => 'Scope team already has an active scale: d.'],
			$service->mayActivate(scale: ['id' => 'new', 'scope' => 'team'], existingScales: [['uuid' => ' d ', 'scope' => 'team', 'active' => true]])
		);
		$this->assertSame(
			['allowed' => false, 'reason' => 'Scope team already has an active scale: .'],
			$service->mayActivate(scale: ['scope' => 'team'], existingScales: [['scope' => 'team', 'active' => true]])
		);
	}//end testEveryActivationRule()

	/**
	 * Every estimate rule: an estimate without a role is skipped; an
	 * unresolvable point is reported per role; a later estimate for a role
	 * replaces the earlier; scale and point are trimmed.
	 *
	 * @return void
	 */
	public function testEveryEstimateRule(): void {
		$scales = ['fib' => ['points' => [['label' => '3', 'weight' => 3], ['label' => '5', 'weight' => '5.5']]]];
		$this->assertSame(
			['perRole' => ['dev' => 5.5, 'qa' => 3.0], 'total' => 8.5, 'unresolved' => ['ux', 'po']],
			$this->service()->totalFor(
				estimates: [
					['role' => 'dev', 'scale' => 'fib', 'point' => '3'],
					['role' => ' ', 'scale' => 'fib', 'point' => '3'],
					['role' => 'qa', 'scale' => ' fib ', 'point' => ' 3 '],
					['role' => 'ux', 'scale' => 'fib', 'point' => '8'],
					['role' => 'po', 'scale' => 'none', 'point' => '3'],
					['role' => ' dev ', 'scale' => 'fib', 'point' => '5'],
				],
				scales: $scales
			)
		);
	}//end testEveryEstimateRule()

	/**
	 * Every cycle rule: snapshots that are not a list start over; a close
	 * appends a numbered snapshot, marks the cycle closed and writes it
	 * under its id or uuid; a failed write is a 500; the chart is live until
	 * the cycle is closed with a snapshot, then the last snapshot.
	 *
	 * @return void
	 */
	public function testEveryCycleRule(): void {
		$tasks = [['status' => 'closed'], ['status' => 'open'], []];
		$service = $this->service(answers: [true, false]);

		$snapshot = $service->snapshotFor(cycle: ['snapshots' => 'junk'], done: 1, total: 3, now: new DateTimeImmutable('2026-05-01T10:00:00+00:00'));
		$this->assertSame(['version' => 1, 'closedAt' => '2026-05-01T10:00:00+00:00', 'closedBy' => 'maria', 'done' => 1, 'total' => 3], $snapshot);

		$closed = $service->closeCycle(cycle: ['uuid' => 'c-1', 'snapshots' => [['version' => 1]]], tasks: $tasks);
		$this->assertSame(200, $closed['status']);
		$this->assertSame('closed', $closed['cycle']['status']);
		$this->assertCount(2, $closed['cycle']['snapshots']);
		$this->assertSame([2, 1, 3], [$closed['cycle']['snapshots'][1]['version'], $closed['cycle']['snapshots'][1]['done'], $closed['cycle']['snapshots'][1]['total']]);
		$this->assertSame(['programmeCycle_schema', $closed['cycle'], 'c-1'], $this->writes[0]);

		$this->assertSame(['status' => 500, 'error' => 'The cycle could not be closed.'], $service->closeCycle(cycle: ['id' => 'c-2', 'snapshots' => 'junk'], tasks: []));
		$this->assertSame([['version' => 1, 'closedAt' => $this->writes[1][1]['snapshots'][0]['closedAt'], 'closedBy' => 'maria', 'done' => 0, 'total' => 0]], $this->writes[1][1]['snapshots']);

		$live = ['done' => 1, 'total' => 3];
		$this->assertSame(['chart' => $live, 'live' => $live, 'source' => 'live'], $service->chartFor(cycle: ['status' => 'open', 'snapshots' => [['done' => 9]]], tasks: $tasks));
		$this->assertSame(['chart' => $live, 'live' => $live, 'source' => 'live'], $service->chartFor(cycle: ['status' => 'closed', 'snapshots' => 'junk'], tasks: $tasks));
		$this->assertSame(['chart' => ['done' => 2], 'live' => $live, 'source' => 'snapshot'], $service->chartFor(cycle: ['status' => 'closed', 'snapshots' => [['done' => 9], ['done' => 2]]], tasks: $tasks));
	}//end testEveryCycleRule()

	/**
	 * Every carry-over rule: a blank target is a 400 with no write; closed
	 * work stays; each other task is written under the target with its id or
	 * uuid; a failed write is logged and left out of the trail.
	 *
	 * @return void
	 */
	public function testEveryCarryOverRule(): void {
		$this->assertSame(['status' => 400, 'error' => 'A target cycle is required.'], $this->service()->carryOver(targetCycleId: ' ', tasks: [['id' => 't']]));
		$this->assertSame([], $this->writes);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('ProgrammeEstimationService: a task could not be carried over', ['task' => 't2']);
		$result = $this->service(answers: [true, false, true], logger: $logger)->carryOver(
			targetCycleId: ' c-9 ',
			tasks: [
				['uuid' => 'u1', 'cycle' => 'c-1'],
				['id' => 't2', 'cycle' => 'c-1', 'status' => 'open'],
				['id' => 't3', 'status' => 'closed'],
				['status' => 'open'],
			]
		);

		$this->assertSame(['status' => 200, 'moved' => 2, 'trail' => [['task' => 'u1', 'from' => 'c-1', 'to' => 'c-9'], ['task' => '', 'from' => '', 'to' => 'c-9']]], $result);
		$this->assertSame(
			[
				['programmeTask_schema', ['uuid' => 'u1', 'cycle' => 'c-9'], 'u1'],
				['programmeTask_schema', ['id' => 't2', 'cycle' => 'c-9', 'status' => 'open'], 't2'],
				['programmeTask_schema', ['status' => 'open', 'cycle' => 'c-9'], ''],
			],
			$this->writes
		);
	}//end testEveryCarryOverRule()
}//end class
