<?php

/**
 * Unit tests for EventService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-reminder-goes-to-everyone-with-a-place
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\EmailFallbackSender;
use OCA\Pipelinq\Service\Marketing\EventService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for EventService: who the reminder reaches, and the door check-in.
 *
 * @spec openspec/changes/public-events/specs/public-events/spec.md#requirement-a-reminder-goes-to-everyone-with-a-place
 */
class EventServiceTest extends TestCase {

	/**
	 * The store events and registrations live in.
	 *
	 * @var InMemoryListObjectStore
	 */
	private InMemoryListObjectStore $store;

	/**
	 * The mail path.
	 *
	 * @var EmailFallbackSender&MockObject
	 */
	private EmailFallbackSender $mail;

	/**
	 * Set up one event with four registrations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryListObjectStore([
			'event' => [
				['uuid' => 'event-1', 'name' => 'Informatieavond', 'startsAt' => '2026-11-12T19:30:00+01:00', 'location' => 'De Linde'],
			],
			'eventRegistration' => [
				['uuid' => 'reg-1', 'eventId' => 'event-1', 'name' => 'Sanne', 'status' => 'confirmed', 'email' => 'sanne@example.org'],
				['uuid' => 'reg-2', 'eventId' => 'event-1', 'name' => 'Kees', 'status' => 'confirmed', 'email' => ''],
				['uuid' => 'reg-3', 'eventId' => 'event-1', 'name' => 'Joke', 'status' => 'waitlist', 'email' => 'joke@example.org'],
				['uuid' => 'reg-4', 'eventId' => 'event-1', 'name' => 'Ruud', 'status' => 'cancelled', 'email' => 'ruud@example.org'],
				['uuid' => 'reg-5', 'eventId' => 'event-2', 'name' => 'Other', 'status' => 'confirmed', 'email' => 'other@example.org'],
			],
		]);
		$this->mail = $this->createMock(EmailFallbackSender::class);
	}//end setUp()

	/**
	 * The service under test, with a fixed clock.
	 *
	 * @return EventService The service.
	 */
	private function service(): EventService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1793000000);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));

		return new EventService($this->store, $this->mail, $time, $l10n, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * Only a confirmed registration with an address of this event is mailed.
	 *
	 * @return void
	 */
	public function testTheReminderReachesOnlyConfirmedRegistrationsWithAnAddress(): void {
		$this->mail->expects($this->once())
			->method('send')
			->with($this->callback(static fn (array $message) => $message['category'] === 'service' && str_contains($message['subject'], 'Informatieavond')), 'sanne@example.org', false)
			->willReturn(true);

		$tally = $this->service()->sendReminder(eventId: 'event-1');

		$this->assertSame(['sent' => 1, 'refused' => 0, 'skipped' => 1, 'failed' => 0], $tally);
		$this->assertNotEmpty($this->store->rows['event']['event-1']['reminderSentAt']);
	}//end testTheReminderReachesOnlyConfirmedRegistrationsWithAnAddress()

	/**
	 * A send integriq refused counts as refused, a throwing send as failed.
	 *
	 * @return void
	 */
	public function testARefusalAndAFailureAreCountedApart(): void {
		$this->store->rows['eventRegistration']['reg-2']['email'] = 'kees@example.org';
		$this->mail->method('send')->willReturnOnConsecutiveCalls(false, $this->throwException(new RuntimeException('smtp down')));
		$this->mail->method('lastRefusal')->willReturn(['code' => 'opted_out', 'reason' => 'Opted out']);

		$tally = $this->service()->sendReminder(eventId: 'event-1');

		$this->assertSame(['sent' => 0, 'refused' => 1, 'skipped' => 0, 'failed' => 1], $tally);
	}//end testARefusalAndAFailureAreCountedApart()

	/**
	 * An unknown event sends nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownEventSendsNothing(): void {
		$this->mail->expects($this->never())->method('send');

		$this->assertNull($this->service()->sendReminder(eventId: 'nope'));
	}//end testAnUnknownEventSendsNothing()

	/**
	 * Checking in twice keeps the first moment.
	 *
	 * @return void
	 */
	public function testACheckInIsStampedOnce(): void {
		$first = $this->service()->checkIn(registrationId: 'reg-1');
		$this->assertSame('checked_in', $first['outcome']);
		$stamp = $this->store->rows['eventRegistration']['reg-1']['checkedInAt'];
		$this->assertNotEmpty($stamp);

		$second = $this->service()->checkIn(registrationId: 'reg-1');
		$this->assertSame('already', $second['outcome']);
		$this->assertSame($stamp, $this->store->rows['eventRegistration']['reg-1']['checkedInAt']);
	}//end testACheckInIsStampedOnce()

	/**
	 * A cancelled registration is not checked in, and an unknown one is not found.
	 *
	 * @return void
	 */
	public function testACancelledOrUnknownRegistrationIsNotCheckedIn(): void {
		$this->assertSame('cancelled', $this->service()->checkIn(registrationId: 'reg-4')['outcome']);
		$this->assertArrayNotHasKey('checkedInAt', $this->store->rows['eventRegistration']['reg-4']);
		$this->assertSame('not_found', $this->service()->checkIn(registrationId: 'nope')['outcome']);
	}//end testACancelledOrUnknownRegistrationIsNotCheckedIn()
}//end class
