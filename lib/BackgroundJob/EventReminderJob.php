<?php

/**
 * Pipelinq EventReminderJob.
 *
 * Every five minutes, mails the reminder to each person with a place at a
 * campaign event that starts between 23 and 24 hours from now, and stamps
 * `reminderSentAt` on the registration so a later run never sends it twice.
 * One run sends at most BATCH mails; the rest go out on the next run, still
 * inside the hour-wide window. A failure on one registration is logged and
 * does not stop the others.
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
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use OCA\Pipelinq\Kernel\Contracts\EventRegistrationServiceInterface;
use OCA\Pipelinq\Service\Marketing\EventEmailService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends the day-before reminder of campaign events.
 *
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
 */
class EventReminderJob extends TimedJob {

	/**
	 * How often the job runs, in seconds.
	 */
	public const INTERVAL_SECONDS = 300;

	/**
	 * The window opens this many seconds before the start.
	 */
	public const WINDOW_UPPER_SECONDS = (24 * 3600);

	/**
	 * The window closes this many seconds before the start.
	 */
	public const WINDOW_LOWER_SECONDS = (23 * 3600);

	/**
	 * At most this many mails per run.
	 */
	public const BATCH = 200;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time          The clock.
	 * @param EventRegistrationServiceInterface $registrations The events and their registrations.
	 * @param EventEmailService        $mails         Sends the reminder.
	 * @param LoggerInterface          $logger        The logger.
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	public function __construct(
		ITimeFactory $time,
		private EventRegistrationServiceInterface $registrations,
		private EventEmailService $mails,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL_SECONDS);
	}//end __construct()

	/**
	 * Send the reminders that are due.
	 *
	 * @param mixed $argument Job argument (unused).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is required by TimedJob::run().
	 *
	 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
	 */
	protected function run(mixed $argument): void {
		$now = $this->time->getTime();
		$events = $this->registrations->eventsStartingBetween(
			from: ($now + self::WINDOW_LOWER_SECONDS),
			to: ($now + self::WINDOW_UPPER_SECONDS)
		);

		$sent = 0;
		foreach ($events as $event) {
			$eventId = (string)($event['uuid'] ?? $event['id'] ?? '');
			foreach ($this->registrations->registrationsWithStatus(eventId: $eventId, status: 'confirmed') as $registration) {
				if ($sent >= self::BATCH) {
					return;
				}

				if (trim((string)($registration['reminderSentAt'] ?? '')) !== '') {
					continue;
				}

				$sent += $this->remind(event: $event, registration: $registration);
			}
		}
	}//end run()

	/**
	 * Remind one person and stamp the registration.
	 *
	 * @param array<string, mixed> $event        The event.
	 * @param array<string, mixed> $registration The registration.
	 *
	 * @return int 1 when a mail went out, else 0.
	 */
	private function remind(array $event, array $registration): int {
		try {
			if ($this->mails->sendReminder(event: $event, registration: $registration) === false) {
				return 0;
			}

			$this->registrations->stamp(registration: $registration, field: 'reminderSentAt');
			return 1;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Pipelinq: an event reminder could not be sent',
				['registration' => (string)($registration['uuid'] ?? ''), 'reason' => $e->getMessage()]
			);
			return 0;
		}
	}//end remind()
}//end class
