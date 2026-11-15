<?php

/**
 * Unit tests for ActivityItemNormalizer.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ActivityItemNormalizer;
use OCP\IDateTimeFormatter;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ActivityItemNormalizer turns every timeline source into one item shape.
 */
class ActivityItemNormalizerTest extends TestCase {

	/**
	 * A normalizer whose user manager knows "anna" as "Anna de Vries" and whose
	 * formatter writes "<ISO> (long, short)".
	 *
	 * @return ActivityItemNormalizer
	 */
	private function normalizer(): ActivityItemNormalizer {
		$anna = $this->createMock(IUser::class);
		$anna->method('getDisplayName')->willReturn('Anna de Vries');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => ($uid === 'anna' ? $anna : null));

		$formatter = $this->createMock(IDateTimeFormatter::class);
		$formatter->method('formatDateTime')->willReturnCallback(
			static fn (\DateTime $moment, string $date, string $time): string => $moment->format('Y-m-d H:i') . " ($date, $time)"
		);

		return new ActivityItemNormalizer(userManager: $users, dateTimeFormatter: $formatter);
	}//end normalizer()

	/**
	 * An unknown source gives no item.
	 *
	 * @return void
	 */
	public function testAnUnknownSourceGivesNothing(): void {
		self::assertNull($this->normalizer()->normalize(sourceType: 'fax', object: ['id' => 'x'], entityType: 'client', entityId: 'c-1'));
	}//end testAnUnknownSourceGivesNothing()

	/**
	 * Every source carries the entity it is shown on and the common keys.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function sources(): array {
		return [
			'interaction' => ['interaction', 'interaction'],
			'task' => ['task', 'task'],
			'emailLink' => ['emailLink', 'email'],
			'calendarLink' => ['calendarLink', 'calendar'],
			'note' => ['note', 'note'],
			'message' => ['message', 'message'],
			'booking' => ['booking', 'calendar'],
			'linkedEmail' => ['linkedEmail', 'email'],
		];
	}//end sources()

	/**
	 * Each source maps to its item type and keeps the entity.
	 *
	 * @param string $source The source type.
	 * @param string $type   The item type it becomes.
	 *
	 * @return void
	 */
	#[DataProvider('sources')]
	public function testEachSourceMapsToItsType(string $source, string $type): void {
		$item = $this->normalizer()->normalize(sourceType: $source, object: ['id' => 'obj-1'], entityType: 'lead', entityId: 'l-9');

		self::assertSame($type, $item['type']);
		self::assertSame('obj-1', $item['id']);
		self::assertSame('lead', $item['entityType']);
		self::assertSame('l-9', $item['entityId']);
		foreach (['title', 'description', 'date', 'user', 'userName', 'metadata'] as $key) {
			self::assertArrayHasKey($key, $item, $source . ' lacks ' . $key);
		}
	}//end testEachSourceMapsToItsType()

	/**
	 * An interaction falls back to its description as title and names the assignee.
	 *
	 * @return void
	 */
	public function testAnInteraction(): void {
		$item = $this->normalizer()->normalize(
			sourceType: 'interaction',
			object: ['uuid' => 'i-1', 'description' => 'Belde over offerte', 'channel' => 'phone', 'direction' => 'inbound', 'duration' => 300, 'occurredAt' => '2026-11-10T09:00:00Z', 'assignee' => 'anna'],
			entityType: 'client',
			entityId: 'c-1'
		);

		self::assertSame('i-1', $item['id']);
		self::assertSame('Belde over offerte', $item['title']);
		self::assertSame('Belde over offerte', $item['description']);
		self::assertSame('2026-11-10T09:00:00Z', $item['date']);
		self::assertSame('anna', $item['user']);
		self::assertSame('Anna de Vries', $item['userName']);
		self::assertSame(['channel' => 'phone', 'direction' => 'inbound', 'duration' => '300', 'outcome' => null], $item['metadata']);
	}//end testAnInteraction()

	/**
	 * A worklog interaction becomes a worklog item.
	 *
	 * @return void
	 */
	public function testAWorklogInteraction(): void {
		$item = $this->normalizer()->normalize(sourceType: 'interaction', object: ['id' => 'w', 'channel' => 'worklog', 'title' => 'Uren'], entityType: 'client', entityId: 'c');

		self::assertSame('worklog', $item['type']);
		self::assertSame('Uren', $item['title']);
	}//end testAWorklogInteraction()

	/**
	 * A task dates by deadline, else creation, and an unknown user keeps the uid as name.
	 *
	 * @return void
	 */
	public function testATask(): void {
		$normalizer = $this->normalizer();
		$task = $normalizer->normalize(
			sourceType: 'task',
			object: ['id' => 't', 'title' => 'Bel terug', 'createdAt' => '2026-11-01', 'assigneeUserId' => 'bram', 'status' => 'open', 'priority' => 'high'],
			entityType: 'client',
			entityId: 'c'
		);

		self::assertSame('Bel terug', $task['title']);
		self::assertSame('2026-11-01', $task['date']);
		self::assertSame('bram', $task['userName']);
		self::assertSame(['status' => 'open', 'priority' => 'high'], $task['metadata']);

		$withDeadline = $normalizer->normalize(sourceType: 'task', object: ['subject' => 'S', 'deadline' => '2026-12-01', 'createdAt' => '2026-11-01'], entityType: 'client', entityId: 'c');
		self::assertSame('S', $withDeadline['title']);
		self::assertSame('2026-12-01', $withDeadline['date']);
		self::assertNull($withDeadline['user']);
		self::assertNull($withDeadline['userName']);
	}//end testATask()

	/**
	 * A note keeps the author name it was stored with.
	 *
	 * @return void
	 */
	public function testANoteKeepsItsAuthorName(): void {
		$item = $this->normalizer()->normalize(
			sourceType: 'note',
			object: ['id' => 7, 'message' => 'Klant is tevreden', 'timestamp' => '2026-11-11T08:00:00Z', 'authorId' => 'anna', 'authorName' => 'A. de Vries'],
			entityType: 'client',
			entityId: 'c'
		);

		self::assertSame('7', $item['id']);
		self::assertSame('Klant is tevreden', $item['title']);
		self::assertSame('A. de Vries', $item['userName']);
	}//end testANoteKeepsItsAuthorName()

	/**
	 * A message carries its channel, direction and delivery status; empty values are null.
	 *
	 * @return void
	 */
	public function testAMessage(): void {
		$item = $this->normalizer()->normalize(
			sourceType: 'message',
			object: ['uuid' => 'm', 'body' => 'Uw afspraak staat', 'sentAt' => '2026-11-12T10:00:00Z', 'channel' => 'whatsapp', 'direction' => 'outbound', 'deliveryStatus' => ''],
			entityType: 'contact',
			entityId: 'p'
		);

		self::assertSame('Uw afspraak staat', $item['title']);
		self::assertSame(['channel' => 'whatsapp', 'direction' => 'outbound', 'deliveryStatus' => null], $item['metadata']);
		self::assertNull($item['user']);
	}//end testAMessage()

	/**
	 * A booking is dated by when it was booked and titled with its subject and formatted start.
	 *
	 * @return void
	 */
	public function testABooking(): void {
		$item = $this->normalizer()->normalize(
			sourceType: 'booking',
			object: ['id' => 'b', 'serviceName' => 'Paspoort aanvragen', 'startAt' => '2026-11-20T14:30:00', 'status' => 'confirmed', 'statusHistory' => [['changedAt' => '2026-11-02T11:00:00Z']]],
			entityType: 'contact',
			entityId: 'p'
		);

		self::assertSame('Paspoort aanvragen, 2026-11-20 14:30 (long, short)', $item['title']);
		self::assertSame('2026-11-02', $item['date']);
		self::assertSame(['startAt' => '2026-11-20T14:30:00', 'status' => 'confirmed'], $item['metadata']);
	}//end testABooking()

	/**
	 * Without a booking moment the start dates it; an unparseable start is shown as it is.
	 *
	 * @return void
	 */
	public function testABookingWithoutHistory(): void {
		$item = $this->normalizer()->normalize(sourceType: 'booking', object: ['title' => 'Intake', 'startAt' => 'next tuesday-ish'], entityType: 'contact', entityId: 'p');

		self::assertSame('Intake, next tuesday-ish', $item['title']);
		self::assertSame('next tuesd', $item['date']);
	}//end testABookingWithoutHistory()

	/**
	 * A booking without subject or start has an empty title and no date.
	 *
	 * @return void
	 */
	public function testAnEmptyBooking(): void {
		$item = $this->normalizer()->normalize(sourceType: 'booking', object: [], entityType: 'contact', entityId: 'p');

		self::assertSame('', $item['title']);
		self::assertNull($item['date']);
		self::assertSame('', $item['id']);
	}//end testAnEmptyBooking()

	/**
	 * Email and calendar links carry their own metadata.
	 *
	 * @return void
	 */
	public function testEmailAndCalendarLinks(): void {
		$normalizer = $this->normalizer();
		$email = $normalizer->normalize(sourceType: 'emailLink', object: ['id' => 'e', 'subject' => 'Offerte', 'sender' => 'klant@example.nl', 'date' => '2026-11-03', 'direction' => 'inbound', 'messageId' => '<abc@mail>'], entityType: 'client', entityId: 'c');
		self::assertSame('Offerte', $email['title']);
		self::assertSame('klant@example.nl', $email['description']);
		self::assertSame(['direction' => 'inbound', 'messageId' => '<abc@mail>'], $email['metadata']);

		$calendar = $normalizer->normalize(sourceType: 'calendarLink', object: ['id' => 'k', 'subject' => 'Demo', 'notes' => 'Bij klant', 'startDate' => '2026-11-04T10:00', 'endDate' => '2026-11-04T11:00', 'location' => 'Utrecht', 'uid' => 'ev-1'], entityType: 'client', entityId: 'c');
		self::assertSame('Demo', $calendar['title']);
		self::assertSame('Bij klant', $calendar['description']);
		self::assertSame('2026-11-04T10:00', $calendar['date']);
		self::assertSame(['endDate' => '2026-11-04T11:00', 'location' => 'Utrecht', 'uid' => 'ev-1'], $calendar['metadata']);
	}//end testEmailAndCalendarLinks()

	/**
	 * A linked email dates by the mail, else by the link, and names who linked it.
	 *
	 * @return void
	 */
	public function testALinkedEmail(): void {
		$item = $this->normalizer()->normalize(
			sourceType: 'linkedEmail',
			object: ['id' => 'le', 'subject' => 'Re: factuur', 'linkedAt' => '2026-11-05', 'linkedBy' => 'anna', 'mailAccountId' => 3, 'mailMessageId' => 99],
			entityType: 'client',
			entityId: 'c'
		);

		self::assertSame('2026-11-05', $item['date']);
		self::assertSame('anna', $item['user']);
		self::assertSame('Anna de Vries', $item['userName']);
		self::assertSame(['mailAccountId' => 3, 'mailMessageId' => 99], $item['metadata']);
	}//end testALinkedEmail()

	/**
	 * A calendar-leaf event needs a uid; a past one is completed, a future one scheduled.
	 *
	 * @return void
	 */
	public function testALeafEvent(): void {
		$normalizer = $this->normalizer();
		self::assertNull($normalizer->normalize(sourceType: 'leafEvent', object: ['summary' => 'x'], entityType: 'client', entityId: 'c'));

		$past = $normalizer->normalize(sourceType: 'leafEvent', object: ['uid' => 'u-1', 'summary' => 'Kennismaking', 'dtstart' => '2001-01-01T10:00:00Z', 'location' => 'Online'], entityType: 'client', entityId: 'c');
		self::assertSame('u-1', $past['id']);
		self::assertSame('Kennismaking', $past['title']);
		self::assertSame('completed', $past['metadata']['status']);
		self::assertSame('calendar-leaf', $past['metadata']['source']);
		self::assertSame('Online', $past['metadata']['location']);

		$future = $normalizer->normalize(sourceType: 'leafEvent', object: ['uid' => 'u-2', 'dtstart' => '2099-01-01T10:00:00Z'], entityType: 'client', entityId: 'c');
		self::assertSame('scheduled', $future['metadata']['status']);

		$undated = $normalizer->normalize(sourceType: 'leafEvent', object: ['uid' => 'u-3'], entityType: 'client', entityId: 'c');
		self::assertSame('scheduled', $undated['metadata']['status']);
		self::assertNull($undated['date']);
	}//end testALeafEvent()

	/**
	 * Non-scalar values never become text.
	 *
	 * @return void
	 */
	public function testNonScalarValuesAreNull(): void {
		$item = $this->normalizer()->normalize(sourceType: 'interaction', object: ['id' => 'i', 'occurredAt' => ['not' => 'a date'], 'assignee' => ''], entityType: 'client', entityId: 'c');

		self::assertNull($item['date']);
		self::assertNull($item['user']);
		self::assertNull($item['userName']);
	}//end testNonScalarValuesAreNull()
}//end class
