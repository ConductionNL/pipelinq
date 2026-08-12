<?php

/**
 * Unit tests for ReminderDispatchJob.
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
 *
 * @spec openspec/changes/appointment-booking-07-email-confirmation-reminder/specs/appointment-booking/spec.md#req-apt-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\ReminderDispatchJob;
use OCA\Pipelinq\Service\AppointmentEmailService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ReminderDispatchJob.
 *
 * The job's `run()` is invoked via reflection (it is `protected` per
 * `OCP\BackgroundJob\TimedJob`), the OR `ObjectService` is a `stdClass` mock
 * exposing `findAll`, and `AppointmentEmailService::sendReminder` is the
 * dispatch seam under test.
 *
 * @SuppressWarnings(PHPMD.LongClassName)
 */
class ReminderDispatchJobTest extends TestCase {

	/**
	 * Mock time factory.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory $timeFactory;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->timeFactory->method('getTime')->willReturn(time());
	}//end setUp()

	/**
	 * Build a job under test.
	 *
	 * @return ReminderDispatchJob
	 */
	private function buildJob(): ReminderDispatchJob {
		return new ReminderDispatchJob(
			$this->timeFactory,
			$this->appConfig,
			$this->container,
			$this->logger,
		);
	}//end buildJob()

	/**
	 * Invoke the protected `run()` via reflection.
	 *
	 * @param ReminderDispatchJob $job The job instance.
	 *
	 * @return void
	 */
	private function runJob(ReminderDispatchJob $job): void {
		$ref = new \ReflectionMethod($job, 'run');
		$ref->setAccessible(true);
		$ref->invoke($job, null);
	}//end runJob()

	/**
	 * Skips when the register is not configured.
	 *
	 * @return void
	 */
	public function testSkipsWhenRegisterNotConfigured(): void {
		$this->appConfig->method('getValueString')->willReturnMap(
			[
				['pipelinq', 'register', '', ''],
				['pipelinq', 'booking_schema', '', ''],
			]
		);

		// The container must NOT be consulted at all when we skip.
		$this->container->expects($this->never())->method('get');

		$this->runJob(job: $this->buildJob());
	}//end testSkipsWhenRegisterNotConfigured()

	/**
	 * Skips when the booking schema is not configured.
	 *
	 * @return void
	 */
	public function testSkipsWhenBookingSchemaNotConfigured(): void {
		$this->appConfig->method('getValueString')->willReturnMap(
			[
				['pipelinq', 'register', '', 'register-uuid'],
				['pipelinq', 'booking_schema', '', ''],
			]
		);

		$this->container->expects($this->never())->method('get');

		$this->runJob(job: $this->buildJob());
	}//end testSkipsWhenBookingSchemaNotConfigured()

