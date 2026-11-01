<?php

/**
 * Regression test: an integriq answer whose `send` is not a boolean is no answer.
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
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Integriq\Event\OutboundSendDecisionRequestedEvent;
use OCA\Pipelinq\Service\Consent\ConsentAsk;
use OCA\Pipelinq\Service\IntegriqConsentClient;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A malformed `send` is refused as `authority-unavailable`, without a link and with a warning.
 *
 * @covers \OCA\Pipelinq\Service\IntegriqConsentClient
 * @uses   \OCA\Pipelinq\Service\Consent\ConsentAsk
 * @uses   \OCA\Pipelinq\Service\Consent\ConsentQuestion
 * @uses   \OCA\Pipelinq\Support\FleetAppId
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
 */
class IntegriqConsentMalformedAnswerTest extends TestCase {
	/**
	 * Values of `send` that are not a boolean.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function malformedSends(): array {
		return ['text true' => ['true'], 'int one' => [1], 'null' => [null], 'text false' => ['false'], 'int zero' => [0], 'array' => [[true]]];
	}//end malformedSends()

	/**
	 * A non-exempt message is refused as unavailable, with no link, and logged.
	 *
	 * @param mixed $send The malformed value.
	 *
	 * @return void
	 */
	#[DataProvider('malformedSends')]
	public function testAMalformedSendIsRefusedAsUnavailable(mixed $send): void {
		$warnings = [];
		$decision = $this->client(send: $send, warnings: $warnings)->decideOne(
			ask: new ConsentAsk(channel: 'email', category: 'marketing', requiresConsent: true),
			recipient: ['address' => 'a@example.nl']
		);

		self::assertSame(
			['send' => false, 'code' => IntegriqConsentClient::CODE_UNAVAILABLE, 'unsubscribe' => null],
			['send' => $decision['send'], 'code' => $decision['code'], 'unsubscribe' => $decision['unsubscribe']]
		);
		self::assertCount(1, $warnings);
		self::assertSame(['email', 'marketing', 'authority-unavailable'], [$warnings[0]['channel'], $warnings[0]['category'], $warnings[0]['code']]);
	}//end testAMalformedSendIsRefusedAsUnavailable()

	/**
	 * An exempt message goes out without a link, as when integriq gives no answer.
	 *
	 * @param mixed $send The malformed value.
	 *
	 * @return void
	 */
	#[DataProvider('malformedSends')]
	public function testAMalformedSendLetsAnExemptMessageGoWithoutALink(mixed $send): void {
		$warnings = [];
		$decision = $this->client(send: $send, warnings: $warnings)->decideOne(
			ask: new ConsentAsk(channel: 'email', category: 'security', requiresConsent: false),
			recipient: ['address' => 'a@example.nl']
		);

		self::assertSame([true, IntegriqConsentClient::CODE_UNAVAILABLE, null], [$decision['send'], $decision['code'], $decision['unsubscribe']]);
		self::assertCount(1, $warnings);
	}//end testAMalformedSendLetsAnExemptMessageGoWithoutALink()

	/**
	 * A boolean `send` is still read as integriq gave it.
	 *
	 * @return void
	 */
	public function testABooleanSendIsReadAsGiven(): void {
		$warnings = [];
		$decision = $this->client(send: false, warnings: $warnings)->decideOne(
			ask: new ConsentAsk(channel: 'email', category: 'marketing', requiresConsent: true),
			recipient: ['address' => 'a@example.nl']
		);

		self::assertSame([false, 'allowed', []], [$decision['send'], $decision['code'], $warnings]);
	}//end testABooleanSendIsReadAsGiven()

	/**
	 * A client whose integriq answers every address with the given `send`, code `allowed` and a link.
	 *
	 * @param mixed                            $send     The `send` value.
	 * @param array<int, array<string, mixed>> $warnings Collects the warning contexts.
	 *
	 * @return IntegriqConsentClient
	 */
	private function client(mixed $send, array &$warnings): IntegriqConsentClient {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (Event $event) use ($send): void {
				if ($event instanceof OutboundSendDecisionRequestedEvent) {
					foreach ($event->getRecipients() as $recipient) {
						$event->setDecision((string)$recipient['address'], ['send' => $send, 'code' => 'allowed', 'reason' => '', 'unsubscribe' => ['url' => 'https://integriq.example/u']]);
					}

					$event->setHandled(true);
				}
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = $context;
			}
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://pipelinq.example/');

		return new IntegriqConsentClient(dispatcher: $dispatcher, appConfig: $this->createMock(IAppConfig::class), urlGenerator: $urls, logger: $logger);
	}//end client()
}//end class
