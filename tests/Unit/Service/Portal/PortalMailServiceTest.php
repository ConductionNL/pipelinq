<?php

/**
 * Unit tests for PortalMailService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Portal
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Portal;

use OCA\Pipelinq\Service\Portal\PortalMailService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Plain-text portal mail: address validation, delivery result, failure
 * logging and token-link composition.
 *
 * @covers \OCA\Pipelinq\Service\Portal\PortalMailService
 */
class PortalMailServiceTest extends TestCase {
	/**
	 * Mailer mock.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer&MockObject $mailer;

	/**
	 * URL generator mock.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urls;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Message mock.
	 *
	 * @var IMessage&MockObject
	 */
	private IMessage&MockObject $message;

	/**
	 * Service under test.
	 *
	 * @var PortalMailService
	 */
	private PortalMailService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mailer  = $this->createMock(IMailer::class);
		$this->urls    = $this->createMock(IURLGenerator::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->message = $this->createMock(IMessage::class);
		$this->mailer->method('createMessage')->willReturn($this->message);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => '[' . $text . ']');

		$this->service = new PortalMailService($this->mailer, $this->urls, $l10n, $this->logger);
	}//end setUp()

	/**
	 * An invalid address is refused without building a message.
	 *
	 * @return void
	 */
	public function testSendRefusesInvalidAddress(): void {
		$this->mailer->expects($this->once())->method('validateMailAddress')->with('nope')->willReturn(false);
		$this->mailer->expects($this->never())->method('createMessage');

		$this->assertFalse($this->service->send('nope', 'S', 'B'));
	}//end testSendRefusesInvalidAddress()

	/**
	 * Delivery succeeds only when the mailer reports no failed recipients.
	 *
	 * @return void
	 */
	public function testSendReportsMailerResult(): void {
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->message->expects($this->exactly(2))->method('setTo')->with(['a@b.test']);
		$this->message->expects($this->exactly(2))->method('setSubject')->with('Subject');
		$this->message->expects($this->exactly(2))->method('setPlainBody')->with('Body');
		$this->mailer->expects($this->exactly(2))->method('send')->with($this->message)
			->willReturnOnConsecutiveCalls([], ['a@b.test']);

		$this->assertTrue($this->service->send('a@b.test', 'Subject', 'Body'));
		$this->assertFalse($this->service->send('a@b.test', 'Subject', 'Body'));
	}//end testSendReportsMailerResult()

	/**
	 * A mailer exception is logged as a warning and reported as false.
	 *
	 * @return void
	 */
	public function testSendLogsFailure(): void {
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->mailer->method('send')->willThrowException(new RuntimeException('smtp down'));
		$this->logger->expects($this->once())->method('warning')
			->with('Pipelinq portal: mail send failed', ['exception' => 'smtp down']);

		$this->assertFalse($this->service->send('a@b.test', 'S', 'B'));
	}//end testSendLogsFailure()

	/**
	 * A token link is the absolute route plus the url-encoded token, after the intro.
	 *
	 * @return void
	 */
	public function testSendTokenLink(): void {
		$this->urls->expects($this->once())->method('getAbsoluteURL')->with('/portal/reset')
			->willReturn('https://cloud.test/portal/reset');
		$this->mailer->method('validateMailAddress')->willReturn(true);
		$this->message->expects($this->once())->method('setSubject')->with('Reset');
		$this->message->expects($this->once())->method('setPlainBody')->with(
			"Click below.\n\nhttps://cloud.test/portal/reset?token=a%2Bb%2Fc%3D\n\n[If you did not request this, you can ignore this email.]"
		);
		$this->mailer->method('send')->willReturn([]);

		$this->assertTrue($this->service->sendTokenLink('a@b.test', '/portal/reset', 'a+b/c=', 'Reset', 'Click below.'));
	}//end testSendTokenLink()
}//end class
