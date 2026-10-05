<?php

/**
 * Unit tests for IntegriqConsentClient.
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

use OCA\Pipelinq\Service\IntegriqConsentClient;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The client asks integriq by string class name and fails closed.
 */
class IntegriqConsentClientTest extends TestCase {

	private FakeIntegriq $integriq;

	private IAppConfig $appConfig;

	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->integriq = new FakeIntegriq();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function client(string $decisionEvent = IntegriqConsentClient::DECISION_EVENT, string $changeEvent = IntegriqConsentClient::CHANGE_EVENT): IntegriqConsentClient {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://pipelinq.example/');
		return new IntegriqConsentClient(
			dispatcher: $this->integriq,
			appConfig: $this->appConfig,
			urlGenerator: $urls,
			logger: $this->logger,
			decisionEvent: $decisionEvent,
			changeEvent: $changeEvent,
		);
	}//end client()

	/**
	 * The three ways integriq can be absent, by name.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function absentModes(): array {
		return [
			'class missing' => ['missing'],
			'unhandled' => [FakeIntegriq::MODE_UNHANDLED],
			'throwing' => [FakeIntegriq::MODE_THROW],
		];
	}//end absentModes()

	#[DataProvider('absentModes')]
	public function testAbsentIntegriqRefusesNonExemptAndPassesAccount(string $mode): void {
		$decisionEvent = IntegriqConsentClient::DECISION_EVENT;
		if ($mode === 'missing') {
			$decisionEvent = 'Event\\NoSuchDecisionEvent';
		} else {
			$this->integriq->mode = $mode;
		}

		$this->logger->expects($this->atLeastOnce())->method('warning');
		$client = $this->client(decisionEvent: $decisionEvent);

		foreach (['marketing', 'service', 'reminder'] as $category) {
			$decision = $client->decideOne(channel: 'email', category: $category, requiresConsent: false, address: 'a@example.nl');
			self::assertFalse($decision['send'], $category.' must be refused when integriq is '.$mode);
			self::assertSame('authority-unavailable', $decision['code']);
		}

		$account = $client->decideOne(channel: 'email', category: 'account', requiresConsent: false, address: 'a@example.nl');
		self::assertTrue($account['send']);
		self::assertSame('authority-unavailable', $account['code']);
		self::assertNull($account['unsubscribe']);
	}//end testAbsentIntegriqRefusesNonExemptAndPassesAccount()

	public function testAnOptOutIsRefusedAndAnAllowedSendCarriesTheLink(): void {
		$this->integriq->seed(['address' => 'out@example.nl', 'state' => 'opted-out', 'scope' => 'instance', 'channel' => '']);
		$decisions = $this->client()->decide(
			channel: 'email',
			category: 'service',
			requiresConsent: false,
			recipients: [['address' => 'out@example.nl'], ['address' => 'in@example.nl']]
		);

		self::assertFalse($decisions['out@example.nl']['send']);
		self::assertSame('opted-out', $decisions['out@example.nl']['code']);
		self::assertTrue($decisions['in@example.nl']['send']);
		self::assertStringStartsWith('https://', (string)$decisions['in@example.nl']['unsubscribe']['oneClickUrl']);
		self::assertSame('pipelinq', $this->integriq->decisionEvents[0]->getSourceApp());
		self::assertSame('https://pipelinq.example', $this->integriq->decisionEvents[0]->getBaseUrl());
	}//end testAnOptOutIsRefusedAndAnAllowedSendCarriesTheLink()

	public function testAReplyWithInReplyToPassesAnOptOut(): void {
		$this->integriq->seed(['address' => '+31612345678', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'whatsapp']);
		$decision = $this->client()->decideOne(
			channel: 'whatsapp',
			category: 'reply',
			requiresConsent: false,
			address: '+31612345678',
			inReplyTo: 'msg-1'
		);

		self::assertTrue($decision['send']);
		self::assertSame('msg-1', $this->integriq->decisionEvents[0]->getInReplyTo());
	}//end testAReplyWithInReplyToPassesAnOptOut()

	public function testBatchesOf500(): void {
		$recipients = [];
		for ($i = 0; $i < 1200; $i++) {
			$recipients[] = ['address' => 'p'.$i.'@example.nl'];
		}

		$decisions = $this->client()->decide(channel: 'email', category: 'marketing', requiresConsent: false, recipients: $recipients);

		self::assertCount(3, $this->integriq->decisionEvents);
		self::assertCount(1200, $decisions);
	}//end testBatchesOf500()

	public function testRecordHandsTheWishToIntegriq(): void {
		$outcome = $this->client()->record(
			[
				'address' => '+31612345678',
				'state' => 'opted-out',
				'scope' => 'channel',
				'channel' => 'sms',
				'contactRef' => 'c-1',
				'source' => 'keyword-stop',
				'legacyRef' => 'L1',
			]
		);

		self::assertTrue($outcome['recorded']);
		self::assertNotNull($outcome['recordId']);
		$request = $this->integriq->changeEvents[0]->toRequest();
		self::assertSame('pipelinq', $request['sourceApp']);
		self::assertSame('keyword-stop', $request['source']);
		self::assertSame('L1', $request['legacyRef']);
	}//end testRecordHandsTheWishToIntegriq()

	public function testRecordIsNotRecordedWhenIntegriqIsAbsentOrRefuses(): void {
		self::assertFalse($this->client(changeEvent: 'Event\\Nope')->record(['address' => 'a@example.nl', 'state' => 'opted-out'])['recorded']);

		$this->integriq->changeMode = FakeIntegriq::MODE_UNHANDLED;
		self::assertFalse($this->client()->record(['address' => 'a@example.nl', 'state' => 'opted-out'])['recorded']);

		$this->integriq->changeMode = FakeIntegriq::MODE_THROW;
		self::assertFalse($this->client()->record(['address' => 'a@example.nl', 'state' => 'opted-out'])['recorded']);

		$this->integriq->changeMode = FakeIntegriq::MODE_ANSWER;
		$refused = $this->client()->record(['address' => 'a@example.nl', 'state' => 'maybe']);
		self::assertFalse($refused['recorded']);
		self::assertSame('invalid-request', $refused['code']);
	}//end testRecordIsNotRecordedWhenIntegriqIsAbsentOrRefuses()

	public function testCutoverFlag(): void {
		$this->appConfig->method('getValueString')->willReturnOnConsecutiveCalls('pipelinq', 'integriq');
		$client = $this->client();
		self::assertFalse($client->isCutover());
		self::assertTrue($client->isCutover());
	}//end testCutoverFlag()
}//end class
