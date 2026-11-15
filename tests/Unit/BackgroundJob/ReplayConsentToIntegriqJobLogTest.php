<?php

/**
 * Unit tests for ReplayConsentToIntegriqJob: it replays pending consent records and warns only when some are still refused.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\ReplayConsentToIntegriqJob;
use OCA\Pipelinq\Kernel\Contracts\ConsentServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the refusal warning of ReplayConsentToIntegriqJob.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\ReplayConsentToIntegriqJob
 */
class ReplayConsentToIntegriqJobLogTest extends TestCase {

	/**
	 * Run the job once with the given replay counts.
	 *
	 * @param array<string, int> $counts What replayPending() returns.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	private function runWith(array $counts, LoggerInterface $logger): void {
		$consent = $this->createMock(ConsentServiceInterface::class);
		$consent->expects($this->once())->method('replayPending')->willReturn($counts);

		$job = new ReplayConsentToIntegriqJob($this->createMock(ITimeFactory::class), $consent, $logger);
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}//end runWith()

	/**
	 * Records integriq still refuses are warned about, with the counts.
	 *
	 * @return void
	 */
	public function testStillRefusedRecordsAreWarnedAboutWithTheCounts(): void {
		$counts = ['replayed' => 3, 'failed' => 2];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('ReplayConsentToIntegriqJob: integriq still refuses some consent records', $counts);

		$this->runWith($counts, $logger);
	}//end testStillRefusedRecordsAreWarnedAboutWithTheCounts()

	/**
	 * A replay without refusals logs nothing.
	 *
	 * @return void
	 */
	public function testAReplayWithoutRefusalsLogsNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->runWith(['replayed' => 5, 'failed' => 0], $logger);
	}//end testAReplayWithoutRefusalsLogsNothing()
}//end class
