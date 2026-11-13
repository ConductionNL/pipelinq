<?php

/**
 * Edge-case unit tests for EventReminderService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Event
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Event;

use JsonSerializable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCA\Pipelinq\Service\Event\EventReminderService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Registrations read from entities, an unconfigured registration schema, a
 * failed send, and an event without a readable start time.
 *
 * @covers \OCA\Pipelinq\Service\Event\EventReminderService
 */
class EventReminderEdgesTest extends TestCase
{

    /**
     * The plain bodies sent, in order.
     *
     * @var array<int, string>
     */
    private array $bodies = [];

    /**
     * The logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;


    /**
     * Fresh state per test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->bodies = [];
        $this->logger = $this->createMock(originalClassName: LoggerInterface::class);

    }//end setUp()


    /**
     * Without a registration schema nobody is reminded and nothing is searched.
     *
     * @return void
     */
    public function testNoRegistrationSchemaRemindsNobody(): void
    {
        $objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
        $objects->expects($this->once())->method('saveObject');
        $objects->expects($this->never())->method('findAll');

        $result = $this->service(objects: $objects, mailer: $this->mailer(), registrationSchema: '')->send('event-1');

        $this->assertSame(['sent' => 0, 'skipped' => 0], $result);

    }//end testNoRegistrationSchemaRemindsNobody()


    /**
     * Registrations returned as entities are read; rows that are neither are
     * ignored; the search asks for the event's confirmed registrations.
     *
     * @return void
     */
    public function testEntityRowsAreRead(): void
    {
        $objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
        $objects->expects($this->once())->method('findAll')->with(
            [
                'filters' => ['register' => '7', 'schema' => '22', 'event' => 'event-1', 'status' => 'confirmed'],
                'limit'   => 1000,
            ]
        )->willReturn([$this->entity(['email' => 'ada@example.org']), 'not a row', ['email' => '']]);

        $result = $this->service(objects: $objects, mailer: $this->mailer())->send('event-1');

        $this->assertSame(['sent' => 1, 'skipped' => 1], $result);

    }//end testEntityRowsAreRead()


    /**
     * A send that fails is logged and skipped; the next registration is still sent.
     *
     * @return void
     */
    public function testAFailedSendIsSkippedAndLogged(): void
    {
        $objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
        $objects->method('findAll')->willReturn([['email' => 'first@example.org'], ['email' => 'second@example.org']]);
        $this->logger->expects($this->once())->method('error')->with(
            'Pipelinq: an event reminder could not be sent',
            ['app' => 'pipelinq', 'exception' => 'smtp down']
        );

        $mailer = $this->mailer(failFor: 'first@example.org');
        $result = $this->service(objects: $objects, mailer: $mailer)->send('event-1');

        $this->assertSame(['sent' => 1, 'skipped' => 1], $result);
        $this->assertCount(1, $this->bodies);

    }//end testAFailedSendIsSkippedAndLogged()


    /**
     * Start times that cannot be formatted.
     *
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function startTimes(): array
    {
        return [
            'no start'          => [['title' => 'Avond', 'venue' => 'Zaal'], 'You are registered for Avond on  at Zaal. We look forward to seeing you.'],
            'unreadable start'  => [['title' => 'Avond', 'venue' => 'Zaal', 'startAt' => 'next tuesday-ish'], 'You are registered for Avond on next tuesday-ish at Zaal. We look forward to seeing you.'],
        ];

    }//end startTimes()


    /**
     * A missing start time is left blank and an unreadable one is shown as given.
     *
     * @param array<string, string> $event The event.
     * @param string                $body  The expected body.
     *
     * @dataProvider startTimes
     *
     * @return void
     */
    public function testAStartTimeThatCannotBeFormatted(array $event, string $body): void
    {
        $objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
        $objects->method('findAll')->willReturn([['email' => 'ada@example.org']]);

        $this->service(objects: $objects, mailer: $this->mailer(), event: $event)->send('event-1');

        $this->assertSame([$body], $this->bodies);

    }//end testAStartTimeThatCannotBeFormatted()


    /**
     * A mailer that records bodies and fails for one address.
     *
     * @param string|null $failFor The address whose send fails.
     *
     * @return IMailer
     */
    private function mailer(?string $failFor=null): IMailer
    {
        $sent   = [];
        $mailer = $this->createMock(originalClassName: IMailer::class);
        $mailer->method('validateMailAddress')->willReturnCallback(static fn(string $a): bool => str_contains($a, '@'));
        $mailer->method('createMessage')->willReturnCallback(
            function () use (&$sent): IMessage {
                $message = $this->createMock(originalClassName: IMessage::class);
                $id      = spl_object_id($message);
                $sent[$id] = ['to' => [], 'body' => ''];
                $message->method('setTo')->willReturnCallback(
                    function (array $recipients) use (&$sent, $id, $message): IMessage {
                        $sent[$id]['to'] = $recipients;
                        return $message;
                    }
                );
                $message->method('setPlainBody')->willReturnCallback(
                    function (string $text) use (&$sent, $id, $message): IMessage {
                        $sent[$id]['body'] = $text;
                        return $message;
                    }
                );
                $message->method('setSubject')->willReturnSelf();
                $message->method('setHtmlBody')->willReturnSelf();

                return $message;
            }
        );
        $mailer->method('send')->willReturnCallback(
            function (IMessage $message) use (&$sent, $failFor): array {
                $mail = $sent[spl_object_id($message)];
                if ($failFor !== null && in_array($failFor, $mail['to'], true) === true) {
                    throw new RuntimeException('smtp down');
                }

                $this->bodies[] = $mail['body'];
                return [];
            }
        );

        return $mailer;

    }//end mailer()


    /**
     * An entity serialising to the given row.
     *
     * @param array<string, mixed> $row The row.
     *
     * @return JsonSerializable
     */
    private function entity(array $row): JsonSerializable
    {
        return new class ($row) implements JsonSerializable {


            /**
             * Constructor.
             *
             * @param array<string, mixed> $row The row.
             */
            public function __construct(private readonly array $row)
            {

            }//end __construct()


            /**
             * Serialise.
             *
             * @return array<string, mixed>
             */
            public function jsonSerialize(): array
            {
                return $this->row;

            }//end jsonSerialize()


        };

    }//end entity()


    /**
     * The service under test.
     *
     * @param ObjectServiceInterface $objects            The object service.
     * @param IMailer                $mailer             The mailer.
     * @param string                 $registrationSchema The registration schema.
     * @param array<string, string>  $event              The event.
     *
     * @return EventReminderService
     */
    private function service(
        ObjectServiceInterface $objects,
        IMailer $mailer,
        string $registrationSchema='22',
        array $event=['title' => 'Avond', 'venue' => 'Zaal', 'startAt' => '2026-11-12T19:30:00+01:00'],
    ): EventReminderService {
        $reader = $this->createMock(originalClassName: CallerObjectReader::class);
        $reader->method('read')->willReturn($event);
        $reader->method('register')->willReturn('7');

        $config = $this->createMock(originalClassName: IAppConfig::class);
        $config->method('getValueString')->willReturnCallback(
            static fn(string $app, string $key, string $default=''): string => match ($key) {
                'campaignEvent_schema' => '21',
                'eventRegistration_schema' => $registrationSchema,
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
            logger: $this->logger
        );

    }//end service()


}//end class
