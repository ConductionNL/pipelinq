<?php

/**
 * Unit tests for AppointmentDepositTimeoutJob — drains pending-deposit
 * bookings whose 15-minute window has elapsed.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-08-deposit-payment/specs/appointment-booking/spec.md#req-apt-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\BackgroundJob\AppointmentDepositTimeoutJob;
use OCA\Pipelinq\Service\AppointmentDepositService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Tests for AppointmentDepositTimeoutJob.
 */
class AppointmentDepositTimeoutJobTest extends TestCase {
	/**
	 * Build a job whose protected run() we invoke directly via reflection.
	 *
	 * @param ObjectService $objectService OR ObjectService mock.
	 * @param AppointmentDepositService $depositService Deposit service mock.
	 *
	 * @return AppointmentDepositTimeoutJob
	 */
	private function buildJob(
		ObjectService $objectService,
		AppointmentDepositService $depositService,
	): AppointmentDepositTimeoutJob {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(time());

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new RuntimeException(sprintf('No binding for %s', $id));
			}
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				if ($key === 'register') {
					return 'pipelinq';
				}

				if ($key === 'booking_schema') {
					return 'booking';
				}

				return $default;
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		return new AppointmentDepositTimeoutJob(
			time: $time,
			container: $container,
			appConfig: $appConfig,
			depositService: $depositService,
			logger: $logger
		);
	}//end buildJob()

	/**
	 * Invoke the protected run() via reflection.
	 *
	 * @param AppointmentDepositTimeoutJob $job The job.
	 *
	 * @return void
	 */
	private function invokeRun(AppointmentDepositTimeoutJob $job): void {
		$method = new ReflectionMethod(objectOrMethod: $job, method: 'run');
		$method->invoke($job, null);
	}//end invokeRun()

	/**
	 * Run() releases pending-deposit bookings whose createdAt has aged
	 * past the 15-minute window and skips fresh ones.
	 *
	 * @return void
	 */
	public function testRunReleasesOnlyExpiredBookings(): void {
		$expired = '2020-01-01T00:00:00+00:00';
		// Long-expired.
		$fresh = (new \DateTimeImmutable('-1 minute'))->format('Y-m-d\TH:i:sP');

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturn(
			[
				['@self' => ['id' => 'b-expired', 'created' => $expired], 'status' => 'pending-deposit'],
				['@self' => ['id' => 'b-fresh',   'created' => $fresh],   'status' => 'pending-deposit'],
			]
		);

		$depositService = $this->createMock(originalClassName: AppointmentDepositService::class);
		$depositService->method('isDepositExpired')->willReturnCallback(
			static function (string $createdAtIso, ?int $nowEpoch = null) use ($expired): bool {
				return ($createdAtIso === $expired);
			}
		);
		$depositService->expects($this->once())
			->method('releaseExpiredDeposit')
			->with($this->equalTo(value: 'b-expired'));

		$job = $this->buildJob(objectService: $objectService, depositService: $depositService);
		$this->invokeRun(job: $job);

	}//end testRunReleasesOnlyExpiredBookings()

	/**
	 * An empty result set is a no-op (no calls).
	 *
	 * @return void
	 */
	public function testRunNoopOnEmptyResult(): void {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturn([]);

		$depositService = $this->createMock(originalClassName: AppointmentDepositService::class);
		$depositService->expects($this->never())->method('releaseExpiredDeposit');

		$job = $this->buildJob(objectService: $objectService, depositService: $depositService);
		$this->invokeRun(job: $job);

	}//end testRunNoopOnEmptyResult()

	/**
	 * Rows without a discoverable id or createdAt are skipped silently.
	 *
	 * @return void
	 */
	public function testRunSkipsRowsWithoutIdOrCreatedAt(): void {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturn(
			[
				// No id field on this row.
				['@self' => ['created' => '2020-01-01T00:00:00+00:00']],
				// No createdAt on this row.
				['@self' => ['id' => 'b-no-created']],
				['@self' => ['id' => 'b-good', 'created' => '2020-01-01T00:00:00+00:00']],
			]
		);

		$depositService = $this->createMock(originalClassName: AppointmentDepositService::class);
		$depositService->method('isDepositExpired')->willReturn(true);
		$depositService->expects($this->once())
			->method('releaseExpiredDeposit')
			->with($this->equalTo(value: 'b-good'));

		$job = $this->buildJob(objectService: $objectService, depositService: $depositService);
		$this->invokeRun(job: $job);

	}//end testRunSkipsRowsWithoutIdOrCreatedAt()

	/**
	 * A throw inside releaseExpiredDeposit is caught and the loop continues.
	 *
	 * @return void
	 */
	public function testRunContinuesAfterReleaseFailure(): void {
		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturn(
			[
				['@self' => ['id' => 'b-1', 'created' => '2020-01-01T00:00:00+00:00']],
				['@self' => ['id' => 'b-2', 'created' => '2020-01-01T00:00:00+00:00']],
			]
		);

		$depositService = $this->createMock(originalClassName: AppointmentDepositService::class);
		$depositService->method('isDepositExpired')->willReturn(true);

		$depositService->expects($this->exactly(count: 2))
			->method('releaseExpiredDeposit')
			->willReturnCallback(
				static function (string $id): void {
					if ($id === 'b-1') {
						throw new RuntimeException('OR down');
					}
				}
			);

		$job = $this->buildJob(objectService: $objectService, depositService: $depositService);
		$this->invokeRun(job: $job);

	}//end testRunContinuesAfterReleaseFailure()
}//end class
