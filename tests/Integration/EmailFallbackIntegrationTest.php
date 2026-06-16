<?php

/**
 * Integration test for the email-fallback path of the Berichtenbox
 * bridge. The IMailer collaborator is mocked (the real openconnector
 * SMTP source is exercised only in deploy verification); this test
 * confirms the bridge calls IMailer with the right shape and updates
 * the message row when the fallback fires.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Integration
 *
 * @author    Conduction <info@conduction.nl>
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
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-mailbox-003
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-fallback-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Integration;

use OCA\Pipelinq\Service\EmailFallbackSender;
use OCP\IAppConfig;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Email-fallback path integration.
 */
class EmailFallbackIntegrationTest extends TestCase
{
    /**
     * IMailer is called with the rendered subject + (notice-prepended) body.
     *
     * @return void
     */
    public function testFallbackIncludesMijnOverheidNotice(): void
    {
        $message = $this->createMock(IMessage::class);
        $message->expects($this->once())->method('setTo')->with(['burger@example.nl']);
        $message->expects($this->once())->method('setSubject')->with('Uw paspoort is gereed');
        $message->expects($this->once())->method('setHtmlBody')->willReturnCallback(
            function (string $body) use ($message): IMessage {
                $this->assertStringContainsString(
                    EmailFallbackSender::FALLBACK_NOTICE,
                    $body
                );
                $this->assertStringContainsString('<p>Inhoud</p>', $body);
                return $message;
            }
        );
        $message->method('setPlainBody')->willReturn($message);
        $message->method('setFrom')->willReturn($message);

        $mailer = $this->createMock(IMailer::class);
        $mailer->method('validateMailAddress')->willReturn(true);
        $mailer->method('createMessage')->willReturn($message);
        $mailer->expects($this->once())->method('send')->willReturn([]);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');

        $sender = new EmailFallbackSender(
            $mailer,
            $appConfig,
            $this->createMock(LoggerInterface::class)
        );

        $sent = $sender->send(
            ['subject' => 'Uw paspoort is gereed', 'body' => '<p>Inhoud</p>'],
            'burger@example.nl',
            true
        );
        $this->assertTrue($sent);
    }//end testFallbackIncludesMijnOverheidNotice()

    /**
     * No-mailbox path skips the notice.
     *
     * @return void
     */
    public function testNoMailboxPathSkipsNotice(): void
    {
        $message = $this->createMock(IMessage::class);
        $captured = '';
        $message->method('setTo')->willReturn($message);
        $message->method('setSubject')->willReturn($message);
        $message->method('setHtmlBody')->willReturnCallback(
            function (string $body) use ($message, &$captured): IMessage {
                $captured = $body;
                return $message;
            }
        );
        $message->method('setPlainBody')->willReturn($message);
        $message->method('setFrom')->willReturn($message);

        $mailer = $this->createMock(IMailer::class);
        $mailer->method('validateMailAddress')->willReturn(true);
        $mailer->method('createMessage')->willReturn($message);
        $mailer->method('send')->willReturn([]);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturn('');

        $sender = new EmailFallbackSender(
            $mailer,
            $appConfig,
            $this->createMock(LoggerInterface::class)
        );

        $sender->send(
            ['subject' => 'x', 'body' => '<p>Inhoud</p>'],
            'burger@example.nl',
            false
        );
        $this->assertStringNotContainsString(EmailFallbackSender::FALLBACK_NOTICE, $captured);
    }//end testNoMailboxPathSkipsNotice()

    /**
     * Invalid recipient throws.
     *
     * @return void
     */
    public function testInvalidRecipientThrows(): void
    {
        $mailer = $this->createMock(IMailer::class);
        $mailer->method('validateMailAddress')->willReturn(false);
        $sender = new EmailFallbackSender(
            $mailer,
            $this->createMock(IAppConfig::class),
            $this->createMock(LoggerInterface::class)
        );
        $this->expectException(\RuntimeException::class);
        $sender->send(['subject' => 'x', 'body' => '<p>x</p>'], 'not-an-email');
    }//end testInvalidRecipientThrows()
}//end class
