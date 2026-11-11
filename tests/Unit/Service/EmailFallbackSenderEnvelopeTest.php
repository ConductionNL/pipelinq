<?php

/**
 * Characterisation of the fallback email's envelope, body and failure answers.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-FALLBACK-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Notifications\Service\Notification\UnsubscribeHeaders;
use OCA\Pipelinq\Service\EmailFallbackSender;
use OCA\Pipelinq\Service\UnsubscribeMail;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCA\Pipelinq\Tests\Unit\Support\RecordingMessage;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins send()'s exact message, From handling, mailer answers and errors.
 */
class EmailFallbackSenderEnvelopeTest extends TestCase {
	/** @var list<RecordingMessage> */
	private array $sent = [];

	private \ArrayObject $fromLog;

	/** @var list<string> */
	private array $warnings = [];

	/**
	 * Build the sender.
	 *
	 * @param string                       $from    Configured From address.
	 * @param callable(IMessage):array     $deliver The mailer's send answer.
	 * @param list<string>                 $invalid Addresses the mailer rejects.
	 *
	 * @return EmailFallbackSender
	 */
	private function senderWith(string $from, callable $deliver, array $invalid = []): EmailFallbackSender {
		$froms = new \ArrayObject();
		$this->fromLog = $froms;
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturnCallback(static fn (string $a) => in_array($a, $invalid, true) === false);
		$mailer->method('createMessage')->willReturnCallback(
			static fn () => new class ($froms) extends RecordingMessage {
				public function __construct(private \ArrayObject $froms) {
				}
				public function setFrom(array $addresses): IMessage {
					$this->froms[] = $addresses;
					return $this;
				}
			}
		);
		$mailer->method('send')->willReturnCallback(
			function (IMessage $message) use ($deliver): array {
				$this->sent[] = $message;
				return $deliver($message);
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $key === EmailFallbackSender::CONFIG_FROM_ADDRESS ? $from : $default
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []) => vsprintf($text, $p));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new UnsubscribeHeaders(new NullLogger()));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $m, array $c = []): void {
			$this->warnings[] = $m.' '.json_encode($c);
		});

		return new EmailFallbackSender(
			$mailer,
			$appConfig,
			$logger,
			FakeIntegriq::client($appConfig, new FakeIntegriq()),
			new UnsubscribeMail($container, $l10n, new NullLogger())
		);
	}//end senderWith()

	/**
	 * The notice, the body and the unsubscribe line in that order, plain = stripped html.
	 *
	 * @return void
	 */
	public function testTheMessageCarriesNoticeBodyAndLinkInOrder(): void {
		$sender = $this->senderWith('noreply@gemeente.nl', static fn () => []);

		self::assertTrue($sender->send(['subject' => 'Bericht & meer', 'body' => '<p>Hallo</p>'], 'ok@example.nl'));
		$msg = $this->sent[0];
		self::assertSame(['ok@example.nl'], $msg->to);
		self::assertSame('Bericht & meer', $msg->subject);
		self::assertStringStartsWith('<p>'.EmailFallbackSender::FALLBACK_NOTICE.'</p><p>Hallo</p><p>', $msg->html);
		self::assertStringEndsWith('</p>', $msg->html);
		self::assertSame(strip_tags($msg->html), $msg->plain);
		self::assertSame([['noreply@gemeente.nl']], $this->fromLog->getArrayCopy());
		self::assertNull($sender->lastRefusal());
	}//end testTheMessageCarriesNoticeBodyAndLinkInOrder()

	/**
	 * Without the notice the body starts with the message body; missing fields become empty.
	 *
	 * @return void
	 */
	public function testWithoutTheNoticeAndWithoutSubject(): void {
		self::assertTrue($this->senderWith('', static fn () => [])->send([], 'ok@example.nl', false));
		self::assertSame('', $this->sent[0]->subject);
		self::assertStringStartsWith('<p>', $this->sent[0]->html);
		self::assertStringNotContainsString(EmailFallbackSender::FALLBACK_NOTICE, $this->sent[0]->html);
		self::assertSame([], $this->fromLog->getArrayCopy());
	}//end testWithoutTheNoticeAndWithoutSubject()

	/**
	 * An invalid configured From address is not set.
	 *
	 * @return void
	 */
	public function testAnInvalidFromIsSkipped(): void {
		self::assertTrue($this->senderWith('bad-from', static fn () => [], ['bad-from'])->send(['body' => 'B'], 'ok@example.nl'));
		self::assertSame([], $this->fromLog->getArrayCopy());
	}//end testAnInvalidFromIsSkipped()

	/**
	 * Failed recipients from the mailer answer false.
	 *
	 * @return void
	 */
	public function testFailedRecipientsAnswerFalse(): void {
		self::assertFalse($this->senderWith('', static fn () => ['ok@example.nl'])->send(['body' => 'B'], 'ok@example.nl'));
		self::assertSame([], $this->warnings);
	}//end testFailedRecipientsAnswerFalse()

	/**
	 * A mailer exception is logged and rethrown wrapped.
	 *
	 * @return void
	 */
	public function testAMailerExceptionIsLoggedAndWrapped(): void {
		$boom = new \LogicException('smtp down');
		$sender = $this->senderWith('', static function () use ($boom): array {
			throw $boom;
		});
		try {
			$sender->send(['body' => 'B'], 'ok@example.nl');
			self::fail('no exception');
		} catch (RuntimeException $e) {
			self::assertSame('Fallback email dispatch failed: smtp down', $e->getMessage());
			self::assertSame(0, $e->getCode());
			self::assertSame($boom, $e->getPrevious());
		}
		self::assertSame(['Berichtenbox fallback email dispatch failed. {"exception":"smtp down"}'], $this->warnings);
	}//end testAMailerExceptionIsLoggedAndWrapped()

	/**
	 * An empty or invalid recipient throws before integriq is asked.
	 *
	 * @return void
	 */
	public function testAnInvalidRecipientThrows(): void {
		foreach (['', 'nope'] as $address) {
			try {
				$this->senderWith('', static fn () => [], ['nope'])->send(['body' => 'B'], $address);
				self::fail('no exception for '.$address);
			} catch (RuntimeException $e) {
				self::assertSame('Invalid recipient address.', $e->getMessage());
			}
		}
		self::assertSame([], $this->sent);
	}//end testAnInvalidRecipientThrows()
}//end class
