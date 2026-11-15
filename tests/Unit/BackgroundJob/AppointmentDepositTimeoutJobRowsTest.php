<?php

/**
 * Unit tests for AppointmentDepositTimeoutJob's candidate rows: a query that answers with something else than a list, and a candidate that is not a row.
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

use OCA\Pipelinq\BackgroundJob\AppointmentDepositTimeoutJob;
use OCA\Pipelinq\Kernel\Contracts\AppointmentDepositServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the candidate rows of AppointmentDepositTimeoutJob.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\AppointmentDepositTimeoutJob
 * @uses   \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses   \OCA\Pipelinq\Util\ObjectToPlainArray
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 * @uses   \OCA\Pipelinq\Util\RowIdOf
 * @uses   \OCA\Pipelinq\Util\RowIdentity
 */
class AppointmentDepositTimeoutJobRowsTest extends TestCase {

	/**
	 * Run the job once over an object service returning $answer.
	 *
	 * @param mixed $answer What findAll() returns.
	 * @param AppointmentDepositServiceInterface $deposits The deposit service.
	 *
	 * @return void
	 */
	private function runWith(mixed $answer, AppointmentDepositServiceInterface $deposits): void {
		$objectService = new class($answer) {
			/**
			 * @param mixed $answer The answer.
			 */
			public function __construct(
				private mixed $answer,
			) {
			}

			/**
			 * The seeded answer.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $config = []): mixed {
				return $this->answer;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => 'pipelinq', 'booking_schema' => 'booking'][$key] ?? $default
		);

		$job = new AppointmentDepositTimeoutJob($this->createMock(ITimeFactory::class), $container, $config, $deposits, $this->createMock(LoggerInterface::class));
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}//end runWith()

	/**
	 * A query that answers with something else than a list releases nothing.
	 *
	 * @return void
	 */
	public function testAQueryThatIsNotAListReleasesNothing(): void {
		$deposits = $this->createMock(AppointmentDepositServiceInterface::class);
		$deposits->expects($this->never())->method('isDepositExpired');
		$deposits->expects($this->never())->method('releaseExpiredDeposit');

		$this->runWith('not a list', $deposits);
	}//end testAQueryThatIsNotAListReleasesNothing()

	/**
	 * A candidate that is not a row is skipped and the expired row next to it
	 * is still released.
	 *
	 * @return void
	 */
	public function testACandidateThatIsNotARowIsSkipped(): void {
		$deposits = $this->createMock(AppointmentDepositServiceInterface::class);
		$deposits->method('isDepositExpired')->willReturn(true);
		$deposits->expects($this->once())->method('releaseExpiredDeposit')->with('booking-1');

		$this->runWith([42, ['id' => 'booking-1', 'createdAt' => '2026-01-01T00:00:00Z']], $deposits);
	}//end testACandidateThatIsNotARowIsSkipped()
}//end class
