<?php

/**
 * Tests for EventCancelledListener: a campaign event that turns cancelled
 * defers one job, and that job tells everyone with a place or on the waiting
 * list, as long as the event is still cancelled.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Kernel\Contracts\EventEmailServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\EventRegistrationServiceInterface;
use OCA\Pipelinq\Listener\DeferredHandlers;
use OCA\Pipelinq\Listener\EventCancelledListener;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Listener\EventCancelledListener
 */
class EventCancelledListenerTest extends TestCase {

	private const EVENT_SCHEMA = '41';

	private EventRegistrationServiceInterface&MockObject $registrations;

	private EventEmailServiceInterface&MockObject $mails;

	private RecordingDeferralService $deferral;

	private LoggerInterface&MockObject $logger;

	private EventCancelledListener $listener;

	/**
	 * The listener over doubles, with the event schema configured.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->registrations = $this->createMock(EventRegistrationServiceInterface::class);
		$this->mails = $this->createMock(EventEmailServiceInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ['campaignEvent_schema' => self::EVENT_SCHEMA][$key] ?? ''
		);
		$this->deferral = new RecordingDeferralService();
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new EventCancelledListener(
			registrations: $this->registrations,
			mails: $this->mails,
			appConfig: $appConfig,
			deferral: $this->deferral,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * An entity with the given schema and data.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The data.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('event-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * An update of event-1 from one registration status to another.
	 *
	 * @param string $was    The old status.
	 * @param string $is     The new status.
	 * @param string $schema The schema id.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private static function update(string $was, string $is, string $schema = self::EVENT_SCHEMA): ObjectUpdatedEvent {
		return new ObjectUpdatedEvent(
			self::entity(schema: $schema, data: ['title' => 'Webinar', 'signupStatus' => $is]),
			self::entity(schema: $schema, data: ['title' => 'Webinar', 'signupStatus' => $was])
		);
	}//end update()

	/**
	 * Cancelling an open event defers one job for it.
	 *
	 * @return void
	 */
	public function testCancellingAnEventDefersTheNotices(): void {
		$this->mails->expects(self::never())->method('sendEventCancelled');

		$this->listener->handle(event: self::update(was: 'open', is: 'cancelled'));

		self::assertSame([['handler' => 'event-cancelled', 'uuid' => 'event-1']], $this->deferral->entries);
		self::assertSame([DeferredObjectListenerJob::class], $this->deferral->jobClasses);
		self::assertSame(['event-cancelled|event-1'], $this->deferral->dedupeKeys);
		self::assertSame(EventCancelledListener::class, DeferredHandlers::MAP[EventCancelledListener::HANDLER_KEY]);
	}//end testCancellingAnEventDefersTheNotices()

	/**
	 * Another status change, an event already cancelled, another schema or another event type defers nothing.
	 *
	 * @return void
	 */
	public function testOnlyTheChangeToCancelledCounts(): void {
		$this->listener->handle(event: self::update(was: 'open', is: 'full'));
		$this->listener->handle(event: self::update(was: 'cancelled', is: 'cancelled'));
		$this->listener->handle(event: self::update(was: 'open', is: 'cancelled', schema: '7'));
		$this->listener->handle(event: new Event());

		self::assertSame([], $this->deferral->entries);
	}//end testOnlyTheChangeToCancelledCounts()

	/**
	 * The job mails everyone with a place and everyone waiting, and nobody who cancelled.
	 *
	 * @return void
	 */
	public function testTheJobTellsEveryoneWithAPlaceOrWaiting(): void {
		$event = ['id' => 'event-1', 'title' => 'Webinar', 'signupStatus' => 'cancelled'];
		$this->registrations->method('event')->with('event-1')->willReturn($event);
		$this->registrations->expects(self::exactly(2))->method('registrationsWithStatus')->willReturnCallback(
			static fn (string $eventId, string $status): array => [
				'confirmed' => [['id' => 'r-1', 'email' => 'a@example.org'], ['id' => 'r-2', 'email' => 'b@example.org']],
				'waitlist' => [['id' => 'r-3', 'email' => 'c@example.org']],
			][$status] ?? [['id' => 'r-x']]
		);
		$sent = [];
		$this->mails->method('sendEventCancelled')->willReturnCallback(
			static function (array $event, array $registration) use (&$sent): bool {
				$sent[] = [$event['id'], $registration['id']];
				return true;
			}
		);

		$this->listener->runDeferredWork(entry: ['handler' => 'event-cancelled', 'uuid' => 'event-1']);

		self::assertSame([['event-1', 'r-1'], ['event-1', 'r-2'], ['event-1', 'r-3']], $sent);
	}//end testTheJobTellsEveryoneWithAPlaceOrWaiting()

	/**
	 * An event reopened, or gone, before the job ran is not announced as cancelled.
	 *
	 * @return void
	 */
	public function testAnEventNoLongerCancelledSendsNothing(): void {
		$this->registrations->method('event')->willReturnOnConsecutiveCalls(['signupStatus' => 'open'], null);
		$this->registrations->expects(self::never())->method('registrationsWithStatus');
		$this->mails->expects(self::never())->method('sendEventCancelled');

		$this->listener->runDeferredWork(entry: ['uuid' => 'event-1']);
		$this->listener->runDeferredWork(entry: ['uuid' => 'event-1']);
	}//end testAnEventNoLongerCancelledSendsNothing()

	/**
	 * One notice that fails is logged and the next one still goes out.
	 *
	 * @return void
	 */
	public function testAFailingNoticeDoesNotStopTheOthers(): void {
		$this->registrations->method('event')->willReturn(['signupStatus' => 'cancelled']);
		$this->registrations->method('registrationsWithStatus')->willReturnCallback(
			static fn (string $eventId, string $status): array => $status === 'confirmed' ? [['uuid' => 'r-1'], ['uuid' => 'r-2']] : []
		);
		$this->mails->expects(self::exactly(2))->method('sendEventCancelled')->willReturnCallback(
			static function (array $event, array $registration): bool {
				if ($registration['uuid'] === 'r-1') {
					throw new RuntimeException('smtp down');
				}

				return true;
			}
		);
		$this->logger->expects(self::once())->method('warning')->with(
			'Pipelinq: an event cancellation notice could not be sent',
			['registration' => 'r-1', 'reason' => 'smtp down']
		);

		$this->listener->runDeferredWork(entry: ['uuid' => 'event-1']);
	}//end testAFailingNoticeDoesNotStopTheOthers()
}//end class
