<?php

/**
 * Pipelinq ReminderDispatchJob.
 *
 * 5-minute TimedJob that queries confirmed Bookings whose start falls between
 * `now + 23h` and `now + 24h` and whose `reminderSentAt` is unset, and asks
 * {@see \OCA\Pipelinq\Service\AppointmentEmailService} to send the 24-hour
 * reminder for each one. Per-booking errors are caught and logged so a single
 * bad row never blocks the run (REQ-APT-007 robustness).
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\AppointmentEmailServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\BookingServiceInterface;
use OCA\Pipelinq\Service\AppointmentEmailService;
use OCA\Pipelinq\Util\EntityIdOf;
use OCA\Pipelinq\Util\NullablePayloadToArray;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * ReminderDispatchJob — 5-minute reminder dispatcher.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Measured 13, threshold 13.
 *  A dispatcher is a fan-out point by definition: it reads bookings, resolves
 *  services and resources, renders the message and hands off to email/SMS. Each
 *  collaborator is used exactly once and splitting the job would move the same
 *  edges behind an extra indirection without removing any of them.
 */
class ReminderDispatchJob extends TimedJob {
	use EntityIdOf;
	use NullablePayloadToArray;

	/**
	 * Job interval in seconds (5 minutes).
	 *
	 * @var int
	 */
	public const INTERVAL_SECONDS = 300;

	/**
	 * Default lower bound of the lookahead window in seconds (23 hours).
	 *
	 * @var int
	 */
	public const WINDOW_LOWER_SECONDS = (23 * 3600);

	/**
	 * Default upper bound of the lookahead window in seconds (24 hours).
	 *
	 * @var int
	 */
	public const WINDOW_UPPER_SECONDS = (24 * 3600);

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param IAppConfig $appConfig The app config.
	 * @param ContainerInterface $container The DI container (OR ObjectService + email service).
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		ITimeFactory $time,
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Run the reminder dispatch cycle.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	protected function run(mixed $argument): void {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, BookingServiceInterface::BOOKING_SCHEMA_KEY, '');
		if ($register === '' || $schema === '') {
			$this->logger->debug('ReminderDispatchJob: register/booking schema not configured, skipping');
			return;
		}

		$window = $this->startCycle();
		$counts = ['sent' => 0, 'skipped' => 0, 'failures' => 0];
		foreach ($this->loadDueBookings(register: $register, schema: $schema) as $row) {
			$counts[$this->dispatchOne(row: $row, window: $window)]++;
		}

		$this->logger->info('ReminderDispatchJob: cycle complete', $counts);
	}//end run()

	/**
	 * The window of this cycle, logged as the cycle starts.
	 *
	 * @return ReminderWindow The bookings' start-time window.
	 */
	private function startCycle(): ReminderWindow {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$window = new ReminderWindow(
			startIso: $now->modify('+' . self::WINDOW_LOWER_SECONDS . ' seconds')->format('Y-m-d\TH:i:sP'),
			endIso: $now->modify('+' . self::WINDOW_UPPER_SECONDS . ' seconds')->format('Y-m-d\TH:i:sP')
		);

		$this->logger->info(
			'ReminderDispatchJob: starting cycle',
			['window_start' => $window->startIso, 'window_end' => $window->endIso]
		);
		return $window;
	}//end startCycle()

	/**
	 * Send the reminder of one booking row when it is due.
	 *
	 * @param mixed          $row    One booking row.
	 * @param ReminderWindow $window The cycle's window.
	 *
	 * @return string The outcome: sent, skipped or failures.
	 */
	private function dispatchOne(mixed $row, ReminderWindow $window): string {
		$booking = $this->toArray(object: $row);
		if ($booking === null || $this->isDueForReminder(booking: $booking, windowStartIso: $window->startIso, windowEndIso: $window->endIso) === false) {
			return 'skipped';
		}

		$bookingId = $this->idOf(object: $booking);
		if ($bookingId === '') {
			return 'skipped';
		}

		return $this->sendReminder(bookingId: $bookingId);
	}//end dispatchOne()

	/**
	 * Ask the email service for the reminder; a failure is logged and the cycle continues.
	 *
	 * @param string $bookingId The booking id.
	 *
	 * @return string The outcome: sent or failures.
	 */
	private function sendReminder(string $bookingId): string {
		try {
			if ($this->emailService()->sendReminder(bookingId: $bookingId) === true) {
				return 'sent';
			}

			return 'failures';
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ReminderDispatchJob: per-booking dispatch failed, continuing',
				['booking' => $bookingId, 'exception' => $e->getMessage()]
			);
			return 'failures';
		}
	}//end sendReminder()

	/**
	 * True when the booking is `confirmed`, has no `reminderSentAt`, and starts
	 * inside the [windowStart, windowEnd] window.
	 *
	 * @param array<string, mixed> $booking The booking entity.
	 * @param string $windowStartIso ISO timestamp lower bound.
	 * @param string $windowEndIso ISO timestamp upper bound.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	public function isDueForReminder(array $booking, string $windowStartIso, string $windowEndIso): bool {
		$status = (string)($booking['status'] ?? '');
		if ($status !== 'confirmed') {
			return false;
		}

		$reminderAt = (string)($booking['reminderSentAt'] ?? '');
		if ($reminderAt !== '') {
			return false;
		}

		$startAt = (string)($booking['startAt'] ?? '');
		if ($startAt === '') {
			return false;
		}

		return ($startAt >= $windowStartIso && $startAt <= $windowEndIso);
	}//end isDueForReminder()

	/**
	 * Load candidate bookings (confirmed + reminderSentAt unset) for the cycle.
	 *
	 * OpenRegister's findAll filter DSL is equality-only, so the time window is
	 * applied in {@see self::isDueForReminder}.
	 *
	 * @param string $register Register UUID.
	 * @param string $schema Booking schema UUID.
	 *
	 * @return iterable<int, mixed>
	 */
	private function loadDueBookings(string $register, string $schema): iterable {
		try {
			return $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'status' => 'confirmed',
					],
				]
			) ?? [];
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ReminderDispatchJob: booking lookup failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}
	}//end loadDueBookings()

	/**
	 * Resolve the AppointmentEmailService via the DI container.
	 *
	 * @return AppointmentEmailServiceInterface
	 */
	private function emailService(): AppointmentEmailServiceInterface {
		return $this->container->get(AppointmentEmailService::class);
	}//end emailService()

	/**
	 * Resolve the OpenRegister ObjectService.
	 *
	 * @return object
	 */
	private function getObjectService(): object {
		return $this->objectService;
	}//end getObjectService()
}//end class
