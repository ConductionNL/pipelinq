<?php

/**
 * Unit tests for RemoveBrpMonitorState.
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
 *
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-pipelinq-does-not-monitor-the-brp-connection
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\RemoveBrpMonitorState;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The BRP monitor jobs are unregistered and their reports cleared.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class RemoveBrpMonitorStateTest extends TestCase {

	/**
	 * Both jobs are unregistered and both reports are cleared from pipelinq's app config.
	 *
	 * @return void
	 */
	public function testJobsAreUnregisteredAndReportsCleared(): void {
		$removed = [];
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(static function (string $job) use (&$removed): void {
			$removed[] = $job;
		});
		$deleted = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('deleteKey')->willReturnCallback(static function (string $app, string $key) use (&$deleted): void {
			$deleted[] = $app . ':' . $key;
		});
		$infos = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $line) use (&$infos): void {
			$infos[] = $line;
		});
		$output->expects(self::never())->method('warning');

		$step = new RemoveBrpMonitorState(jobList: $jobList, appConfig: $appConfig, logger: $this->createMock(LoggerInterface::class));
		$step->run(output: $output);

		self::assertSame(['OCA\Pipelinq\BackgroundJob\BrpHealthCheckJob', 'OCA\Pipelinq\BackgroundJob\BrpMonitorJob'], $removed);
		self::assertSame(['pipelinq:brp.monitor_report', 'pipelinq:brp.cert_health'], $deleted);
		self::assertSame(
			[
				'Unregistered BRP monitor job: OCA\Pipelinq\BackgroundJob\BrpHealthCheckJob',
				'Unregistered BRP monitor job: OCA\Pipelinq\BackgroundJob\BrpMonitorJob',
				'Cleared BRP monitor report: brp.monitor_report',
				'Cleared BRP monitor report: brp.cert_health',
			],
			$infos
		);
		self::assertSame('Remove the BRP monitor jobs and reports (the monitor moved to integriq)', $step->getName());
	}//end testJobsAreUnregisteredAndReportsCleared()

	/**
	 * A part that cannot be removed is warned about and logged, and the others still run.
	 *
	 * @return void
	 */
	public function testAFailedPartDoesNotStopTheRest(): void {
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(static function (string $job): void {
			if (str_ends_with($job, 'BrpHealthCheckJob') === true) {
				throw new RuntimeException('jobs table locked');
			}
		});
		$deleted = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('deleteKey')->willReturnCallback(static function (string $app, string $key) use (&$deleted): void {
			if ($key === 'brp.monitor_report') {
				throw new RuntimeException('config read-only');
			}

			$deleted[] = $key;
		});
		$warnings = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(static function (string $line) use (&$warnings): void {
			$warnings[] = $line;
		});
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context) use (&$logged): void {
			$logged[] = $context;
		});

		(new RemoveBrpMonitorState(jobList: $jobList, appConfig: $appConfig, logger: $logger))->run(output: $output);

		self::assertSame(['brp.cert_health'], $deleted);
		self::assertSame(
			[
				'Failed to remove OCA\Pipelinq\BackgroundJob\BrpHealthCheckJob: jobs table locked',
				'Failed to remove brp.monitor_report: config read-only',
			],
			$warnings
		);
		self::assertSame(
			[
				['subject' => 'OCA\Pipelinq\BackgroundJob\BrpHealthCheckJob', 'exception' => 'jobs table locked'],
				['subject' => 'brp.monitor_report', 'exception' => 'config read-only'],
			],
			$logged
		);
	}//end testAFailedPartDoesNotStopTheRest()
}//end class
