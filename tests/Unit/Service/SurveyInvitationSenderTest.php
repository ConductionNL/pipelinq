<?php
/**
 * Tests for SurveyInvitationSender's response link.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SurveyInvitationSender;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The link in an invitation opens a page a person can answer, not raw JSON.
 */
class SurveyInvitationSenderTest extends TestCase {

	/**
	 * Build the sender over an url generator that prefixes a host.
	 *
	 * @return SurveyInvitationSender The sender.
	 */
	private function sender(): SurveyInvitationSender {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://cloud.example'.$path
		);

		return new SurveyInvitationSender(
			$this->createMock(IMailer::class),
			$urls,
			$this->createMock(LoggerInterface::class),
		);
	}//end sender()

	/**
	 * The link opens the public survey page in the customer portal.
	 *
	 * The JSON endpoint under /survey/i/ is what that page reads; a respondent
	 * who opened it directly saw raw JSON and had no way to answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function testTheLinkOpensThePublicSurveyPage(): void {
		$this->assertSame(
			'https://cloud.example/index.php/apps/pipelinq/portal/survey/abc%2F1',
			$this->sender()->linkFor(token: 'abc/1')
		);
	}//end testTheLinkOpensThePublicSurveyPage()

	/**
	 * Every way a send ends: a channel without transport (logged), a missing
	 * or invalid address, a delivered mail with its exact content (to the
	 * given address, else the recorded one), and a mailer failure (logged).
	 *
	 * @return void
	 */
	public function testEveryWayASendEnds(): void {
		$cases = [
			'sms' => [['channel' => ' sms ', 'deliveryAddress' => 'a@b.nl'], '', 'ok', false, [], [['info', 'SurveyInvitationSender: no transport configured for this channel', ['channel' => 'sms']]]],
			'no address' => [['token' => 't'], '  ', 'ok', false, [], []],
			'invalid address' => [['deliveryAddress' => 'nope'], '', 'ok', false, [['validate', 'nope']], []],
			'recorded address' => [['deliveryAddress' => ' anna@example.org ', 'token' => 'tk/1'], '', 'ok', true, [['validate', 'anna@example.org'], ['to', ['anna@example.org']], ['subject', 'How did we do?'], ['html', '<p>We would like to know how the last contact went.</p><p><a href="https://cloud.example/index.php/apps/pipelinq/portal/survey/tk%2F1">Answer a few questions</a></p>'], ['plain', "We would like to know how the last contact went.\n\nhttps://cloud.example/index.php/apps/pipelinq/portal/survey/tk%2F1"], ['send']], []],
			'given address' => [['channel' => 'email', 'deliveryAddress' => 'old@example.org'], 'new@example.org', 'ok', true, [['validate', 'new@example.org'], ['to', ['new@example.org']], ['subject', 'How did we do?'], ['html', '<p>We would like to know how the last contact went.</p><p><a href="https://cloud.example/index.php/apps/pipelinq/portal/survey/">Answer a few questions</a></p>'], ['plain', "We would like to know how the last contact went.\n\nhttps://cloud.example/index.php/apps/pipelinq/portal/survey/"], ['send']], []],
			'mailer fails' => [['deliveryAddress' => 'anna@example.org'], '', 'throw', false, [['validate', 'anna@example.org'], ['to', ['anna@example.org']], ['subject', 'How did we do?']], [['error', 'SurveyInvitationSender: the invitation could not be sent', ['exception' => 'smtp down']]]],
		];
		foreach ($cases as $name => [$invitation, $address, $mode, $sent, $mailerCalls, $logs]) {
			$seen = [];
			$logged = [];
			$message = $this->createMock(IMessage::class);
			$message->method('setTo')->willReturnCallback(function (array $to) use (&$seen, $message) {
				$seen[] = ['to', $to];
				return $message;
			});
			$message->method('setSubject')->willReturnCallback(function (string $subject) use (&$seen, $message, $mode) {
				$seen[] = ['subject', $subject];
				if ($mode === 'throw') {
					throw new \RuntimeException('smtp down');
				}

				return $message;
			});
			$message->method('setHtmlBody')->willReturnCallback(function (string $body) use (&$seen, $message) {
				$seen[] = ['html', $body];
				return $message;
			});
			$message->method('setPlainBody')->willReturnCallback(function (string $body) use (&$seen, $message) {
				$seen[] = ['plain', $body];
				return $message;
			});
			$mailer = $this->createMock(IMailer::class);
			$mailer->method('validateMailAddress')->willReturnCallback(function (string $mail) use (&$seen): bool {
				$seen[] = ['validate', $mail];
				return str_contains($mail, '@');
			});
			$mailer->method('createMessage')->willReturn($message);
			$mailer->method('send')->willReturnCallback(function () use (&$seen): array {
				$seen[] = ['send'];
				return [];
			});
			$logger = $this->createMock(LoggerInterface::class);
			foreach (['info', 'error'] as $level) {
				$logger->method($level)->willReturnCallback(function (string $m, array $c = []) use (&$logged, $level): void {
					$logged[] = [$level, $m, $c];
				});
			}

			$urls = $this->createMock(IURLGenerator::class);
			$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example'.$path);

			$this->assertSame($sent, (new SurveyInvitationSender($mailer, $urls, $logger))->send($invitation, $address), $name);
			$this->assertSame($mailerCalls, $seen, $name);
			$this->assertSame($logs, $logged, $name);
		}//end foreach
	}//end testEveryWayASendEnds()
}//end class
