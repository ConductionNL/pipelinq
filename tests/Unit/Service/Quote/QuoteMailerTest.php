<?php

/**
 * Unit tests for QuoteMailer.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Quote
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/products-quote-from-lead/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Quote;

use OCA\Pipelinq\Service\Quote\QuoteMailer;
use OCA\Pipelinq\Service\Quote\SentQuote;
use OCP\IL10N;
use OCP\Mail\IAttachment;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The quote's mail: addressee, subject, body and the PDF attached.
 *
 * @spec openspec/changes/products-quote-from-lead/specs/product-catalog-quoting/spec.md#requirement-a-quote-is-sent-as-a-pdf-and-logged-req-qslice-002
 */
class QuoteMailerTest extends TestCase {

	/**
	 * The mailer double.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer $mailer;

	/**
	 * The message double.
	 *
	 * @var IMessage&MockObject
	 */
	private IMessage $message;

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * A mailer whose message records what it is given.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->message = $this->createMock(originalClassName: IMessage::class);
		$this->mailer  = $this->createMock(originalClassName: IMailer::class);
		$this->mailer->method('createMessage')->willReturn($this->message);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
	}//end setUp()

	/**
	 * The mailer under test.
	 *
	 * @return QuoteMailer
	 */
	private function quoteMailer(): QuoteMailer {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);

		return new QuoteMailer(mailer: $this->mailer, l10n: $l10n, logger: $this->logger);
	}//end quoteMailer()

	/**
	 * The quote OFF-2026-0042.
	 *
	 * @return SentQuote
	 */
	private static function quote(): SentQuote {
		return new SentQuote(
			number: 'OFF-2026-0042',
			validUntil: '2026-12-17',
			totalInclVat: 1755.0,
			content: '%PDF-1.7 quote',
			filename: 'OFF-2026-0042 Nieuw kantoor.pdf',
			fileId: 4711,
			path: '/Pipelinq/OFF-2026-0042 Nieuw kantoor.pdf'
		);
	}//end quote()

	/**
	 * The contact gets a mail named after the quote with the PDF attached.
	 *
	 * @return void
	 */
	public function testMailsThePdfToTheRecipient(): void {
		$attachment = $this->createMock(originalClassName: IAttachment::class);
		$this->mailer->expects($this->once())->method('createAttachment')
			->with('%PDF-1.7 quote', 'OFF-2026-0042 Nieuw kantoor.pdf', 'application/pdf')
			->willReturn($attachment);
		$this->message->expects($this->once())->method('setTo')->with(['kees@degraaf.example' => 'Kees Bakker']);
		$this->message->expects($this->once())->method('setSubject')->with('Quote OFF-2026-0042');
		$this->message->expects($this->once())->method('setPlainBody')
			->with('Please find attached quote OFF-2026-0042, for a total of EUR 1755.00 including VAT. It is valid until 2026-12-17.');
		$this->message->expects($this->once())->method('attach')->with($attachment);
		$this->mailer->expects($this->once())->method('send')->with($this->message)->willReturn([]);

		$sent = $this->quoteMailer()->send(recipient: ['email' => 'kees@degraaf.example', 'name' => 'Kees Bakker'], quote: self::quote());

		$this->assertTrue(condition: $sent);
	}//end testMailsThePdfToTheRecipient()

	/**
	 * A recipient without a name is addressed by email alone.
	 *
	 * @return void
	 */
	public function testANamelessRecipientIsAddressedByEmail(): void {
		$this->mailer->method('send')->willReturn([]);
		$this->message->expects($this->once())->method('setTo')->with(['info@degraaf.example']);

		$this->assertTrue(condition: $this->quoteMailer()->send(recipient: ['email' => 'info@degraaf.example', 'name' => ''], quote: self::quote()));
	}//end testANamelessRecipientIsAddressedByEmail()

	/**
	 * A recipient the mailer refuses is a failed send.
	 *
	 * @return void
	 */
	public function testARefusedRecipientIsAFailure(): void {
		$this->mailer->method('send')->willReturn(['kees@degraaf.example']);

		$this->assertFalse(condition: $this->quoteMailer()->send(recipient: ['email' => 'kees@degraaf.example', 'name' => 'Kees'], quote: self::quote()));
	}//end testARefusedRecipientIsAFailure()

	/**
	 * A mailer that throws is a failed send, and it is logged.
	 *
	 * @return void
	 */
	public function testAThrowingMailerIsLoggedAndAFailure(): void {
		$this->mailer->method('send')->willThrowException(new RuntimeException('SMTP down'));
		$this->logger->expects($this->once())->method('error')->with(
			'Pipelinq: a quote was made but its mail did not go out',
			$this->callback(callback: static fn (array $context): bool => $context['quote'] === 'OFF-2026-0042' && $context['exception'] === 'SMTP down')
		);

		$this->assertFalse(condition: $this->quoteMailer()->send(recipient: ['email' => 'kees@degraaf.example', 'name' => 'Kees'], quote: self::quote()));
	}//end testAThrowingMailerIsLoggedAndAFailure()
}//end class
