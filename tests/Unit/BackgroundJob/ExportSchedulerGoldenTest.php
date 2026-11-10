<?php

/**
 * Pins every call the export scheduler makes for each job shape.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * One tick over a mixed job list, and a tick whose job list fails.
 */
class ExportSchedulerGoldenTest extends TestCase {

	/**
	 * Runs one tick at a fixed moment and returns the calls.
	 *
	 * @param array<int, array<string, mixed>>|null $jobs The job list, or null when listing fails.
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function tick(?array $jobs): array {
		$calls = [];
		$zone  = date_default_timezone_get();
		date_default_timezone_set('UTC');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((int)strtotime('2026-11-03 12:05:10 UTC'));
		$list = $this->createMock(ExportJobService::class);
		if ($jobs === null) {
			$list->method('listJobs')->willThrowException(new RuntimeException('register down'));
		} else {
			$list->method('listJobs')->willReturn($jobs);
		}

		$runs = $this->createMock(ExportRunService::class);
		$runs->method('lastSucceededRun')->willReturnCallback(
			function (string $jobId) use (&$calls): ?array {
				$calls[] = ['lastSucceededRun', $jobId];
				return ['j-inc' => ['watermarkTo' => '2026-11-02T00:00:00Z'], 'j-inc-null' => ['watermarkTo' => null], 'j-inc-num' => ['watermarkTo' => 17]][$jobId] ?? null;
			}
		);
		$runs->method('createPendingRun')->willReturnCallback(
			function (array $job, ?string $watermarkFrom) use (&$calls): array {
				$calls[] = ['createPendingRun', $job['id'] ?? $job['uuid'] ?? '?', $watermarkFrom];
				if (($job['id'] ?? '') === 'j-broken') {
					throw new RuntimeException('write failed');
				}

				return [];
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'error', 'warning', 'debug'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use (&$calls, $level): void {
					$calls[] = [$level, $message, $context];
				}
			);
		}

		try {
			$job = new ExportSchedulerJob(time: $time, jobs: $list, runs: $runs, cron: new CronExpressionHelper(), logger: $logger);
			$job->setLastRun((int)strtotime('2026-11-03 12:03:20 UTC'));
			(new ReflectionMethod($job, 'run'))->invoke($job, null);
		} finally {
			date_default_timezone_set($zone);
		}

		return $calls;
	}//end tick()

	/**
	 * A mixed job list enqueues exactly the due enabled jobs, in order.
	 *
	 * @return void
	 */
	public function testMixedJobs(): void {
		$due = '5 * * * *';
		$calls = $this->tick(
			[
				['id' => 'j-off', 'enabled' => false, 'scheduleCron' => $due],
				['id' => 'j-truthy', 'enabled' => 1, 'scheduleCron' => $due],
				['id' => 'j-later', 'enabled' => true, 'scheduleCron' => '30 * * * *'],
				['id' => 'j-full', 'enabled' => true, 'scheduleCron' => $due],
				['id' => 'j-inc', 'enabled' => true, 'mode' => 'incremental', 'scheduleCron' => $due],
				['id' => 'j-inc-null', 'enabled' => true, 'mode' => 'incremental', 'scheduleCron' => $due],
				['id' => 'j-inc-num', 'enabled' => true, 'mode' => 'incremental', 'scheduleCron' => $due],
				['uuid' => 'j-inc-new', 'enabled' => true, 'mode' => 'incremental', 'scheduleCron' => $due],
				['enabled' => true, 'mode' => 'incremental', 'scheduleCron' => $due],
				['id' => 'j-broken', 'enabled' => true, 'scheduleCron' => $due],
				['id' => 'j-nocron', 'enabled' => true],
			]
		);
		$this->assertSame(
			[
				['createPendingRun', 'j-full', null],
				['lastSucceededRun', 'j-inc'],
				['createPendingRun', 'j-inc', '2026-11-02T00:00:00Z'],
				['lastSucceededRun', 'j-inc-null'],
				['createPendingRun', 'j-inc-null', null],
				['lastSucceededRun', 'j-inc-num'],
				['createPendingRun', 'j-inc-num', '17'],
				['lastSucceededRun', 'j-inc-new'],
				['createPendingRun', 'j-inc-new', null],
				['createPendingRun', '?', null],
				['createPendingRun', 'j-broken', null],
				['error', 'ExportSchedulerJob: failed to enqueue run', ['jobId' => 'j-broken', 'error' => 'write failed']],
				['info', 'ExportSchedulerJob: enqueued export runs', ['count' => 6]],
			],
			$calls
		);
	}//end testMixedJobs()

	/**
	 * A failing job list logs once and enqueues nothing; nothing due logs nothing.
	 *
	 * @return void
	 */
	public function testListFailureAndQuietTick(): void {
		$this->assertSame([['error', 'ExportSchedulerJob: failed to list jobs', ['error' => 'register down']]], $this->tick(null));
		$this->assertSame([], $this->tick([['id' => 'j-later', 'enabled' => true, 'scheduleCron' => '30 * * * *']]));
	}//end testListFailureAndQuietTick()
}//end class
