<?php

/**
 * Unit tests for EventEmailService.
 *
 * The confirmation carries a calendar file with the event's start and a cancel
 * link; a waiting list notice carries the link but no calendar file; the
 * reminder carries the join link of an online event; and a cancel token
 * verifies only for the registration and time it was signed for.
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
 * @spec openspec/changes/marketing-events/specs/marketing-events-and-attendees/spec.md#requirement-attendees-get-a-confirmation-a-reminder-and-a-way-to-cancel-req-mev-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\EventEmailService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the mails of a campaign event.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\EventEmailService
 */
class EventEmailServiceTest extends TestCase {

	/**
	 * Now, as Unix time: 2026-11-01T10:00:00Z.
	 */
	private const NOW = 1793527200;

	/**
	 * What each sent message was given: to, subject, body, attachments (as [content, name, type]).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $sent = [];

	/**
	 * Attachment parts by attachment object id.
	 *
	 * @var array<int, array<int, string|null>>
	 */
	private array $attachments = [];

	/**
	 * Values written to the app config.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Build the service over recording doubles.
	 *
	 * @param string $secret The stored signing secret ('' when none yet).
	 * @param int    $now    The time.
	 *
	 * @return EventEmailService
	 */
	private function service(string $secret = 'test-secret', int $now = self::NOW): EventEmailService {
		$this->config = ['event_link_secret' => $secret];
		$test = $this;

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturnCallback(static fn (string $a): bool => str_contains($a, '@'));
		$mailer->method('createMessage')->willReturnCallback(
			function () use ($test): IMessage {
				$index = count($test->sent);
				$test->sent[$index] = ['to' => [], 'subject' => '', 'body' => '', 'attachments' => []];
				$message = $this->createMock(IMessage::class);
				$message->method('setTo')->willReturnCallback(function (array $to) use ($test, $index, $message) {
					$test->sent[$index]['to'] = $to;
					return $message;
				});
				$message->method('setSubject')->willReturnCallback(function (string $s) use ($test, $index, $message) {
					$test->sent[$index]['subject'] = $s;
					return $message;
				});
				$message->method('setPlainBody')->willReturnCallback(function (string $b) use ($test, $index, $message) {
					$test->sent[$index]['body'] = $b;
					return $message;
				});
				$message->method('attach')->willReturnCallback(function (IAttachment $a) use ($test, $index, $message) {
					$test->sent[$index]['attachments'][] = $test->partsOf(attachment: $a);
					return $message;
				});
				return $message;
			}
		);
		$mailer->method('createAttachment')->willReturnCallback(
			function (?string $data, ?string $name, ?string $type): IAttachment {
				$attachment = $this->createMock(IAttachment::class);
				$this->attachments[spl_object_id($attachment)] = [$data, $name, $type];
				return $attachment;
			}
		);
		$mailer->method('send')->willReturn([]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params): string => 'https://cloud.example/' . $route . '?token=' . $params['token']
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		return new EventEmailService($mailer, $l10n, $appConfig, $urls, $time, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * The content, name and type an attachment was created with.
	 *
	 * @param IAttachment $attachment The attachment.
	 *
	 * @return array<int, string|null>
	 */
	public function partsOf(IAttachment $attachment): array {
		return $this->attachments[spl_object_id($attachment)];
	}//end partsOf()

	/**
	 * The event used across the tests.
	 *
	 * @param array<string, mixed> $fields Fields over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function event(array $fields = []): array {
		return array_merge(
			[
				'uuid' => 'ev-1',
				'title' => 'Webinar AI, gemeenten',
				'startAt' => '2026-11-12T13:00:00+00:00',
				'endAt' => '2026-11-12T14:00:00+00:00',
				'venue' => 'Stadhuis',
				'room' => 'Raadzaal',
			],
			$fields
		);
	}//end event()

	/**
	 * A registration with a place gets the calendar file with the start, and a cancel link.
	 *
	 * @return void
	 */
	public function testTheConfirmationCarriesACalendarFileAndACancelLink(): void {
		$service = $this->service();

		$ok = $service->sendConfirmation(
			event: $this->event(),
			registration: ['uuid' => 'r-1', 'name' => 'Anna', 'email' => 'anna@example.nl', 'status' => 'confirmed']
		);

		$this->assertTrue($ok);
		$this->assertSame(['anna@example.nl'], $this->sent[0]['to']);
		$this->assertSame('Your registration for Webinar AI, gemeenten', $this->sent[0]['subject']);
		$this->assertStringContainsString('You are registered for Webinar AI, gemeenten on 12-11-2026 14:00.', $this->sent[0]['body']);
		$this->assertStringContainsString('Location: Stadhuis, Raadzaal', $this->sent[0]['body']);
		$token = $service->cancelToken(registrationId: 'r-1', expiresAt: strtotime('2026-11-12T13:00:00+00:00'));
		$this->assertStringContainsString('https://cloud.example/pipelinq.eventCancel.show?token=' . $token, $this->sent[0]['body']);

		[$ics, $name, $type] = $this->sent[0]['attachments'][0];
		$this->assertSame('event.ics', $name);
		$this->assertSame('text/calendar; method=REQUEST; charset=UTF-8', $type);
		$this->assertStringContainsString("DTSTART:20261112T130000Z\r\n", $ics);
		$this->assertStringContainsString("DTEND:20261112T140000Z\r\n", $ics);
		$this->assertStringContainsString("SUMMARY:Webinar AI\\, gemeenten\r\n", $ics);
		$this->assertStringContainsString("UID:event-ev-1-r-1@pipelinq\r\n", $ics);
		$this->assertStringContainsString('ATTENDEE;CN=Anna;RSVP=FALSE:mailto:anna@example.nl', $ics);
	}//end testTheConfirmationCarriesACalendarFileAndACancelLink()

	/**
	 * A waitlisted registration gets a notice with its place in line and no calendar file.
	 *
	 * @return void
	 */
	public function testAWaitingListNoticeHasNoCalendarFile(): void {
		$this->service()->sendConfirmation(
			event: $this->event(),
			registration: ['uuid' => 'r-2', 'email' => 'bob@example.nl', 'status' => 'waitlist', 'waitlistPosition' => 3]
		);

		$this->assertSame('You are on the waiting list for Webinar AI, gemeenten', $this->sent[0]['subject']);
		$this->assertStringContainsString('on the waiting list at place 3', $this->sent[0]['body']);
		$this->assertStringContainsString('Cancel your registration: https://cloud.example/', $this->sent[0]['body']);
		$this->assertSame([], $this->sent[0]['attachments']);
	}//end testAWaitingListNoticeHasNoCalendarFile()

	/**
	 * Someone who moves up gets the calendar file.
	 *
	 * @return void
	 */
	public function testAFreedPlaceMailCarriesTheCalendarFile(): void {
		$this->service()->sendPlaceFreed(event: $this->event(), registration: ['uuid' => 'r-3', 'email' => 'cor@example.nl']);

		$this->assertSame('You have a place at Webinar AI, gemeenten', $this->sent[0]['subject']);
		$this->assertCount(1, $this->sent[0]['attachments']);
	}//end testAFreedPlaceMailCarriesTheCalendarFile()

	/**
	 * The reminder of an online event carries its join link instead of a location.
	 *
	 * @return void
	 */
	public function testTheReminderOfAnOnlineEventCarriesTheJoinLink(): void {
		$this->service()->sendReminder(
			event: $this->event(['online' => true, 'joinUrl' => 'https://meet.example/abc']),
			registration: ['uuid' => 'r-1', 'email' => 'anna@example.nl']
		);

		$this->assertSame('Reminder: Webinar AI, gemeenten', $this->sent[0]['subject']);
		$this->assertStringContainsString('Join online: https://meet.example/abc', $this->sent[0]['body']);
		$this->assertStringNotContainsString('Location:', $this->sent[0]['body']);
		$this->assertSame([], $this->sent[0]['attachments']);
	}//end testTheReminderOfAnOnlineEventCarriesTheJoinLink()

	/**
	 * The cancellation notice names the event and its date, with no calendar file and no cancel link.
	 *
	 * @return void
	 */
	public function testTheEventCancelledNoticeHasNoCalendarFileOrCancelLink(): void {
		$ok = $this->service()->sendEventCancelled(
			event: $this->event(),
			registration: ['uuid' => 'r-1', 'email' => 'anna@example.nl', 'status' => 'confirmed']
		);

		$this->assertTrue($ok);
		$this->assertSame(['anna@example.nl'], $this->sent[0]['to']);
		$this->assertSame('Webinar AI, gemeenten is cancelled', $this->sent[0]['subject']);
		$this->assertSame(
			'We are sorry: Webinar AI, gemeenten on 12-11-2026 14:00 is cancelled. Your registration lapses; you do not need to do anything.',
			$this->sent[0]['body']
		);
		$this->assertSame([], $this->sent[0]['attachments']);
	}//end testTheEventCancelledNoticeHasNoCalendarFileOrCancelLink()

	/**
	 * The closed notice goes to the person, and nothing goes to an invalid address.
	 *
	 * @return void
	 */
	public function testTheClosedNoticeAndAnInvalidAddress(): void {
		$service = $this->service();

		$this->assertTrue($service->sendClosed(event: $this->event(), person: ['email' => 'late@example.nl']));
		$this->assertFalse($service->sendClosed(event: $this->event(), person: ['email' => 'not-an-address']));

		$this->assertCount(1, $this->sent);
		$this->assertSame('Registration for Webinar AI, gemeenten has closed', $this->sent[0]['subject']);
	}//end testTheClosedNoticeAndAnInvalidAddress()

	/**
	 * A token verifies for its registration; a changed id, action or time, or a wrong secret, does not.
	 *
	 * @return void
	 */
	public function testACancelTokenVerifiesOnlyAsSigned(): void {
		$service = $this->service();
		$expires = (self::NOW + 3600);
		$token = $service->cancelToken(registrationId: 'r-1', expiresAt: $expires);
		[, , , $signature] = explode('.', $token);

		$this->assertSame('r-1', $service->verifyCancelToken(token: $token));
		$this->assertNull($service->verifyCancelToken(token: 'r-2.event-cancel.' . $expires . '.' . $signature));
		$this->assertNull($service->verifyCancelToken(token: 'r-1.event-cancel.' . ($expires + 1) . '.' . $signature));
		$this->assertNull($service->verifyCancelToken(token: 'r-1.cancel.' . $expires . '.' . $signature));
		$this->assertNull($service->verifyCancelToken(token: 'garbage'));
		$this->assertNull($this->service(secret: 'other-secret')->verifyCancelToken(token: $token));
	}//end testACancelTokenVerifiesOnlyAsSigned()

	/**
	 * A token stops working when its time has come.
	 *
	 * @return void
	 */
	public function testAnExpiredTokenIsRefused(): void {
		$token = $this->service()->cancelToken(registrationId: 'r-1', expiresAt: self::NOW);

		$this->assertNull($this->service(now: self::NOW)->verifyCancelToken(token: $token));
		$this->assertSame('r-1', $this->service(now: (self::NOW - 1))->verifyCancelToken(token: $token));
	}//end testAnExpiredTokenIsRefused()

	/**
	 * Without a stored secret one is created, stored, and used from then on.
	 *
	 * @return void
	 */
	public function testASecretIsCreatedOnFirstUse(): void {
		$service = $this->service(secret: '');

		$token = $service->cancelToken(registrationId: 'r-1', expiresAt: (self::NOW + 60));

		$this->assertSame(64, strlen($this->config['event_link_secret']));
		$this->assertSame('r-1', $service->verifyCancelToken(token: $token));
	}//end testASecretIsCreatedOnFirstUse()
}//end class
