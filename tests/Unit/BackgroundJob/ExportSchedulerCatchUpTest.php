<?php

/**
 * Tests for the minutes ExportSchedulerJob covers on each tick.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\ExportSchedulerJob;
use OCA\Pipelinq\Service\Export\CronExpressionHelper;
use OCA\Pipelinq\Service\Export\ExportJobService;
use OCA\Pipelinq\Service\Export\ExportRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * A tick enqueues a job whose schedule fired in any minute since the
 * previous tick, once, and never for minutes the previous tick already
 * covered; without a previous tick only the current minute counts.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\ExportSchedulerJob
 * @uses   \OCA\Pipelinq\Service\Export\CronExpressionHelper
 *
 * @spec openspec/changes/bi-export-and-data-warehouse-sink/specs.md#REQ-BIE-004-01
 */
class ExportSchedulerCatchUpTest extends TestCase {
	/**
	 * The ticks: previous tick, this tick (UTC), the schedule, and how many runs are enqueued.
	 *
	 * @return array<string, array{0: string|null, 1: string, 2: string, 3: int}>
	 */
	public static function ticks(): array {
		return [
			'minute between two ticks'         => ['2026-11-03 12:03:20', '2026-11-03 12:07:10', '5 * * * *', 1],
			'several minutes between ticks'    => ['2026-11-03 12:03:20', '2026-11-03 12:17:10', '*/5 * * * *', 1],
			'nothing due between ticks'        => ['2026-11-03 12:06:20', '2026-11-03 12:07:10', '5 * * * *', 0],
			'current minute'                   => ['2026-11-03 12:04:50', '2026-11-03 12:05:10', '5 * * * *', 1],
			'minute the previous tick covered' => ['2026-11-03 12:05:05', '2026-11-03 12:05:50', '5 * * * *', 0],
			'next minute not yet'              => ['2026-11-03 12:05:05', '2026-11-03 12:05:50', '6 * * * *', 0],
			'first tick, due now'              => [null, '2026-11-03 12:05:30', '5 * * * *', 1],
			'first tick, due earlier'          => [null, '2026-11-03 12:05:30', '4 * * * *', 0],
			'long gap, daily schedule'         => ['2026-10-30 12:00:00', '2026-11-03 12:07:00', '0 2 * * *', 1],
			'long gap, older than a day only'  => ['2026-10-30 12:00:00', '2026-11-03 12:07:00', '0 12 31 10 *', 0],
			'invalid schedule'                 => ['2026-11-03 12:03:20', '2026-11-03 12:07:10', 'nonsense', 0],
		];
	}//end ticks()

	/**
	 * One tick enqueues as many runs as expected.
	 *
	 * @param string|null $previous The previous tick, or null for none.
	 * @param string      $now      This tick.
	 * @param string      $cron     The job's schedule.
	 * @param int         $runs     The expected number of enqueued runs.
	 *
	 * @return void
	 */
	#[DataProvider('ticks')]
	public function testTickEnqueuesMissedMinutesOnce(?string $previous, string $now, string $cron, int $runs): void {
		$zone = date_default_timezone_get();
		date_default_timezone_set('UTC');
		try {
			$enqueued = $this->tick(previous: $previous, now: $now, jobs: [['id' => 'j1', 'enabled' => true, 'scheduleCron' => $cron]]);
		} finally {
			date_default_timezone_set($zone);
		}

		$this->assertSame(expected: $runs, actual: $enqueued);
	}//end testTickEnqueuesMissedMinutesOnce()

	/**
	 * A disabled job is never enqueued, a missed minute or not.
	 *
	 * @return void
	 */
	public function testDisabledJobIsNotEnqueued(): void {
		$jobs = [['id' => 'j1', 'enabled' => false, 'scheduleCron' => '5 * * * *']];
		$enqueued = $this->tick(previous: '2026-11-03 12:03:20', now: '2026-11-03 12:07:10', jobs: $jobs);

		$this->assertSame(expected: 0, actual: $enqueued);
	}//end testDisabledJobIsNotEnqueued()

	/**
	 * Run one tick and count the enqueued runs.
	 *
	 * @param string|null                      $previous The previous tick, or null for none.
	 * @param string                           $now      This tick.
	 * @param array<int, array<string, mixed>> $jobs     The export jobs.
	 *
	 * @return int
	 */
	private function tick(?string $previous, string $now, array $jobs): int {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime($now . ' UTC'));

		$jobService = $this->createMock(originalClassName: ExportJobService::class);
		$jobService->method('listJobs')->willReturn($jobs);

		$enqueued = 0;
		$runs = $this->createMock(originalClassName: ExportRunService::class);
		$runs->method('createPendingRun')->willReturnCallback(
			static function () use (&$enqueued): array {
				$enqueued++;
				return [];
			}
		);

		$job = new ExportSchedulerJob(
			time: $time,
			jobs: $jobService,
			runs: $runs,
			cron: new CronExpressionHelper(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
		if ($previous !== null) {
			$job->setLastRun((int)strtotime($previous . ' UTC'));
		}

		(new ReflectionMethod($job, 'run'))->invoke($job, null);
		return $enqueued;
	}//end tick()
}//end class
