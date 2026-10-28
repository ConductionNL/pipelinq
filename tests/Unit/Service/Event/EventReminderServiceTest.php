<?php

/**
 * EventReminderService: the reminder reaches every confirmed registration
 * with an address, and nothing goes out when the caller may not change the
 * event.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/event-reminder-and-check-in/specs/campaign-events/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Event;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCA\Pipelinq\Service\Event\EventReminderService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Locks who gets the reminder and the order of the checks.
 */
class EventReminderServiceTest extends TestCase
{

    /**
     * The event the reader returns by default.
     */
    private const EVENT = [
        'title'   => 'Informatieavond warmtenet Oost',
        'startAt' => '2026-11-12T19:30:00+01:00',
        'venue'   => 'Wijkcentrum De Linde',
    ];


    /**
     * The service, with an event and three confirmed registrations, one without an address.
     *
     * @param ObjectServiceInterface $objects The object service double.
     * @param IMailer                $mailer  The mailer double.
     * @param array|null             $event   The event the reader returns.
     *
     * @return EventReminderService
     */
    private function service(ObjectServiceInterface $objects, IMailer $mailer, ?array $event=self::EVENT): EventReminderService
    {
        $reader = $this->createMock(originalClassName: CallerObjectReader::class);
        $reader->method('read')->willReturn($event);
        $reader->method('register')->willReturn('7');

        $config = $this->createMock(originalClassName: IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            static fn(string $app, string $key, string $default=''): string => match ($key) {
                'campaignEvent_schema' => '21',
                'eventRegistration_schema' => '22',
                default => $default,
            }
        );

        $l10n = $this->createMock(originalClassName: IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn(string $text, array $params=[]): string => vsprintf($text, $params));
        $l10n->method('l')->willReturn('12 november 2026 19:30');

        return new EventReminderService(
            objectService: $objects,
            reader: $reader,
            appConfig: $config,
            mailer: $mailer,
            l10n: $l10n,
            logger: $this->createMock(originalClassName: LoggerInterface::class)
        );

    }//end service()


    /**
     * Every confirmed registration with an address gets the reminder; the event is stamped first.
     *
     * @return void
     */
    public function testEveryConfirmedRegistrationWithAnAddressIsReminded(): void
    {
        $order   = [];
        $objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
        $objects->expects($this->once())->method('saveObject')->willReturnCallback(
            function (array $object, $extend, $register, $schema, $uuid) use (&$order) {
                $order[] = 'stamp';
                $this->assertSame(expected: '21', actual: $schema);
                $this->assertSame(expected: 'e1', actual: $uuid);
                $this->assertArrayHasKey(key: 'reminderSentAt', array: $object);
                return $this->createMock(originalClassName: \OCA\OpenRegister\Contract\ObjectEntityInterface::class);
            }
        );
        $objects->expects($this->once())->method('findAll')->with(
            $this->callback(
                callback: static fn(array $config): bool => $config['filters']['event'] === 'e1'
                    && $config['filters']['status'] === 'confirmed'
                    && $config['filters']['schema'] === '22'
            )
        )->willReturn(
            [
                ['name' => 'Sanne de Vries', 'email' => 'sanne@example.org'],
                ['name' => 'Fam. Kuiper', 'email' => ''],
                ['name' => 'Ahmed El Idrissi', 'email' => 'ahmed@example.org'],
            ]
        );

        $mailer = $this->createMock(originalClassName: IMailer::class);
        $mailer->method('validateMailAddress')->willReturn(true);
        $mailer->method('createMessage')->willReturnCallback(fn() => $this->createMock(originalClassName: IMessage::class));
        $mailer->expects($this->exactly(count: 2))->method('send')->willReturnCallback(
            function () use (&$order) {
                $order[] = 'mail';
                return [];
            }
        );

        $result = $this->service(objects: $objects, mailer: $mailer)->send(eventId: 'e1');

        $this->assertSame(expected: ['sent' => 2, 'skipped' => 1], actual: $result);
        $this->assertSame(expected: ['stamp', 'mail', 'mail'], actual: $order);

    }//end testEveryConfirmedRegistrationWithAnAddressIsReminded()


    /**
     * A caller who may not change the event sends nothing.
     *
     * @return void
     */
    public function testNoMailGoesOutWhenTheEventMayNotBeChanged(): void
    {
        $objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
        $objects->method('saveObject')->willThrowException(new \Exception('forbidden'));
        $objects->expects($this->never())->method('findAll');
        $mailer = $this->createMock(originalClassName: IMailer::class);
        $mailer->expects($this->never())->method('send');

        $this->expectException(exception: RuntimeException::class);
        $this->expectExceptionMessage(message: 'event_not_saved');
        $this->service(objects: $objects, mailer: $mailer)->send(eventId: 'e1');

    }//end testNoMailGoesOutWhenTheEventMayNotBeChanged()


    /**
     * An event the caller may not read is not found, and nothing happens.
     *
     * @return void
     */
    public function testAnUnreadableEventIsNotFound(): void
    {
        $objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
        $objects->expects($this->never())->method('saveObject');
        $mailer = $this->createMock(originalClassName: IMailer::class);
        $mailer->expects($this->never())->method('send');

        $this->expectExceptionMessage(message: 'event_not_found');
        $this->service(objects: $objects, mailer: $mailer, event: null)->send(eventId: 'e1');

    }//end testAnUnreadableEventIsNotFound()


}//end class
