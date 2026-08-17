<?php

/**
 * Pipelinq AppointmentDepositTimeoutJob.
 *
 * Background job that releases pending-deposit bookings whose 15-minute
 * payment window has elapsed without confirmation (REQ-APT-010 S2).
 * Runs every 5 minutes; queries OpenRegister for bookings with
 * `status = pending-deposit` and a creation timestamp older than the
 * deposit-timeout window, then routes each through
 * {@see AppointmentDepositService::releaseExpiredDeposit} so the slot
 * is freed and the booking transitions to `cancelled-by-business`.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\AppointmentDepositService;
use OCA\Pipelinq\Service\BookingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * AppointmentDepositTimeoutJob — releases timed-out pending-deposit holds.
 *
 * Runs every 5 minutes. Each pass queries OpenRegister for
 * `pending-deposit` bookings older than the deposit-timeout window
 * (default 15 min) and forwards each one to the deposit service.
 * Errors are logged and skipped so a single bad row never aborts the
 * pass.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
class AppointmentDepositTimeoutJob extends TimedJob {
	/**
	 * Interval in seconds (5 minutes).
	 *
	 * The deposit timeout itself is 15 minutes; we sample three times
	 * per window so the worst-case lag between a true expiry and the
	 * slot release is ~5 minutes.
	 *
	 * @var int
	 */
	private const INTERVAL = 300;

	/**
	 * Per-pass cap on releases so a backlog of stale bookings cannot
	 * monopolise the cron worker. Subsequent passes drain the rest.
	 *
	 * @var int
	 */
	private const MAX_RELEASES_PER_PASS = 50;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The time factory.
	 * @param ContainerInterface $container DI container (OR ObjectService).
	 * @param IAppConfig $appConfig The app configuration.
	 * @param AppointmentDepositService $depositService The deposit service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private AppointmentDepositService $depositService,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Drain pending-deposit timeouts.
	 *
	 * @param mixed $argument The job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Required by TimedJob::run.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	protected function run(mixed $argument): void {
		$candidates = $this->loadPendingDeposits();
		if ($candidates === []) {
			return;
		}

		$released = 0;
		foreach ($candidates as $candidate) {
			$released += $this->releaseIfExpired(candidate: $candidate);
		}

		if ($released > 0) {
			$this->logger->info(
				'AppointmentDepositTimeoutJob: released expired deposit holds',
				['count' => $released]
			);
		}
	}//end run()

	/**
	 * Load pending-deposit candidates from OpenRegister.
	 *
	 * Returns an empty array on any failure (missing config, OR
	 * unavailable, query failure) so the calling `run()` stays linear.
	 *
	 * @return array<int, mixed>
	 */
	private function loadPendingDeposits(): array {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(
			Application::APP_ID,
			BookingService::BOOKING_SCHEMA_KEY,
			''
		);
		if ($register === '' || $schema === '') {
			$this->logger->debug('AppointmentDepositTimeoutJob: no register/schema configured, skipping');
			return [];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->debug('AppointmentDepositTimeoutJob: OR ObjectService unavailable, skipping');
			return [];
		}

		try {
			$candidates = $objectService->findAll(
				config: [
					'register' => $register,
					'schema' => $schema,
					'filters' => ['status' => 'pending-deposit'],
					'limit' => self::MAX_RELEASES_PER_PASS,
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'AppointmentDepositTimeoutJob: pending-deposit query failed',
				['exception' => $e]
			);
			return [];
		}

		if (is_array($candidates) === false) {
			return [];
		}

		return $candidates;
	}//end loadPendingDeposits()

	/**
	 * Release a single candidate if its 15-minute window has elapsed.
	 *
	 * @param mixed $candidate A booking row from OR.
	 *
	 * @return int 1 when the candidate was released, 0 otherwise.
	 */
	private function releaseIfExpired(mixed $candidate): int {
		$data = $this->toArray(object: $candidate);
		if ($data === null) {
			return 0;
		}

		$bookingId = $this->idOf(data: $data);
		$createdAt = $this->createdAt(data: $data);
		if ($bookingId === '' || $createdAt === '') {
			return 0;
		}

		if ($this->depositService->isDepositExpired(createdAtIso: $createdAt) === false) {
			return 0;
		}

		try {
			$this->depositService->releaseExpiredDeposit(bookingId: $bookingId);
			return 1;
		} catch (Throwable $e) {
			$this->logger->warning(
				'AppointmentDepositTimeoutJob: release failed',
				['booking' => $bookingId]
			);
			return 0;
		}
	}//end releaseIfExpired()

	/**
	 * Pull the booking id out of an OR object (supporting @self + flat shapes).
	 *
	 * @param array<string, mixed> $data The booking row.
	 *
	 * @return string
	 */
	private function idOf(array $data): string {
		if (isset($data['@self']) === true && is_array($data['@self']) === true) {
			if (isset($data['@self']['id']) === true) {
				return (string)$data['@self']['id'];
			}

			if (isset($data['@self']['uuid']) === true) {
				return (string)$data['@self']['uuid'];
			}
		}

		if (isset($data['id']) === true) {
			return (string)$data['id'];
		}

		if (isset($data['uuid']) === true) {
			return (string)$data['uuid'];
		}

		return '';
	}//end idOf()

	/**
	 * Pull the creation timestamp out of an OR object.
	 *
	 * OR exposes `created` on `@self`; fall back to a `createdAt` field
	 * on the row, then to the first `statusHistory[0].changedAt` entry.
	 *
	 * @param array<string, mixed> $data The booking row.
	 *
	 * @return string
	 */
	private function createdAt(array $data): string {
		if (isset($data['@self']) === true && is_array($data['@self']) === true) {
			if (isset($data['@self']['created']) === true) {
				return (string)$data['@self']['created'];
			}
		}

		if (isset($data['createdAt']) === true) {
			return (string)$data['createdAt'];
		}

		$history = ($data['statusHistory'] ?? []);
		if (is_array($history) === true && isset($history[0]) === true && is_array($history[0]) === true) {
			return (string)($history[0]['changedAt'] ?? '');
		}

		return '';
	}//end createdAt()

	/**
	 * Normalise an OR entity to a plain array.
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
