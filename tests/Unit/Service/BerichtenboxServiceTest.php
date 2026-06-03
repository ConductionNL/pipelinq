<?php

/**
 * Unit tests for BerichtenboxService.
 *
 * All external collaborators (OpenRegister, Logius, mailer) are mocked; no live
 * Logius credentials or running instance are required.
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
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Exception\BerichtenboxException;
use OCA\Pipelinq\Exception\LogiusApiException;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\DeliveryAuditLogger;
use OCA\Pipelinq\Service\DutchHolidayCalendar;
use OCA\Pipelinq\Service\EmailFallbackSender;
use OCA\Pipelinq\Service\EncryptionService;
use OCA\Pipelinq\Service\LogiusConnector;
use OCA\Pipelinq\Service\MailboxResolver;
use OCA\Pipelinq\Service\TemplateRenderer;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BerichtenboxService message lifecycle.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class BerichtenboxServiceTest extends TestCase
{
    /**
     * A valid BSN (passes the 11-proef).
     *
     * @var string
     */
    private const VALID_BSN = '123456782';

    /**
     * The object service mock.
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * Collaborator mocks.
     *
     * @var array<string, object>
     */
    private array $deps = [];

    /**
     * The service under test.
     *
     * @var BerichtenboxService
     */
    private BerichtenboxService $service;

    /**
     * Set up the service with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $container           = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);
        $container->method('has')->willReturn(false);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default=''): string {
                if ($key === 'register') {
                    return '1';
                }

                if (str_ends_with($key, '_schema') === true) {
                    return '2';
                }

                return $default;
            }
        );

        $encryption = $this->createMock(EncryptionService::class);
        $encryption->method('encrypt')->willReturn('cipher');
        $encryption->method('decrypt')->willReturn(self::VALID_BSN);
        $encryption->method('hashBsn')->willReturn('hash-abc');
        $encryption->method('shred')->willReturn('shredded');

        $renderer = $this->createMock(TemplateRenderer::class);
        $renderer->method('render')->willReturn(['subject' => 'Subject', 'body' => '<p>Body</p>']);

        $this->deps = [
            'encryption' => $encryption,
            'renderer'   => $renderer,
            'mailbox'    => $this->createMock(MailboxResolver::class),
            'logius'     => $this->createMock(LogiusConnector::class),
            'email'      => $this->createMock(EmailFallbackSender::class),
            'audit'      => $this->createMock(DeliveryAuditLogger::class),
        ];

        $this->service = new BerichtenboxService(
            $container,
            $appConfig,
            $encryption,
            $renderer,
            $this->deps['mailbox'],
            $this->deps['logius'],
            $this->deps['email'],
            $this->deps['audit'],
            new DutchHolidayCalendar(),
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * Queueing an outbound message encrypts the BSN and audits "queued".
     *
     * @return void
     */
    public function testQueueOutboundMessageEncryptsBsn(): void
    {
        $captured = null;
        $this->objectService->method('find')->willReturn(
            ['status' => 'afgehandeld', 'requiresDeepLink' => false]
        );
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array|object $object = []) use (&$captured): array {
                $captured = (array) $object;
                $captured['@self'] = ['id' => 'msg-1'];
                return $captured;
            }
        );
        $this->deps['audit']->expects($this->once())->method('log');

        $result = $this->service->queueOutboundMessage(
            zaakId: 'Z-1',
            contactmomentId: 'cm-1',
            bsn: self::VALID_BSN,
            templateId: 'tmpl-1'
        );

        $this->assertSame('cipher', $captured['bsn']);
        $this->assertSame('hash-abc', $captured['bsnHash']);
        $this->assertSame('queued', $captured['deliveryStatus']);
        $this->assertNotEmpty($result);
    }//end testQueueOutboundMessageEncryptsBsn()

    /**
     * An invalid BSN is rejected before any work happens.
     *
     * @return void
     */
    public function testQueueRejectsInvalidBsn(): void
    {
        $this->expectException(BerichtenboxException::class);
        $this->service->queueOutboundMessage(zaakId: 'Z', contactmomentId: 'c', bsn: '000000000', templateId: 't');
    }//end testQueueRejectsInvalidBsn()

    /**
     * Dispatch with an available mailbox delivers via Logius and marks "sent".
     *
     * @return void
     */
    public function testDispatchDeliversViaLogius(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [['@self' => ['id' => 'msg-1'], 'bsn' => 'cipher', 'body' => '<p>Body</p>', 'deliveryStatus' => 'queued']]
        );
        $this->objectService->method('find')->willReturn(['@self' => ['id' => 'msg-1']]);
        $this->deps['mailbox']->method('resolve')->willReturn(['mailboxAvailable' => true, 'optedOut' => false, 'cached' => false]);
        $this->deps['logius']->expects($this->once())->method('sendMessage')->willReturn(['logiusMessageId' => 'bbk-9', 'status' => 'sent']);

        $saved = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array|object $object = []) use (&$saved): array {
                $saved = (array) $object;
                return $saved;
            }
        );

        $processed = $this->service->dispatchQueuedMessages();

        $this->assertSame(1, $processed);
        $this->assertSame('sent', $saved['deliveryStatus']);
        $this->assertSame('bbk-9', $saved['logiusMessageId']);
    }//end testDispatchDeliversViaLogius()

    /**
     * Dispatch with no mailbox and a known email falls back to email.
     *
     * @return void
     */
    public function testDispatchFallsBackToEmailWhenNoMailbox(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [['@self' => ['id' => 'msg-2'], 'bsn' => 'cipher', 'body' => '<p>Body</p>', 'fallbackEmail' => 'b@example.nl']]
        );
        $this->objectService->method('find')->willReturn(['@self' => ['id' => 'msg-2'], 'fallbackEmail' => 'b@example.nl']);
        $this->deps['mailbox']->method('resolve')->willReturn(['mailboxAvailable' => false, 'optedOut' => false, 'cached' => false]);
        $this->deps['logius']->expects($this->never())->method('sendMessage');
        $this->deps['email']->expects($this->once())->method('send');

        $saved = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array|object $object = []) use (&$saved): array {
                $saved = (array) $object;
                return $saved;
            }
        );

        $this->service->dispatchQueuedMessages();

        $this->assertSame('fallback-emailed', $saved['deliveryStatus']);
        $this->assertSame('b@example.nl', $saved['fallbackEmail']);
    }//end testDispatchFallsBackToEmailWhenNoMailbox()

    /**
     * An opted-out citizen is treated as no-mailbox and falls back to email.
     *
     * @return void
     */
    public function testDispatchOptedOutFallsBack(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [['@self' => ['id' => 'msg-3'], 'bsn' => 'cipher', 'body' => '<p>B</p>', 'fallbackEmail' => 'b@example.nl']]
        );
        $this->objectService->method('find')->willReturn(['@self' => ['id' => 'msg-3'], 'fallbackEmail' => 'b@example.nl']);
        $this->deps['mailbox']->method('resolve')->willReturn(['mailboxAvailable' => true, 'optedOut' => true, 'cached' => false]);
        $this->deps['logius']->expects($this->never())->method('sendMessage');
        $this->deps['email']->expects($this->once())->method('send');
        $this->objectService->method('saveObject')->willReturn([]);

        $this->service->dispatchQueuedMessages();
        $this->addToAssertionCount(1);
    }//end testDispatchOptedOutFallsBack()

    /**
     * A Logius send failure re-queues the message with a backoff and audits "failed".
     *
     * @return void
     */
    public function testDispatchFailureSchedulesRetry(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [['@self' => ['id' => 'msg-4'], 'bsn' => 'cipher', 'body' => '<p>B</p>', 'retryCount' => 0]]
        );
        $this->objectService->method('find')->willReturn(['@self' => ['id' => 'msg-4'], 'retryCount' => 0]);
        $this->deps['mailbox']->method('resolve')->willReturn(['mailboxAvailable' => true, 'optedOut' => false, 'cached' => false]);
        $this->deps['logius']->method('sendMessage')->willThrowException(new LogiusApiException('boom', 'server'));
        $this->deps['audit']->expects($this->atLeastOnce())->method('log');

        $saved = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array|object $object = []) use (&$saved): array {
                $saved = (array) $object;
                return $saved;
            }
        );

        $this->service->dispatchQueuedMessages();

        $this->assertSame('queued', $saved['deliveryStatus']);
        $this->assertSame(1, $saved['retryCount']);
        $this->assertNotEmpty($saved['nextRetryAt']);
    }//end testDispatchFailureSchedulesRetry()

    /**
     * A read receipt sets readAt and transitions the status to "read".
     *
     * @return void
     */
    public function testHandleReadReceipt(): void
    {
        $this->objectService->method('findAll')->willReturn([['@self' => ['id' => 'msg-5'], 'body' => '<p>B</p>']]);
        $this->objectService->method('find')->willReturn(['@self' => ['id' => 'msg-5']]);

        $saved = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array|object $object = []) use (&$saved): array {
                $saved = (array) $object;
                return $saved;
            }
        );

        $this->service->handleReadReceipt(logiusMessageId: 'bbk-9', readAt: '2026-06-01T12:00:00Z');

        $this->assertSame('read', $saved['deliveryStatus']);
        $this->assertSame('2026-06-01T12:00:00Z', $saved['readAt']);
    }//end testHandleReadReceipt()

    /**
     * An inbound reply creates a Contactmoment scoped to the parent zaak.
     *
     * @return void
     */
    public function testHandleInboundReplyCreatesContactmoment(): void
    {
        $callIndex = 0;
        $this->objectService->method('find')->willReturnCallback(
            static function () use (&$callIndex): array {
                $callIndex++;
                return ['@self' => ['id' => 'parent-1'], 'subject' => 'Origineel', 'zaakId' => 'Z-9', 'bsn' => 'cipher'];
            }
        );

        $created = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            static function (array|object $object = []) use (&$created): array {
                $arr = (array) $object;
                if (isset($arr['channel']) === true) {
                    $arr['@self'] = ['id' => 'cm-new'];
                } else if (isset($arr['parentMessageId']) === true) {
                    $arr['@self'] = ['id' => 'reply-1'];
                }

                $created[] = $arr;
                return $arr;
            }
        );

        $reply = $this->service->handleInboundReply(
            parentMessageId: 'parent-1',
            logiusReplyId: 'rep-1',
            bodyText: 'Mijn antwoord'
        );

        $contactmoment = null;
        foreach ($created as $row) {
            if (($row['channel'] ?? '') === 'berichtenbox') {
                $contactmoment = $row;
            }
        }

        $this->assertNotNull($contactmoment, 'A berichtenbox contactmoment must be created');
        $this->assertSame('Re: Origineel', $contactmoment['subject']);
        $this->assertSame('Z-9', $contactmoment['channelMetadata']['zaakId']);
        $this->assertSame('cm-new', $reply['createdContactmomentId']);
    }//end testHandleInboundReplyCreatesContactmoment()

    /**
     * An unknown parent message makes inbound-reply handling throw.
     *
     * @return void
     */
    public function testHandleInboundReplyUnknownParentThrows(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $this->expectException(BerichtenboxException::class);
        $this->service->handleInboundReply(parentMessageId: 'nope', logiusReplyId: 'r', bodyText: 'x');
    }//end testHandleInboundReplyUnknownParentThrows()

    /**
     * The 5-working-day fallback emails a sent-but-unread message.
     *
     * @return void
     */
    public function testProcessFallbackQueueEmailsAfterFiveWorkingDays(): void
    {
        $sentAt = (new \DateTimeImmutable('-20 days'))->format(\DateTimeInterface::ATOM);
        $this->objectService->method('findAll')->willReturn(
            [['@self' => ['id' => 'msg-6'], 'body' => '<p>B</p>', 'sentToBerichtenboxAt' => $sentAt, 'readAt' => '', 'fallbackEmail' => 'b@example.nl']]
        );
        $this->objectService->method('find')->willReturn(['@self' => ['id' => 'msg-6'], 'fallbackEmail' => 'b@example.nl']);
        $this->deps['email']->expects($this->once())->method('send');
        $this->objectService->method('saveObject')->willReturn([]);

        $count = $this->service->processFallbackQueue();

        $this->assertSame(1, $count);
    }//end testProcessFallbackQueueEmailsAfterFiveWorkingDays()

    /**
     * A read message is not subject to the 5-day fallback.
     *
     * @return void
     */
    public function testProcessFallbackQueueSkipsReadMessages(): void
    {
        $sentAt = (new \DateTimeImmutable('-20 days'))->format(\DateTimeInterface::ATOM);
        $this->objectService->method('findAll')->willReturn(
            [['@self' => ['id' => 'msg-7'], 'sentToBerichtenboxAt' => $sentAt, 'readAt' => '2026-05-01T00:00:00Z']]
        );
        $this->deps['email']->expects($this->never())->method('send');

        $count = $this->service->processFallbackQueue();

        $this->assertSame(0, $count);
    }//end testProcessFallbackQueueSkipsReadMessages()

    /**
     * Crypto-shred overwrites BSN material across all schemas.
     *
     * @return void
     */
    public function testCryptoShredOverwritesBsn(): void
    {
        $this->objectService->method('findAll')->willReturn([['@self' => ['id' => 'r-1']]]);
        $this->objectService->method('find')->willReturn(['@self' => ['id' => 'r-1']]);

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            static function (array|object $object = []) use (&$saved): array {
                $saved[] = (array) $object;
                return (array) $object;
            }
        );

        $count = $this->service->cryptoShred(self::VALID_BSN);

        $this->assertSame(3, $count);
        $this->assertSame('shredded', $saved[0]['bsn']);
    }//end testCryptoShredOverwritesBsn()
}//end class
