<?php

/**
 * Unit tests for InstanceMailerTransport.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing\Transport
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-header-injection-on-the-instance-mailer-degrades-soft
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing\Transport;

use OCA\Pipelinq\Service\Marketing\Transport\InstanceMailerTransport;
use OCA\Pipelinq\Service\Marketing\Transport\MailAddressing;
use OCA\Pipelinq\Service\Marketing\Transport\MailContent;
use OCA\Pipelinq\Service\Marketing\Transport\RenderedMail;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for InstanceMailerTransport — envelope building and the guarded
 * private-API header-injection path.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-header-injection-on-the-instance-mailer-degrades-soft
 */
class InstanceMailerTransportTest extends TestCase {
	private IMailer $mailer;
	private LoggerInterface $logger;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mailer = $this->createMock(IMailer::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build a fluent IMessage mock whose setters return itself.
	 *
	 * @return IMessage
	 */
	private function fluentMessage(): IMessage {
		$message = $this->createMock(IMessage::class);
		$message->method('setFrom')->willReturn($message);
		$message->method('setTo')->willReturn($message);
		$message->method('setReplyTo')->willReturn($message);
		$message->method('setSubject')->willReturn($message);
		$message->method('setHtmlBody')->willReturn($message);
		$message->method('setPlainBody')->willReturn($message);
		return $message;
	}//end fluentMessage()

	/**
	 * A plain send (no extra headers) sets envelope fields and reports
	 * accepted when IMailer::send returns no failed recipients.
	 *
	 * @return void
	 */
	public function testSendAcceptsWithNoExtraHeaders(): void {
		$message = $this->fluentMessage();
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->expects($this->once())->method('send')->with($message)->willReturn([]);

		$transport = new InstanceMailerTransport($this->mailer, $this->logger);
		$mail = new RenderedMail(
			addressing: new MailAddressing(
				fromEmail: 'noreply@example.test',
				fromName: 'Pipelinq',
				replyTo: '',
				toEmail: 'user@example.test',
			),
			content: new MailContent(
				subject: 'Hi',
				html: '<p>hi</p>',
				text: 'hi',
			),
			headers: [],
			deliveryId: 'd-1',
		);

		$result = $transport->send($mail);

		$this->assertTrue($result->accepted);
	}//end testSendAcceptsWithNoExtraHeaders()

	/**
	 * A rejected recipient (non-empty failedRecipients) reports a failure.
	 *
	 * @return void
	 */
	public function testSendFailsWhenRecipientRejected(): void {
		$message = $this->fluentMessage();
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('send')->willReturn(['user@example.test']);

		$transport = new InstanceMailerTransport($this->mailer, $this->logger);
		$mail = new RenderedMail(
			addressing: new MailAddressing(
				fromEmail: 'noreply@example.test',
				fromName: 'Pipelinq',
				replyTo: '',
				toEmail: 'user@example.test',
			),
			content: new MailContent(
				subject: 'Hi',
				html: '<p>hi</p>',
				text: 'hi',
			),
			headers: [],
			deliveryId: 'd-2',
		);

		$result = $transport->send($mail);

		$this->assertFalse($result->accepted);
	}//end testSendFailsWhenRecipientRejected()

	/**
	 * When the underlying IMessage exposes `getSymfonyEmail()`, extra
	 * headers are set on the returned object's header bag via
	 * `addTextHeader()` — using a lightweight fake rather than the real
	 * `symfony/mime` package (not a pipelinq runtime dependency; the real
	 * class is only present inside a full Nextcloud server tree). The
	 * adapter is duck-typed against this shape by design (see its docblock).
	 *
	 * @return void
	 */
	public function testHeaderInjectionUsesGetSymfonyEmailWhenAvailable(): void {
		$headerBag = new FakeSymfonyHeaderBag();
		$symfonyEmail = new FakeSymfonyEmail($headerBag);

		$message = $this->getMockBuilder(FakeMessageWithSymfonyEmail::class)
			->setConstructorArgs([$symfonyEmail])
			->getMock();
		$message->method('setFrom')->willReturn($message);
		$message->method('setTo')->willReturn($message);
		$message->method('setReplyTo')->willReturn($message);
		$message->method('setSubject')->willReturn($message);
		$message->method('setHtmlBody')->willReturn($message);
		$message->method('setPlainBody')->willReturn($message);
		$message->method('getSymfonyEmail')->willReturn($symfonyEmail);

		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->method('send')->willReturn([]);

		$transport = new InstanceMailerTransport($this->mailer, $this->logger);
		$mail = new RenderedMail(
			addressing: new MailAddressing(
				fromEmail: 'noreply@example.test',
				fromName: 'Pipelinq',
				replyTo: '',
				toEmail: 'user@example.test',
			),
			content: new MailContent(
				subject: 'Hi',
				html: '<p>hi</p>',
				text: 'hi',
			),
			headers: ['X-Test-Header' => 'test-value'],
			deliveryId: 'd-3',
		);

		$result = $transport->send($mail);

		$this->assertTrue($result->accepted);
		$this->assertSame('test-value', $headerBag->headers['X-Test-Header'] ?? null);
	}//end testHeaderInjectionUsesGetSymfonyEmailWhenAvailable()

	/**
	 * When the underlying IMessage does NOT expose `getSymfonyEmail()`
	 * (the guard fails), the message is still sent — without the extra
	 * headers — and nothing throws.
	 *
	 * @return void
	 */
	public function testHeaderInjectionDegradesSoftWhenGuardUnavailable(): void {
		$message = $this->fluentMessage();
		$this->mailer->method('createMessage')->willReturn($message);
		$this->mailer->expects($this->once())->method('send')->willReturn([]);

		$transport = new InstanceMailerTransport($this->mailer, $this->logger);
		$mail = new RenderedMail(
			addressing: new MailAddressing(
				fromEmail: 'noreply@example.test',
				fromName: 'Pipelinq',
				replyTo: '',
				toEmail: 'user@example.test',
			),
			content: new MailContent(
				subject: 'Hi',
				html: '<p>hi</p>',
				text: 'hi',
			),
			headers: ['List-Unsubscribe' => '<mailto:unsub@example.test>'],
			deliveryId: 'd-4',
		);

		$result = $transport->send($mail);

		$this->assertTrue($result->accepted, 'the send must succeed even though the header guard is unavailable');
	}//end testHeaderInjectionDegradesSoftWhenGuardUnavailable()

	/**
	 * A rendered mail with the given addressing, content and headers.
	 *
	 * @param array<string, string> $parts   fromName, replyTo, html, text overrides.
	 * @param array<string, string> $headers The extra headers.
	 *
	 * @return RenderedMail
	 */
	private function mailWith(array $parts, array $headers): RenderedMail {
		return new RenderedMail(
			addressing: new MailAddressing(
				fromEmail: 'noreply@example.test',
				fromName: ($parts['fromName'] ?? ''),
				replyTo: ($parts['replyTo'] ?? ''),
				toEmail: 'user@example.test',
			),
			content: new MailContent(subject: 'Hi', html: ($parts['html'] ?? ''), text: ($parts['text'] ?? '')),
			headers: $headers,
			deliveryId: 'd-9',
		);
	}//end mailWith()

	/**
	 * Every way a send ends: no message, a failed send and a rejected
	 * recipient each answer their own error with their own warning; the
	 * envelope sets only what the mail carries; the unsubscribe pair goes
	 * to the shared helper with the bare URL and never as a text header; a
	 * helper that does not set it, or no helper, is logged; a failing
	 * header injection is logged and the mail still goes.
	 *
	 * @return void
	 */
	public function testEveryWayASendEnds(): void {
		$this->mailer->method('createMessage')->willThrowException(new \RuntimeException('down'));
		$this->logger->expects($this->once())->method('warning')->with('InstanceMailerTransport.send: createMessage failed', ['exception' => 'down']);
		$result = (new InstanceMailerTransport($this->mailer, $this->logger))->send($this->mailWith([], []));
		$this->assertSame([false, 'instance-mailer-unavailable'], [$result->accepted, $result->error]);

		$mailer = $this->createMock(IMailer::class);
		$message = $this->fluentMessage();
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willThrowException(new \RuntimeException('smtp'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('InstanceMailerTransport.send: send failed', ['deliveryId' => 'd-9', 'exception' => 'smtp']);
		$result = (new InstanceMailerTransport($mailer, $logger))->send($this->mailWith([], []));
		$this->assertSame([false, 'instance-mailer-send-failed'], [$result->accepted, $result->error]);

		$mailer = $this->createMock(IMailer::class);
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setFrom')->with(['noreply@example.test']);
		$message->expects($this->once())->method('setTo')->with(['user@example.test']);
		$message->expects($this->once())->method('setReplyTo')->with(['reply@example.test']);
		$message->expects($this->once())->method('setSubject')->with('Hi');
		$message->expects($this->never())->method('setHtmlBody');
		$message->expects($this->once())->method('setPlainBody')->with('hi');
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturn(['user@example.test']);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('InstanceMailerTransport.send: provider rejected recipient(s)', ['deliveryId' => 'd-9', 'failedRecipients' => ['user@example.test']]);
		$logger->expects($this->once())->method('info')->with('InstanceMailerTransport.applyHeaders: List-Unsubscribe not set; the body link remains');
		$result = (new InstanceMailerTransport($mailer, $logger))->send(
			$this->mailWith(['replyTo' => 'reply@example.test', 'text' => 'hi'], ['List-Unsubscribe' => ' <https://u.test/x> ', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'])
		);
		$this->assertSame([false, 'recipient-rejected'], [$result->accepted, $result->error]);

		$headerBag = new FakeSymfonyHeaderBag();
		$message = $this->getMockBuilder(FakeMessageWithSymfonyEmail::class)->setConstructorArgs([new FakeSymfonyEmail($headerBag)])->getMock();
		$message->method('getSymfonyEmail')->willReturn(new FakeSymfonyEmail($headerBag));
		$message->expects($this->once())->method('setFrom')->with(['noreply@example.test' => 'Pipelinq']);
		$message->expects($this->once())->method('setHtmlBody')->with('<p>hi</p>');
		$message->expects($this->never())->method('setReplyTo');
		$message->expects($this->never())->method('setPlainBody');
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturn([]);
		$unsubscribe = $this->createMock(\OCA\Pipelinq\Service\UnsubscribeMail::class);
		$unsubscribe->expects($this->once())->method('applyHeaders')->with($message, ['oneClickUrl' => 'https://u.test/x'])->willReturn(true);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		$result = (new InstanceMailerTransport($mailer, $logger, $unsubscribe))->send(
			$this->mailWith(['fromName' => 'Pipelinq', 'html' => '<p>hi</p>'], ['List-Unsubscribe' => '<https://u.test/x>', 'List-Unsubscribe-Post' => 'x', 'X-Campaign' => 'c-1'])
		);
		$this->assertTrue($result->accepted);
		$this->assertSame(['X-Campaign' => 'c-1'], $headerBag->headers);

		$message = $this->getMockBuilder(FakeMessageWithSymfonyEmail::class)->setConstructorArgs([new FakeSymfonyEmail($headerBag)])->getMock();
		$message->expects($this->never())->method('getSymfonyEmail');
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturn([]);
		$unsubscribe = $this->createMock(\OCA\Pipelinq\Service\UnsubscribeMail::class);
		$unsubscribe->expects($this->never())->method('applyHeaders');
		$this->assertTrue((new InstanceMailerTransport($mailer, $this->createMock(LoggerInterface::class), $unsubscribe))->send($this->mailWith([], ['List-Unsubscribe' => '<>', 'List-Unsubscribe-Post' => 'x']))->accepted);

		$message = $this->getMockBuilder(FakeMessageWithSymfonyEmail::class)->setConstructorArgs([new FakeSymfonyEmail($headerBag)])->getMock();
		$message->method('getSymfonyEmail')->willThrowException(new \RuntimeException('no mime'));
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send')->willReturn([]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('InstanceMailerTransport.applyHeaders: header injection failed — sending without extra headers', ['exception' => 'no mime']);
		$this->assertTrue((new InstanceMailerTransport($mailer, $logger))->send($this->mailWith([], ['X-Campaign' => 'c-1']))->accepted);

		$message = $this->fluentMessage();
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('send')->willReturn([]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('InstanceMailerTransport.applyHeaders: getSymfonyEmail() unavailable — sending without extra headers', ['headerNames' => ['X-Campaign']]);
		$this->assertTrue((new InstanceMailerTransport($mailer, $logger))->send($this->mailWith([], ['X-Campaign' => 'c-1']))->accepted);
	}//end testEveryWayASendEnds()
}//end class

/**
 * A minimal fake mirroring `\OC\Mail\Message`'s shape: implements the
 * public `IMessage` contract AND additionally exposes `getSymfonyEmail()`,
 * so `method_exists($message, 'getSymfonyEmail')` is true — used only to
 * exercise the header-injection guard's TRUE branch without depending on
 * the real Nextcloud server tree in the unit suite.
 */
abstract class FakeMessageWithSymfonyEmail implements IMessage {
	public function __construct(private FakeSymfonyEmail $symfonyEmail) {
	}//end __construct()

	public function getSymfonyEmail(): FakeSymfonyEmail {
		return $this->symfonyEmail;
	}//end getSymfonyEmail()
}//end class

/**
 * Fake standing in for `Symfony\Component\Mime\Email` — exposes only the
 * one method `InstanceMailerTransport` actually calls, `getHeaders()`.
 */
class FakeSymfonyEmail {
	public function __construct(private FakeSymfonyHeaderBag $headers) {
	}//end __construct()

	public function getHeaders(): FakeSymfonyHeaderBag {
		return $this->headers;
	}//end getHeaders()
}//end class

/**
 * Fake standing in for `Symfony\Component\Mime\Header\Headers` — exposes
 * only `addTextHeader()`, recording what was set so the test can assert on it.
 */
class FakeSymfonyHeaderBag {
	/** @var array<string, string> */
	public array $headers = [];

	public function addTextHeader(string $name, string $value): void {
		$this->headers[$name] = $value;
	}//end addTextHeader()
}//end class
