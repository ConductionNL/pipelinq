<?php

/**
 * Unit tests for ConfirmationMail.
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
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ConfirmationMail;
use OCA\Pipelinq\Service\ListSignupRun;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * ConfirmationMail sends the double opt-in mail of a list signup.
 */
class ConfirmationMailTest extends TestCase {

	/**
	 * A signup for a list with sender settings.
	 *
	 * @param array<string, mixed> $list The list.
	 *
	 * @return ListSignupRun
	 */
	private static function signup(array $list): ListSignupRun {
		$run = new ListSignupRun(listId: 'l-1', email: 'anna@example.nl');
		$run->list = $list;

		return $run;
	}//end signup()

	/**
	 * A translator that fills the placeholders.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters=[]): string => vsprintf($text, $parameters));

		return $l10n;
	}//end l10n()

	/**
	 * The mail goes to the subscriber with the list name, link and footer, from the list's sender.
	 *
	 * @return void
	 */
	public function testTheMailIsSent(): void {
		$message = $this->createMock(IMessage::class);
		$message->expects(self::once())->method('setTo')->with(['anna@example.nl']);
		$message->expects(self::once())->method('setSubject')->with('Confirm your subscription to Nieuwsbrief');
		$message->expects(self::once())->method('setPlainBody')->with(
			self::callback(
				static fn (string $body): bool => str_starts_with($body, 'You asked to receive Nieuwsbrief.')
					&& str_contains($body, 'https://crm.example/confirm/abc')
					&& str_ends_with($body, 'Stadhuisplein 1, Zuiddrecht')
			)
		);
		$message->expects(self::once())->method('setFrom')->with(['news@zuiddrecht.nl' => 'Gemeente Zuiddrecht']);
		$message->expects(self::once())->method('setReplyTo')->with(['info@zuiddrecht.nl']);
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->expects(self::once())->method('send')->with($message);

		$error = (new ConfirmationMail(mailer: $mailer, l10n: $this->l10n()))->send(
			run: self::signup(['name' => 'Nieuwsbrief', 'footerAddress' => 'Stadhuisplein 1, Zuiddrecht', 'senderEmail' => ' news@zuiddrecht.nl ', 'senderName' => 'Gemeente Zuiddrecht', 'replyTo' => 'info@zuiddrecht.nl']),
			confirmUrl: 'https://crm.example/confirm/abc'
		);

		self::assertNull($error);
	}//end testTheMailIsSent()

	/**
	 * An invalid or missing sender leaves the server's sender; a sender without name names itself.
	 *
	 * @return void
	 */
	public function testSenderAddressesAreValidated(): void {
		$message = $this->createMock(IMessage::class);
		$message->expects(self::never())->method('setFrom');
		$message->expects(self::never())->method('setReplyTo');
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('validateMailAddress')->willReturn(false);

		self::assertNull(
			(new ConfirmationMail(mailer: $mailer, l10n: $this->l10n()))->send(run: self::signup(['name' => 'L', 'senderEmail' => 'not-an-address', 'replyTo' => 'also-not']), confirmUrl: 'u')
		);

		$named = $this->createMock(IMessage::class);
		$named->expects(self::once())->method('setFrom')->with(['news@example.nl' => 'news@example.nl']);
		$valid = $this->createMock(IMailer::class);
		$valid->method('createMessage')->willReturn($named);
		$valid->method('validateMailAddress')->willReturn(true);
		(new ConfirmationMail(mailer: $valid, l10n: $this->l10n()))->send(run: self::signup(['senderEmail' => 'news@example.nl']), confirmUrl: 'u');
	}//end testSenderAddressesAreValidated()

	/**
	 * A send failure is answered with its message.
	 *
	 * @return void
	 */
	public function testASendFailureIsReturned(): void {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$mailer->method('send')->willThrowException(new RuntimeException('SMTP refused'));

		self::assertSame('SMTP refused', (new ConfirmationMail(mailer: $mailer, l10n: $this->l10n()))->send(run: self::signup([]), confirmUrl: 'u'));
	}//end testASendFailureIsReturned()
}//end class
