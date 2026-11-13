<?php

/**
 * Unit tests for EventReminderJob.
 *
 * A person with a place at an event that starts in 23.5 hours gets one
 * reminder and a `reminderSentAt`; the next run sends nothing; people waiting,
 * events outside the window, and a mail that fails are left for later.
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
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\EventReminderJob;
use OCA\Pipelinq\Service\Marketing\EventEmailService;
use OCA\Pipelinq\Service\Marketing\EventRegistrationService;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';

/**
 * Tests for the day-before reminder of campaign events.
 */
class EventReminderJobTest extends TestCase {

	/**
	 * Now, as Unix time: 2026-11-01T10:00:00Z.
	 */
	private const NOW = 1793527200;

	/**
	 * The store.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * The mail double.
	 *
	 * @var EventEmailService&MockObject
	 */
	private EventEmailService $mails;

	/**
	 * Build the job over a store with the given events and registrations.
	 *
	 * @param array<int, array<string, mixed>> $events        The events.
	 * @param array<int, array<string, mixed>> $registrations The registrations.
	 *
	 * @return EventReminderJob
	 */
	private function job(array $events, array $registrations): EventReminderJob {
		$this->store = new InMemoryListObjectStore(['campaignEvent' => $events, 'eventRegistration' => $registrations]);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$this->mails = $this->createMock(EventEmailService::class);

		return new EventReminderJob(
			$time,
			new EventRegistrationService($this->store, new TouchpointService($this->store), $time),
			$this->mails,
			$this->createMock(LoggerInterface::class)
		);
	}//end job()

	/**
	 * Run the job once.
	 *
	 * @param EventReminderJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(EventReminderJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * One reminder per confirmed person, stamped, and none on the second run.
	 *
	 * @return void
	 */
	public function testAReminderGoesOutOnce(): void {
		$job = $this->job(
			events: [
				['uuid' => 'soon', 'signupStatus' => 'full', 'startAt' => gmdate('c', (self::NOW + 84600))],
				['uuid' => 'later', 'signupStatus' => 'open', 'startAt' => gmdate('c', (self::NOW + 90000))],
			],
			registrations: [
				['uuid' => 'r-1', 'event' => 'soon', 'email' => 'a@example.nl', 'status' => 'confirmed'],
				['uuid' => 'r-2', 'event' => 'soon', 'email' => 'b@example.nl', 'status' => 'waitlist'],
				['uuid' => 'r-3', 'event' => 'later', 'email' => 'c@example.nl', 'status' => 'confirmed'],
			]
		);
		$this->mails->expects($this->once())->method('sendReminder')
			->with($this->callback(static fn (array $e): bool => $e['uuid'] === 'soon'), $this->callback(static fn (array $r): bool => $r['uuid'] === 'r-1'))
			->willReturn(true);

		$this->runJob(job: $job);
		$this->runJob(job: $job);

		$this->assertSame('2026-11-01T10:00:00+00:00', $this->store->rows['eventRegistration']['r-1']['reminderSentAt']);
		$this->assertArrayNotHasKey('reminderSentAt', $this->store->rows['eventRegistration']['r-2']);
		$this->assertArrayNotHasKey('reminderSentAt', $this->store->rows['eventRegistration']['r-3']);
	}//end testAReminderGoesOutOnce()

	/**
	 * A mail that does not go out is not stamped, so the next run tries again.
	 *
	 * @return void
	 */
	public function testAFailedMailIsTriedAgain(): void {
		$job = $this->job(
			events: [['uuid' => 'soon', 'signupStatus' => 'open', 'startAt' => gmdate('c', (self::NOW + 84600))]],
			registrations: [['uuid' => 'r-1', 'event' => 'soon', 'email' => 'a@example.nl', 'status' => 'confirmed']]
		);
		$this->mails->expects($this->exactly(2))->method('sendReminder')->willReturn(false);

		$this->runJob(job: $job);
		$this->runJob(job: $job);

		$this->assertArrayNotHasKey('reminderSentAt', $this->store->rows['eventRegistration']['r-1']);
	}//end testAFailedMailIsTriedAgain()
}//end class
