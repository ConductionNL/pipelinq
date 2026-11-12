<?php

/**
 * Characterisation tests for PosPaymentService::handleWebhook().
 *
 * Pins the public payment webhook: an unknown or unconfigured provider and
 * a signature that does not verify are refused before anything is parsed,
 * a payload without a session is ignored, and a verified settlement is
 * handed on with the provider's session, status and event id.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Payment\PaymentProviderInterface;
use OCA\Pipelinq\Service\PosPaymentService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the payment webhook.
 */
class PosPaymentWebhookTest extends TestCase {

	/**
	 * Log lines, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * The payloads the provider parsed.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $parsed = [];

	/**
	 * The signature checks, as [payload, signature].
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	private array $checks = [];

	/**
	 * The settlements handed on, as their arguments.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $settlements = [];

	/**
	 * An unknown provider is refused without looking anything up.
	 *
	 * @return void
	 */
	public function testAnUnknownProviderIsRefused(): void {
		$service = $this->service(provider: null);

		$this->assertSame(['status' => 'invalid', 'error' => 'Unknown provider'], $service->handleWebhook('paypal', '{}', 'x'));
		$this->assertSame([[], [], []], [$this->checks, $this->parsed, $this->settlements]);
	}//end testAnUnknownProviderIsRefused()

	/**
	 * A provider that cannot be built is refused as not configured.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredProviderIsRefused(): void {
		$service = $this->service(provider: null);

		$this->assertSame(['status' => 'invalid', 'error' => 'Provider not configured'], $service->handleWebhook('mollie', '{}', 'x'));
		$this->assertSame([], $this->checks);
	}//end testAnUnconfiguredProviderIsRefused()

	/**
	 * A signature that does not verify is logged and refused before parsing.
	 *
	 * @return void
	 */
	public function testABadSignatureIsRefusedBeforeParsing(): void {
		$service = $this->service(provider: $this->provider(valid: false, envelope: []));

		$this->assertSame(['status' => 'invalid', 'error' => 'Signature mismatch'], $service->handleWebhook('ccv', '{"a":1}', 'sig'));
		$this->assertSame([['{"a":1}', 'sig']], $this->checks);
		$this->assertSame([], $this->parsed);
		$this->assertSame([['warning', 'Pipelinq POS payment: invalid webhook signature', ['provider' => 'ccv']]], $this->logs);
	}//end testABadSignatureIsRefusedBeforeParsing()

	/**
	 * A verified payload without a session is logged and ignored.
	 *
	 * @return void
	 */
	public function testAPayloadWithoutASessionIsIgnored(): void {
		$service = $this->service(provider: $this->provider(valid: true, envelope: ['sessionId' => '', 'eventId' => 'e', 'status' => 'settled']));

		$this->assertSame(['status' => 'ignored'], $service->handleWebhook('adyen', '[1,2]', 's'));
		$this->assertSame([[1, 2]], $this->parsed);
		$this->assertSame([], $this->settlements);
		$this->assertSame([['info', 'Pipelinq POS payment: webhook with no sessionId', ['provider' => 'adyen']]], $this->logs);
	}//end testAPayloadWithoutASessionIsIgnored()

	/**
	 * A verified settlement is handed on; an empty or non-object payload is
	 * parsed as an empty one.
	 *
	 * @return void
	 */
	public function testAVerifiedSettlementIsHandedOn(): void {
		$service = $this->service(provider: $this->provider(valid: true, envelope: ['sessionId' => 'tr_1', 'eventId' => 'evt-1', 'status' => 'refunded']));

		$this->assertSame(['status' => 'ok', 'transactionId' => 'tx'], $service->handleWebhook('stripe', '', 's'));
		$service->handleWebhook('stripe', '"text"', 's');
		$service->handleWebhook('stripe', '{"id":"tr_1"}', 's');

		$this->assertSame([[], [], ['id' => 'tr_1']], $this->parsed);
		$this->assertSame(array_fill(0, 3, ['stripe', 'tr_1', 'refunded', 'evt-1']), $this->settlements);
	}//end testAVerifiedSettlementIsHandedOn()

	/**
	 * A provider double.
	 *
	 * @param bool                 $valid    Whether the signature verifies.
	 * @param array<string, mixed> $envelope What parseWebhook answers.
	 *
	 * @return PaymentProviderInterface
	 */
	private function provider(bool $valid, array $envelope): PaymentProviderInterface {
		$provider = $this->createMock(PaymentProviderInterface::class);
		$provider->method('validateWebhook')->willReturnCallback(
			function (string $rawPayload, string $signature) use ($valid): bool {
				$this->checks[] = [$rawPayload, $signature];
				return $valid;
			}
		);
		$provider->method('parseWebhook')->willReturnCallback(
			function (array $payload) use ($envelope): array {
				$this->parsed[] = $payload;
				return $envelope;
			}
		);
		return $provider;
	}//end provider()

	/**
	 * The service with its provider lookup and settlement stubbed.
	 *
	 * @param PaymentProviderInterface|null $provider The provider, or null when it cannot be built.
	 *
	 * @return PosPaymentService
	 */
	private function service(?PaymentProviderInterface $provider): PosPaymentService {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		$service = $this->getMockBuilder(PosPaymentService::class)
			->setConstructorArgs([
				$this->createMock(ContainerInterface::class),
				$this->createMock(IAppConfig::class),
				$this->createMock(ICrypto::class),
				$this->createMock(IGroupManager::class),
				$this->createMock(IUserSession::class),
				$logger,
			])
			->onlyMethods(['getPaymentProvider', 'handleSettlement'])
			->getMock();
		$service->method('getPaymentProvider')->willReturnCallback(
			static function () use ($provider): PaymentProviderInterface {
				if ($provider === null) {
					throw new RuntimeException('no credentials');
				}

				return $provider;
			}
		);
		$service->method('handleSettlement')->willReturnCallback(
			function (string $providerName, string $sessionId, string $paymentStatus, string $eventId): array {
				$this->settlements[] = [$providerName, $sessionId, $paymentStatus, $eventId];
				return ['status' => 'ok', 'transactionId' => 'tx'];
			}
		);
		return $service;
	}//end service()
}//end class
