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
			fromEmail: 'noreply@example.test',
			fromName: 'Pipelinq',
			replyTo: '',
			toEmail: 'user@example.test',
			subject: 'Hi',
			html: '<p>hi</p>',
			text: 'hi',
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
		$mail = new RenderedMail('noreply@example.test', 'Pipelinq', '', 'user@example.test', 'Hi', '<p>hi</p>', 'hi', [], 'd-2');

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
			'noreply@example.test',
			'Pipelinq',
			'',
			'user@example.test',
			'Hi',
			'<p>hi</p>',
			'hi',
			['X-Test-Header' => 'test-value'],
			'd-3',
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
			'noreply@example.test',
			'Pipelinq',
			'',
			'user@example.test',
			'Hi',
			'<p>hi</p>',
			'hi',
			['List-Unsubscribe' => '<mailto:unsub@example.test>'],
			'd-4',
		);

		$result = $transport->send($mail);

		$this->assertTrue($result->accepted, 'the send must succeed even though the header guard is unavailable');
	}//end testHeaderInjectionDegradesSoftWhenGuardUnavailable()
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
