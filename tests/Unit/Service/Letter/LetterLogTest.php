<?php

/**
 * Unit tests for LetterLog: the exact contact moment payload a letter is
 * logged as, and the payload log() hands to TicketService::save().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Letter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-letter-is-logged-on-the-client-req-wlt-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Letter;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Letter\LetterLog;
use OCA\Pipelinq\Service\Letter\LoggedLetter;
use OCA\Pipelinq\Service\TicketService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LetterLog.
 */
class LetterLogTest extends TestCase
{


    /**
     * A letter with a contact, a ticket and a filed copy carries all three.
     *
     * @return void
     */
    public function testFullPayload(): void
    {
        $payload = LetterLog::contactMomentPayload(
            letter: new LoggedLetter(
                clientId: 'client-1',
                contactId: 'contact-1',
                ticketId: 'ticket-1',
                templateName: 'Appointment letter',
                path: '/Letters/a.pdf',
            ),
            occurredAt: new DateTimeImmutable('2026-09-30T10:15:00+02:00')
        );

        $this->assertSame(
            [
                'client'       => 'client-1',
                'title'        => 'Letter: Appointment letter',
                'direction'    => 'outbound',
                'channel'      => 'brief',
                'outcome'      => 'handled',
                'description'  => 'Letter made from the filinq template "Appointment letter". A copy is filed at /Letters/a.pdf.',
                'occurredAt'   => '2026-09-30T10:15:00+02:00',
                'contact'      => 'contact-1',
                'parentTicket' => 'ticket-1',
            ],
            $payload
        );

    }//end testFullPayload()


    /**
     * Without contact, ticket or path (null or empty) those keys and the copy
     * sentence are left out, and a long title is cut at 255 characters.
     *
     * @return void
     */
    public function testMinimalPayload(): void
    {
        $name = str_repeat('é', 300);
        foreach ([null, ''] as $path) {
            $payload = LetterLog::contactMomentPayload(
                letter: new LoggedLetter(
                    clientId: 'client-2',
                    contactId: null,
                    ticketId: null,
                    templateName: $name,
                    path: $path,
                ),
                occurredAt: new DateTimeImmutable('2026-01-02T03:04:05+00:00')
            );

            $this->assertSame(
                ['client', 'title', 'direction', 'channel', 'outcome', 'description', 'occurredAt'],
                array_keys($payload)
            );
            $this->assertSame(mb_substr('Letter: '.$name, 0, 255), $payload['title']);
            $this->assertSame(255, mb_strlen($payload['title']));
            $this->assertSame('Letter made from the filinq template "'.$name.'".', $payload['description']);
            $this->assertSame('2026-01-02T03:04:05+00:00', $payload['occurredAt']);
        }

    }//end testMinimalPayload()


    /**
     * Log() saves that payload as a contact moment and returns the saved id.
     *
     * @return void
     */
    public function testLogSavesThePayload(): void
    {
        $tickets = $this->createMock(TicketService::class);
        $tickets->expects($this->once())->method('save')->with(
            TicketService::TYPE_CONTACTMOMENT,
            $this->callback(
                static function (array $payload): bool {
                    return $payload['client'] === 'client-3' && $payload['contact'] === 'contact-3'
                        && isset($payload['parentTicket']) === false
                        && $payload['description'] === 'Letter made from the filinq template "T". A copy is filed at /p.'
                        && (new DateTimeImmutable($payload['occurredAt'])) <= new DateTimeImmutable();
                }
            )
        )->willReturn((object) ['id' => 'cm-1']);

        $log = new LetterLog($tickets, $this->createMock(LoggerInterface::class));
        $this->assertSame('cm-1', $log->log('client-3', 'contact-3', null, 'T', '/p'));

    }//end testLogSavesThePayload()


}//end class
