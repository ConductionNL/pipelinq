<?php

/**
 * Unit tests for RemoveRetiredAvgJobs.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\RemoveRetiredAvgJobs;
use OCP\BackgroundJob\IJobList;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every retired AVG job is unregistered, and one that cannot be does not stop the rest.
 *
 * @covers \OCA\Pipelinq\Repair\RemoveRetiredAvgJobs
 */
class RemoveRetiredAvgJobsTest extends TestCase {

	/**
	 * The four retired jobs, in order.
	 *
	 * @var array<int, string>
	 */
	private const RETIRED = [
		'OCA\Pipelinq\BackgroundJob\AvgDeadlineTrackerJob',
		'OCA\Pipelinq\BackgroundJob\AvgDpiaPatternDetectionJob',
		'OCA\Pipelinq\BackgroundJob\AvgRetentionJob',
		'OCA\Pipelinq\BackgroundJob\AvgCollectEvidenceJob',
	];

	/**
	 * Each retired job is removed and reported.
	 *
	 * @return void
	 */
	public function testEveryRetiredJobIsUnregistered(): void {
		$removed = [];
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(static function (string $job) use (&$removed): void {
			$removed[] = $job;
		});
		$infos = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $line) use (&$infos): void {
			$infos[] = $line;
		});
		$output->expects(self::never())->method('warning');

		(new RemoveRetiredAvgJobs(jobList: $jobList, logger: $this->createMock(LoggerInterface::class)))->run(output: $output);

		self::assertSame(self::RETIRED, $removed);
		self::assertSame(array_map(static fn (string $job): string => 'Unregistered retired AVG job: ' . $job, self::RETIRED), $infos);
	}//end testEveryRetiredJobIsUnregistered()

	/**
	 * A job that cannot be removed is warned about and logged, and the others are still removed.
	 *
	 * @return void
	 */
	public function testAFailedRemovalDoesNotStopTheRest(): void {
		$removed = [];
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('remove')->willReturnCallback(static function (string $job) use (&$removed): void {
			if ($job === self::RETIRED[1]) {
				throw new RuntimeException('jobs table locked');
			}

			$removed[] = $job;
		});
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with('Failed to unregister ' . self::RETIRED[1] . ': jobs table locked');
		$output->expects(self::exactly(3))->method('info');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Pipelinq: failed to unregister retired AVG job',
			['job' => self::RETIRED[1], 'exception' => 'jobs table locked']
		);

		(new RemoveRetiredAvgJobs(jobList: $jobList, logger: $logger))->run(output: $output);

		self::assertSame([self::RETIRED[0], self::RETIRED[2], self::RETIRED[3]], $removed);
	}//end testAFailedRemovalDoesNotStopTheRest()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepIsNamed(): void {
		$step = new RemoveRetiredAvgJobs(jobList: $this->createMock(IJobList::class), logger: $this->createMock(LoggerInterface::class));
		self::assertSame('Unregister retired AVG/DSAR background jobs from oc_jobs (ADR-047 Phase-3)', $step->getName());
	}//end testTheStepIsNamed()
}//end class
