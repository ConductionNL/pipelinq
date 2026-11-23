<?php

/**
 * Unit tests for BackfillTaskNames.
 *
 * Verifies what the repair step reports for each outcome of the task-name
 * backfill: the counts, the reason a run named nothing, and a run that
 * stopped early after naming some tasks.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\BackfillTaskNames;
use OCA\Pipelinq\Service\TaskNameBackfillService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The task-name backfill repair step's report.
 */
#[CoversClass(BackfillTaskNames::class)]
class BackfillTaskNamesTest extends TestCase {

	/**
	 * Run the step on a backfill result and collect what it reported.
	 *
	 * @param array<string, mixed> $result The service result.
	 *
	 * @return array<int, array{string, string}> Level and message, in order.
	 */
	private function report(array $result): array {
		$service = $this->createMock(TaskNameBackfillService::class);
		$service->expects($this->once())->method('backfill')->willReturn($result);

		$lines  = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $message) use (&$lines): void {
			$lines[] = ['info', $message];
		});
		$output->method('warning')->willReturnCallback(function (string $message) use (&$lines): void {
			$lines[] = ['warning', $message];
		});

		(new BackfillTaskNames($service))->run($output);
		return $lines;
	}//end report()

	/**
	 * The step names itself.
	 *
	 * @return void
	 */
	public function testName(): void {
		$step = new BackfillTaskNames($this->createMock(TaskNameBackfillService::class));

		$this->assertSame('Name existing tasks after their subject, without notifying anyone', $step->getName());
	}//end testName()

	/**
	 * A completed run reports its three counts.
	 *
	 * @return void
	 */
	public function testACompletedRunReportsTheCounts(): void {
		$this->assertSame(
			[['info', 'BackfillTaskNames: 4 task(s) named after their subject, 2 left as they were, 1 could not be saved.']],
			$this->report(['status' => 'done', 'named' => 4, 'skipped' => 2, 'failed' => 1])
		);
	}//end testACompletedRunReportsTheCounts()

	/**
	 * Each stop reason with an outcome and the reason text it reports.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function reasonProvider(): array {
		return [
			'not configured' => ['not-configured', 'the task schema is not configured yet'],
			'no silent path' => ['no-silent-path', 'this OpenRegister cannot save without notifying the assignee'],
			'scope inactive' => ['scope-inactive', 'OpenRegister\'s system scope was not active at the write'],
			'read failed' => ['read-failed', 'the tasks could not be read'],
		];
	}//end reasonProvider()

	/**
	 * A stop that named nothing reports only the reason, as a warning.
	 *
	 * @param string $status The service status.
	 * @param string $reason The reported reason.
	 *
	 * @return void
	 */
	#[DataProvider('reasonProvider')]
	public function testAStopThatNamedNothingWarnsWithTheReason(string $status, string $reason): void {
		$this->assertSame(
			[['warning', 'BackfillTaskNames: no task named, because ' . $reason . '.']],
			$this->report(['status' => $status, 'named' => 0, 'skipped' => 3, 'failed' => 0])
		);
	}//end testAStopThatNamedNothingWarnsWithTheReason()

	/**
	 * A stop after naming some tasks reports the counts and then that it stopped early.
	 *
	 * @return void
	 */
	public function testAStopAfterNamingSomeReportsCountsAndTheEarlyStop(): void {
		$this->assertSame(
			[
				['info', 'BackfillTaskNames: 2 task(s) named after their subject, 0 left as they were, 0 could not be saved.'],
				['warning', 'BackfillTaskNames: stopped early, because OpenRegister\'s system scope was not active at the write.'],
			],
			$this->report(['status' => 'scope-inactive', 'named' => 2, 'skipped' => 0, 'failed' => 0])
		);
	}//end testAStopAfterNamingSomeReportsCountsAndTheEarlyStop()

	/**
	 * An unknown status with nothing named is reported as an ordinary run.
	 *
	 * @return void
	 */
	public function testAnUnknownStatusIsReportedAsCounts(): void {
		$this->assertSame(
			[['info', 'BackfillTaskNames: 0 task(s) named after their subject, 0 left as they were, 0 could not be saved.']],
			$this->report(['status' => 'something-new', 'named' => 0, 'skipped' => 0, 'failed' => 0])
		);
	}//end testAnUnknownStatusIsReportedAsCounts()
}//end class