	/**
	 * Dispatches reminders for bookings inside the 23-24h window.
	 *
	 * @return void
	 */
	public function testDispatchesRemindersForDueBookings(): void {
		$this->appConfig->method('getValueString')->willReturnMap(
			[
				['pipelinq', 'register', '', 'register-uuid'],
				['pipelinq', 'booking_schema', '', 'booking-schema'],
			]
		);

		// Booking due in ~23.5h: should trigger.
		$dueIso = (new \DateTimeImmutable('+23 hours 30 minutes', new \DateTimeZone('UTC')))
			->format('Y-m-d\TH:i:sP');
		$dueBooking = [
			'id' => 'booking-due',
			'status' => 'confirmed',
			'reminderSentAt' => '',
			'startAt' => $dueIso,
		];

		// Out-of-window booking (~10h away): should skip.
		$earlyIso = (new \DateTimeImmutable('+10 hours', new \DateTimeZone('UTC')))
			->format('Y-m-d\TH:i:sP');
		$earlyBooking = [
			'id' => 'booking-early',
			'status' => 'confirmed',
			'reminderSentAt' => '',
			'startAt' => $earlyIso,
		];

		// Already-reminded booking inside the window: should skip.
		$remindedBooking = [
			'id' => 'booking-reminded',
			'status' => 'confirmed',
			'reminderSentAt' => '2026-01-01T00:00:00+00:00',
			'startAt' => $dueIso,
		];

		// Build object-service mock that exposes findAll.
		$objectService = new class([$dueBooking, $earlyBooking, $remindedBooking]) {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $rows;

			/**
			 * @param array<int, array<string, mixed>> $rows Seeded rows.
			 */
			public function __construct(array $rows) {
				$this->rows = $rows;
			}//end __construct()

			/**
			 * Mock findAll that returns the seeded rows verbatim.
			 *
			 * @param array<string, mixed> $config Filter config (ignored in tests).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config): array {
				return $this->rows;
			}//end findAll()
		};

		$emailService = $this->createMock(AppointmentEmailService::class);
		$emailService->expects($this->once())
			->method('sendReminder')
			->with($this->equalTo('booking-due'))
			->willReturn(true);

		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $emailService): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === AppointmentEmailService::class) {
					return $emailService;
				}

				throw new RuntimeException('unexpected container lookup: ' . $id);
			}
		);

		$this->runJob(job: $this->buildJob());
	}//end testDispatchesRemindersForDueBookings()

	/**
	 * Continues on per-booking dispatch errors.
	 *
	 * @return void
	 */
	public function testContinuesOnPerBookingErrors(): void {
		$this->appConfig->method('getValueString')->willReturnMap(
			[
				['pipelinq', 'register', '', 'register-uuid'],
				['pipelinq', 'booking_schema', '', 'booking-schema'],
			]
		);

		$dueIso = (new \DateTimeImmutable('+23 hours 30 minutes', new \DateTimeZone('UTC')))
			->format('Y-m-d\TH:i:sP');

		$bookings = [
			['id' => 'a', 'status' => 'confirmed', 'reminderSentAt' => '', 'startAt' => $dueIso],
			['id' => 'b', 'status' => 'confirmed', 'reminderSentAt' => '', 'startAt' => $dueIso],
			['id' => 'c', 'status' => 'confirmed', 'reminderSentAt' => '', 'startAt' => $dueIso],
		];

		$objectService = new class($bookings) {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $rows;

			/**
			 * @param array<int, array<string, mixed>> $rows Seeded rows.
			 */
			public function __construct(array $rows) {
				$this->rows = $rows;
			}//end __construct()

			/**
			 * Mock findAll.
			 *
			 * @param array<string, mixed> $config Ignored.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config): array {
				return $this->rows;
			}//end findAll()
		};

		$emailService = $this->createMock(AppointmentEmailService::class);
		$emailService->expects($this->exactly(3))
			->method('sendReminder')
			->willReturnCallback(
				function (string $bookingId): bool {
					if ($bookingId === 'b') {
						throw new RuntimeException('downstream SMTP failed');
					}

					return true;
				}
			);

		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $emailService): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === AppointmentEmailService::class) {
					return $emailService;
				}

				throw new RuntimeException('unexpected container lookup: ' . $id);
			}
		);

		// The per-booking error should be logged at warning, not bubble.
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->runJob(job: $this->buildJob());
	}//end testContinuesOnPerBookingErrors()

	/**
	 * `isDueForReminder` correctly filters by status / reminderSentAt / window.
	 *
	 * @return void
	 */
	public function testIsDueForReminderFiltersAllAxes(): void {
		$job = $this->buildJob();
		$start = '2026-05-25T14:00:00+00:00';
		$lower = '2026-05-24T14:00:00+00:00';
		$upper = '2026-05-24T15:00:00+00:00';

		// Confirmed + no reminder + start in [lower, upper] → true.
		$this->assertTrue(
			$job->isDueForReminder(
				booking: ['status' => 'confirmed', 'reminderSentAt' => '', 'startAt' => $lower],
				windowStartIso: $lower,
				windowEndIso: $upper
			)
		);

		// Wrong status → false.
		$this->assertFalse(
			$job->isDueForReminder(
				booking: ['status' => 'pending-deposit', 'reminderSentAt' => '', 'startAt' => $lower],
				windowStartIso: $lower,
				windowEndIso: $upper
			)
		);

		// Already reminded → false.
		$this->assertFalse(
			$job->isDueForReminder(
				booking: ['status' => 'confirmed', 'reminderSentAt' => $start, 'startAt' => $lower],
				windowStartIso: $lower,
				windowEndIso: $upper
			)
		);

		// Out-of-window → false.
		$this->assertFalse(
			$job->isDueForReminder(
				booking: ['status' => 'confirmed', 'reminderSentAt' => '', 'startAt' => '2026-05-23T00:00:00+00:00'],
				windowStartIso: $lower,
				windowEndIso: $upper
			)
		);

		// Missing startAt → false.
		$this->assertFalse(
			$job->isDueForReminder(
				booking: ['status' => 'confirmed', 'reminderSentAt' => '', 'startAt' => ''],
				windowStartIso: $lower,
				windowEndIso: $upper
			)
		);
	}//end testIsDueForReminderFiltersAllAxes()

	/**
	 * Logs a warning when the OR query throws (graceful degradation).
	 *
	 * @return void
	 */
	public function testLogsWarningWhenBookingQueryFails(): void {
		$this->appConfig->method('getValueString')->willReturnMap(
			[
				['pipelinq', 'register', '', 'register-uuid'],
				['pipelinq', 'booking_schema', '', 'booking-schema'],
			]
		);

		$objectService = new class {
			/**
			 * Mock findAll that always throws.
			 *
			 * @param array<string, mixed> $config Ignored.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				throw new RuntimeException('OR unavailable');
			}//end findAll()
		};

		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($objectService): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				throw new RuntimeException('unexpected lookup');
			}
		);

		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->runJob(job: $this->buildJob());
	}//end testLogsWarningWhenBookingQueryFails()
}//end class
