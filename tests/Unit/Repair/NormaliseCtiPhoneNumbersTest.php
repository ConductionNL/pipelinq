<?php

/**
 * Unit tests for NormaliseCtiPhoneNumbers.
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

use OCA\Pipelinq\Repair\NormaliseCtiPhoneNumbers;
use OCA\Pipelinq\Service\CtiContactMatcher;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The stored phone numbers are normalised only where OpenRegister is installed, and a failure is reported.
 *
 * @covers \OCA\Pipelinq\Repair\NormaliseCtiPhoneNumbers
 */
class NormaliseCtiPhoneNumbersTest extends TestCase {

	/**
	 * Build the step.
	 *
	 * @param array<int, string>   $apps    The installed apps.
	 * @param CtiContactMatcher    $matcher The matcher.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return NormaliseCtiPhoneNumbers The step.
	 */
	private function step(array $apps, CtiContactMatcher $matcher, ?LoggerInterface $logger = null): NormaliseCtiPhoneNumbers {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($apps);

		return new NormaliseCtiPhoneNumbers(
			appManager: $appManager,
			contactMatcher: $matcher,
			logger: ($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end step()

	/**
	 * Without OpenRegister nothing is normalised and the skip is said.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItIsSkipped(): void {
		$matcher = $this->createMock(CtiContactMatcher::class);
		$matcher->expects(self::never())->method('normaliseStoredPhoneNumbers');
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with('OpenRegister not installed — skipping CTI phone normalisation.');

		$this->step(apps: ['pipelinq'], matcher: $matcher)->run(output: $output);
	}//end testWithoutOpenRegisterItIsSkipped()

	/**
	 * The counts are reported and logged.
	 *
	 * @return void
	 */
	public function testTheCountsAreReportedAndLogged(): void {
		$matcher = $this->createMock(CtiContactMatcher::class);
		$matcher->expects(self::once())->method('normaliseStoredPhoneNumbers')->willReturn(['updated' => 12, 'skipped' => 3]);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with('CTI phone normalisation: 12 updated, 3 skipped.');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('info')->with('CTI phone normalisation completed', ['updated' => 12, 'skipped' => 3]);

		$this->step(apps: ['pipelinq', 'openregister'], matcher: $matcher, logger: $logger)->run(output: $output);
	}//end testTheCountsAreReportedAndLogged()

	/**
	 * A failing normalisation is warned about and logged, not thrown.
	 *
	 * @return void
	 */
	public function testAFailureIsReportedNotThrown(): void {
		$matcher = $this->createMock(CtiContactMatcher::class);
		$matcher->method('normaliseStoredPhoneNumbers')->willThrowException(new RuntimeException('object store offline'));
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with('CTI phone normalisation failed: object store offline');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('CTI phone normalisation failed', ['exception' => 'object store offline']);

		$this->step(apps: ['openregister'], matcher: $matcher, logger: $logger)->run(output: $output);
	}//end testAFailureIsReportedNotThrown()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepIsNamed(): void {
		self::assertSame(
			'Normalise contact and client phone numbers to E.164 for CTI matching',
			$this->step(apps: [], matcher: $this->createMock(CtiContactMatcher::class))->getName()
		);
	}//end testTheStepIsNamed()
}//end class
