<?php

/**
 * The Berichtenbox email fallback asks integriq with the message's category.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\Notification\UnsubscribeHeaders;
use OCA\Pipelinq\Service\EmailFallbackSender;
use OCA\Pipelinq\Service\UnsubscribeMail;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCA\Pipelinq\Tests\Unit\Support\RecordingMessage;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Fallback refusals and links.
 */
class EmailFallbackSenderTest extends TestCase {

	private FakeIntegriq $integriq;

	/** @var array<int, RecordingMessage> */
	private array $sent = [];

	private function sender(): EmailFallbackSender {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturnCallback(static fn () => new RecordingMessage());
		$mailer->method('send')->willReturnCallback(
			function ($message): array {
				$this->sent[] = $message;
				return [];
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []) => vsprintf($text, $p));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new UnsubscribeHeaders());

		return new EmailFallbackSender(
			$mailer,
			$appConfig,
			new NullLogger(),
			FakeIntegriq::client($appConfig, $this->integriq),
			new UnsubscribeMail($container, $l10n, new NullLogger())
		);
	}//end sender()

	protected function setUp(): void {
		$this->integriq = new FakeIntegriq();
	}//end setUp()

	public function testAnOptedOutBurgerGetsNoFallback(): void {
		$this->integriq->seed(['address' => 'burger@example.nl', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'email']);
		$sender = $this->sender();

		self::assertFalse($sender->send(['uuid' => 'bb-1', 'subject' => 'Uw aanvraag', 'body' => '<p>Status</p>'], 'burger@example.nl', true));
		self::assertSame([], $this->sent);
		self::assertSame('opted-out', $sender->lastRefusal()['code']);
		self::assertSame('service', $this->integriq->decisionEvents[0]->getCategory());
	}//end testAnOptedOutBurgerGetsNoFallback()

	public function testABesluitFallbackIsSentDespiteTheOptOutWithoutALink(): void {
		$this->integriq->seed(['address' => 'burger@example.nl', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'email']);
		$sender = $this->sender();

		self::assertTrue($sender->send(['subject' => 'Besluit', 'body' => '<p>Besluit</p>', 'category' => 'besluit'], 'burger@example.nl', false));
		self::assertStringNotContainsString('integriq.example', $this->sent[0]->html);
		self::assertSame([], $this->sent[0]->headers);
	}//end testABesluitFallbackIsSentDespiteTheOptOutWithoutALink()

	public function testANormalFallbackCarriesTheLinkAndHeaders(): void {
		self::assertTrue($this->sender()->send(['subject' => 'Bericht', 'body' => '<p>Hallo</p>'], 'ok@example.nl', true));
		self::assertStringContainsString('https://integriq.example/', $this->sent[0]->html);
		self::assertArrayHasKey('List-Unsubscribe', $this->sent[0]->headers);
	}//end testANormalFallbackCarriesTheLinkAndHeaders()
}//end class
