<?php

/**
 * Unit tests for LetterLog::log(): what it answers for each shape of saved
 * contact moment, and for a save that fails.
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

use JsonSerializable;
use OCA\Pipelinq\Service\Letter\LetterLog;
use OCA\Pipelinq\Service\TicketService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the outcome of LetterLog::log().
 *
 * @covers \OCA\Pipelinq\Service\Letter\LetterLog
 * @uses   \OCA\Pipelinq\Service\Letter\LoggedLetter
 */
class LetterLogSaveOutcomeTest extends TestCase
{


    /**
     * A LetterLog whose ticket service answers save() with the given result.
     *
     * @param object               $saved  The saved contact moment.
     * @param LoggerInterface|null $logger The logger, a mock when null.
     *
     * @return LetterLog
     */
    private function logReturning(object $saved, ?LoggerInterface $logger=null): LetterLog
    {
        $tickets = $this->createMock(TicketService::class);
        $tickets->method('save')->willReturn($saved);

        return new LetterLog($tickets, $logger ?? $this->createMock(LoggerInterface::class));

    }//end logReturning()


    /**
     * A failed save is logged as an error with the client and the cause, and
     * log() answers null instead of throwing.
     *
     * @return void
     */
    public function testAFailedSaveIsLoggedAndAnswersNull(): void
    {
        $tickets = $this->createMock(TicketService::class);
        $tickets->method('save')->willThrowException(new RuntimeException('not configured'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'Pipelinq: the letter is made but its contact moment was not saved',
            $this->callback(
                static function (array $context): bool {
                    return $context['app'] === 'pipelinq' && $context['clientId'] === 'client-9'
                        && $context['exception'] === 'not configured';
                }
            )
        );

        $log = new LetterLog($tickets, $logger);
        $this->assertNull($log->log('client-9', null, null, 'T', null));

    }//end testAFailedSaveIsLoggedAndAnswersNull()


    /**
     * A saved entity is read through jsonSerialize(), not its public properties.
     *
     * @return void
     */
    public function testASerialisableResultIsReadThroughJsonSerialize(): void
    {
        $saved = new class implements JsonSerializable {

            /**
             * A public id that must not be used.
             *
             * @var string
             */
            public string $id = 'public-id';


            /**
             * The serialised entity.
             *
             * @return array<string, string>
             */
            public function jsonSerialize(): array
            {
                return ['id' => 'serialised-id'];

            }//end jsonSerialize()


        };

        $this->assertSame('serialised-id', $this->logReturning($saved)->log('c', null, null, 'T', null));

    }//end testASerialisableResultIsReadThroughJsonSerialize()


    /**
     * Without a top-level id the id under @self is used.
     *
     * @return void
     */
    public function testTheSelfIdIsUsedWithoutATopLevelId(): void
    {
        $saved = (object) ['@self' => ['id' => 'self-id'], 'uuid' => 'uuid-id'];

        $this->assertSame('self-id', $this->logReturning($saved)->log('c', null, null, 'T', null));

    }//end testTheSelfIdIsUsedWithoutATopLevelId()


    /**
     * Without any id the uuid is used.
     *
     * @return void
     */
    public function testTheUuidIsTheLastFallback(): void
    {
        $saved = (object) ['uuid' => 'uuid-id'];

        $this->assertSame('uuid-id', $this->logReturning($saved)->log('c', null, null, 'T', null));

    }//end testTheUuidIsTheLastFallback()


    /**
     * A saved contact moment without any id answers null.
     *
     * @return void
     */
    public function testNoIdAnswersNull(): void
    {
        $saved = (object) ['title' => 'Letter: T'];

        $this->assertNull($this->logReturning($saved)->log('c', null, null, 'T', null));

    }//end testNoIdAnswersNull()


}//end class
