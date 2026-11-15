<?php

/**
 * EventReminderService: what each reminder says, and which registrations are
 * skipped instead of mailed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/campaign-events/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Event;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCA\Pipelinq\Service\Event\EventReminderService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Locks the reminder's wording and the skip paths.
 */
#[CoversClass(EventReminderService::class)]
class EventReminderServiceDeliveryTest extends TestCase
{

    /**
     * The service under test.
     *
     * @param array                  $event          The event the reader returns.
     * @param ObjectServiceInterface $objects        The object service double.
     * @param IMailer                $mailer         The mailer double.
     * @param string                 $registrations  The configured registration schema.
     * @param LoggerInterface|null   $logger         The logger double.
     * @param IL10N|null             $l10n           The translator double.
     *
     * @return EventReminderService
     */
    private function service(
        array $event,
        ObjectServiceInterface $objects,
        IMailer $mailer,
        string $registrations='22',
        ?LoggerInterface $logger=null,
        ?IL10N $l10n=null
    ): EventReminderService {
        $reader = $this->createMock(originalClassName: CallerObjectReader::class);
        $reader->method('read')->willReturn($event);
        $reader->method('register')->willReturn('7');

        $config = $this->createMock(originalClassName: IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            static fn(string $app, string $key, string $default=''): string => match ($key) {
                'campaignEvent_schema' => '21',
                'eventRegistration_schema' => $registrations,
                default => $default,
            }
        );

        if ($l10n === null) {
            $l10n = $this->createMock(originalClassName: IL10N::class);
            $l10n->method('t')->willReturnCallback(static fn(string $text, array $params=[]): string => vsprintf($text, $params));
            $l10n->method('l')->willReturn('12 november 2026 19:30');
        }

        return new EventReminderService(
            objectService: $objects,
            reader: $reader,
            appConfig: $config,
            mailer: $mailer,
            l10n: $l10n,
            logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class))
        );

    }//end service()


    /**
     * An object service that stamps the event and returns the given registrations.
     *
     * @param array $rows The registrations findAll returns.
     *
     * @return ObjectServiceInterface
     */
    private function objects(array $rows): ObjectServiceInterface
    {
        $objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
        $objects->method('saveObject')->willReturn($this->createMock(originalClassName: ObjectEntityInterface::class));
        $objects->method('findAll')->willReturn($rows);
        return $objects;

    }//end objects()


    /**
     * The reminder names the event, the localised start and the venue; the HTML body is escaped.
     *
     * @return void
     */
    public function testTheReminderNamesTitleTimeAndVenue(): void
    {
        $message = $this->createMock(originalClassName: IMessage::class);
        $message->expects($this->once())->method('setTo')->with(['sanne@example.org']);
        $message->expects($this->once())->method('setSubject')->with('Reminder: Q&A <warmtenet>');
        $message->expects($this->once())->method('setPlainBody')->with(
            'You are registered for Q&A <warmtenet> on 12 november 2026 19:30 at De Linde. We look forward to seeing you.'
        );
        $message->expects($this->once())->method('setHtmlBody')->with(
            '<p>You are registered for Q&amp;A &lt;warmtenet&gt; on 12 november 2026 19:30 at De Linde. We look forward to seeing you.</p>'
        );

        $mailer = $this->createMock(originalClassName: IMailer::class);
        $mailer->method('validateMailAddress')->with('sanne@example.org')->willReturn(true);
        $mailer->method('createMessage')->willReturn($message);
        $mailer->expects($this->once())->method('send')->with($message);

        $event  = ['title' => 'Q&A <warmtenet>', 'startAt' => '2026-11-12T19:30:00+01:00', 'venue' => '  De Linde '];
        $result = $this->service(event: $event, objects: $this->objects(rows: [['email' => ' sanne@example.org ']]), mailer: $mailer)
            ->send(eventId: 'e1');

        $this->assertSame(expected: ['sent' => 1, 'skipped' => 0], actual: $result);

    }//end testTheReminderNamesTitleTimeAndVenue()


    /**
     * An invalid address and a failed send are skipped; the failure is logged without the address.
     *
     * @return void
     */
    public function testInvalidAddressesAndFailedSendsAreSkipped(): void
    {
        $mailer = $this->createMock(originalClassName: IMailer::class);
        $mailer->method('validateMailAddress')->willReturnCallback(static fn(string $address): bool => $address !== 'not-an-address');
        $mailer->method('createMessage')->willReturnCallback(fn() => $this->createMock(originalClassName: IMessage::class));
        $mailer->expects($this->exactly(count: 2))->method('send')->willReturnOnConsecutiveCalls(
            $this->throwException(new RuntimeException('smtp down')),
            []
        );

        $logger = $this->createMock(originalClassName: LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'Pipelinq: an event reminder could not be sent',
            ['app' => 'pipelinq', 'exception' => 'smtp down']
        );

        $rows   = [['email' => 'not-an-address'], ['email' => 'b@example.org'], ['email' => 'c@example.org']];
        $result = $this->service(event: ['title' => 'Open dag'], objects: $this->objects(rows: $rows), mailer: $mailer, logger: $logger)
            ->send(eventId: 'e1');

        $this->assertSame(expected: ['sent' => 1, 'skipped' => 2], actual: $result);

    }//end testInvalidAddressesAndFailedSendsAreSkipped()


    /**
     * The stamp keeps the event's fields and wraps a save failure with its cause.
     *
     * @return void
     */
    public function testTheStampKeepsTheEventAndWrapsFailures(): void
    {
        $objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
        $cause   = new \Exception('locked');
        $objects->expects($this->once())->method('saveObject')->with(
            $this->callback(
                callback: static fn(array $object): bool => $object['title'] === 'x'
                    && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $object['reminderSentAt']) === 1
            ),
            $this->anything(),
            '7',
            '21',
            'e1'
        )->willThrowException($cause);

        try {
            $this->service(event: ['title' => 'x'], objects: $objects, mailer: $this->createMock(originalClassName: IMailer::class))
                ->send(eventId: 'e1');
            $this->fail(message: 'A failed stamp must throw.');
        } catch (RuntimeException $e) {
            $this->assertSame(expected: 'event_not_saved: locked', actual: $e->getMessage());
            $this->assertSame(expected: $cause, actual: $e->getPrevious());
        }

    }//end testTheStampKeepsTheEventAndWrapsFailures()


}//end class
