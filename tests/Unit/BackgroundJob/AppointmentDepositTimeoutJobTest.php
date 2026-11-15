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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
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
	 * @param ObjectServiceInterface $objectService OR ObjectService mock.
	 * @param AppointmentDepositService $depositService Deposit service mock.
	 *
	 * @return AppointmentDepositTimeoutJob
	 */
	private function buildJob(
		ObjectServiceInterface $objectService,
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
					return 'appointmentBooking';
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

		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
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
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
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
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
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
		$objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
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

	/**
	 * Golden: the creation moment is `@self.created`, else `createdAt`, else the first status change.
	 *
	 * @return void
	 */
	public function testCreationMomentIsReadInThisOrder(): void {
		$job = $this->buildJob(
			$this->createMock(originalClassName: ObjectServiceInterface::class),
			$this->createMock(originalClassName: AppointmentDepositService::class)
		);
		$createdAt = new ReflectionMethod(objectOrMethod: $job, method: 'createdAt');
		$cases = [
			[['@self' => ['created' => 'T-self'], 'createdAt' => 'T-row', 'statusHistory' => [['changedAt' => 'T-hist']]], 'T-self'],
			[['@self' => ['id' => 'x'], 'createdAt' => 'T-row'], 'T-row'],
			[['@self' => 'not-a-map', 'statusHistory' => [['changedAt' => 'T-hist'], ['changedAt' => 'later']]], 'T-hist'],
			[['statusHistory' => [['status' => 'new']]], ''],
			[['statusHistory' => ['not-a-map']], ''],
			[['statusHistory' => 'none'], ''],
			[['createdAt' => 12], '12'],
			[[], ''],
		];
		foreach ($cases as [$row, $expected]) {
			$this->assertSame($expected, $createdAt->invoke($job, $row), (string)json_encode($row));
		}
	}//end testCreationMomentIsReadInThisOrder()

	/**
	 * Golden: the pending-deposit query, and every way it reads nothing.
	 *
	 * @return void
	 */
	public function testPendingDepositsAreReadExactly(): void {
		$queries = [];
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use (&$queries): array {
				$queries[] = $config;
				return [['id' => 'b-1']];
			}
		);
		$job = $this->buildJob($objects, $this->createMock(originalClassName: AppointmentDepositService::class));
		$load = new ReflectionMethod(objectOrMethod: $job, method: 'loadPendingDeposits');

		$this->assertSame([['id' => 'b-1']], $load->invoke($job));
		$this->assertSame(
			[['register' => 'pipelinq', 'schema' => 'appointmentBooking', 'filters' => ['status' => 'pending-deposit'], 'limit' => 50]],
			$queries
		);

		$logs = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['debug', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use ($level, &$logs): void {
					$logs[] = [$level, $message, array_keys($context)];
				}
			);
		}

		$failing = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$failing->method('findAll')->willThrowException(new RuntimeException('query failed'));
		foreach ([['pipelinq', $failing], ['', $failing], ['pipelinq', null]] as [$register, $service]) {
			$container = $this->createMock(originalClassName: ContainerInterface::class);
			if ($service === null) {
				$container->method('get')->willThrowException(new RuntimeException('no openregister'));
			} else {
				$container->method('get')->willReturn($service);
			}

			$appConfig = $this->createMock(originalClassName: IAppConfig::class);
			$appConfig->method('getValueString')->willReturnCallback(
				static fn (string $app, string $key, string $default = ''): string => (['register' => $register, 'booking_schema' => 'appointmentBooking'][$key] ?? $default)
			);
			$quiet = new AppointmentDepositTimeoutJob(
				time: $this->createMock(originalClassName: ITimeFactory::class),
				container: $container,
				appConfig: $appConfig,
				depositService: $this->createMock(originalClassName: AppointmentDepositService::class),
				logger: $logger
			);
			$this->assertSame([], (new ReflectionMethod(objectOrMethod: $quiet, method: 'loadPendingDeposits'))->invoke($quiet));
		}

		$this->assertSame(
			[
				['warning', 'AppointmentDepositTimeoutJob: pending-deposit query failed', ['exception']],
				['debug', 'AppointmentDepositTimeoutJob: no register/schema configured, skipping', []],
				['debug', 'AppointmentDepositTimeoutJob: OR ObjectService unavailable, skipping', []],
			],
			$logs
		);
	}//end testPendingDepositsAreReadExactly()
}//end class
