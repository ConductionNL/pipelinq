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
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AppointmentEmailService;
use OCA\Pipelinq\Service\BookingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

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
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, BookingService::BOOKING_SCHEMA_KEY, '');
		if ($register === '' || $schema === '') {
			$this->logger->debug('ReminderDispatchJob: register/booking schema not configured, skipping');
			return;
		}

		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$windowStartIso = $now->modify('+' . self::WINDOW_LOWER_SECONDS . ' seconds')->format('Y-m-d\TH:i:sP');
		$windowEndIso = $now->modify('+' . self::WINDOW_UPPER_SECONDS . ' seconds')->format('Y-m-d\TH:i:sP');

		$this->logger->info(
			'ReminderDispatchJob: starting cycle',
			['window_start' => $windowStartIso, 'window_end' => $windowEndIso]
		);

		$rows = $this->loadDueBookings(register: $register, schema: $schema);

		$sent = 0;
		$skipped = 0;
		$failures = 0;
		foreach ($rows as $row) {
			$booking = $this->toArray(object: $row);
			if ($booking === null) {
				++$skipped;
				continue;
			}

			if ($this->isDueForReminder(booking: $booking, windowStartIso: $windowStartIso, windowEndIso: $windowEndIso) === false) {
				++$skipped;
				continue;
			}

			$bookingId = $this->idOf(object: $booking);
			if ($bookingId === '') {
				++$skipped;
				continue;
			}

			try {
				$emailService = $this->emailService();
				$reminderSent = $emailService->sendReminder(bookingId: $bookingId);
				if ($reminderSent === true) {
					++$sent;
					continue;
				}

				++$failures;
			} catch (\Throwable $e) {
				++$failures;
				$this->logger->warning(
					'ReminderDispatchJob: per-booking dispatch failed, continuing',
					['booking' => $bookingId, 'exception' => $e->getMessage()]
				);
				continue;
			}
		}//end foreach

		$this->logger->info(
			'ReminderDispatchJob: cycle complete',
			['sent' => $sent, 'skipped' => $skipped, 'failures' => $failures]
		);
	}//end run()

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
	 * @return AppointmentEmailService
	 */
	private function emailService(): AppointmentEmailService {
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

	/**
	 * Pull the canonical id off an OR entity or array.
	 *
	 * @param array<string, mixed>|object $object Object or array.
	 *
	 * @return string
	 */
	private function idOf(array|object $object): string {
		$arr = $this->toArray(object: $object);
		if ($arr === null) {
			return '';
		}

		if (isset($arr['@self']) === true && is_array($arr['@self']) === true) {
			$self = $arr['@self'];
			if (isset($self['id']) === true) {
				return (string)$self['id'];
			}

			if (isset($self['uuid']) === true) {
				return (string)$self['uuid'];
			}
		}

		if (isset($arr['id']) === true) {
			return (string)$arr['id'];
		}

		if (isset($arr['uuid']) === true) {
			return (string)$arr['uuid'];
		}

		return '';
	}//end idOf()

	/**
	 * Normalise an OR entity (or array) to a plain array.
	 *
	 * @param mixed $object Entity, array, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function toArray(mixed $object): ?array {
		if ($object === null) {
			return null;
		}

		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true) {
			if (method_exists($object, 'jsonSerialize') === true) {
				$serialised = $object->jsonSerialize();
				if (is_array($serialised) === true) {
					return $serialised;
				}
			}

			if (method_exists($object, 'toArray') === true) {
				$arr = $object->toArray();
				if (is_array($arr) === true) {
					return $arr;
				}
			}

			return (array)$object;
		}

		return null;
	}//end toArray()
}//end class
