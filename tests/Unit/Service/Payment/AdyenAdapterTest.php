<?php

/**
 * Unit tests for AdyenAdapter.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Payment
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
 *
 * @spec openspec/changes/pos-payment-provider-adapter/tasks.md#11.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Payment;

use OCA\Pipelinq\Service\Payment\AdyenAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/StubHttpTransport.php';

/**
 * Tests for AdyenAdapter.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/tasks.md#11.1
 */
class AdyenAdapterTest extends TestCase {
	/**
	 * Build an adapter with stub transport.
	 *
	 * @param array<int, array<string, mixed>> $responses Queued.
	 *
	 * @return array{0: AdyenAdapter, 1: StubHttpTransport}
	 */
	private function build(array $responses = []): array {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$transport = new StubHttpTransport(responses: $responses);
		$adapter = new AdyenAdapter(
			credentials: [
				'apiKey' => 'adyen-key',
				'webhookSecret' => bin2hex('adyen-hmac-secret'),
			],
			config: [
				'environment' => 'sandbox',
				'merchantAccount' => 'PipelinqPOS',
			],
			http: $transport
		);

		return [$adapter, $transport];
	}//end build()

	public function testInitiateSendsCentsAndMerchantAccount(): void {
		[$adapter, $transport] = $this->build(responses: [
			['status' => 200, 'body' => ['pspReference' => 'psp_1'], 'raw' => '{}'],
		]);

		$result = $adapter->initiate(
			transactionData: ['reference' => 'TXN-1', 'id' => 'uuid-1'],
			amount: 99.99,
			paymentMethod: 'card'
		);

		$this->assertSame('psp_1', $result['sessionId']);
		$body = json_decode($transport->lastRequest()['body'], true);
		$this->assertSame(9999, $body['amount']['value']);
		$this->assertSame('PipelinqPOS', $body['merchantAccount']);
		$this->assertSame('scheme', $body['paymentMethod']['type']);
	}//end testInitiateSendsCentsAndMerchantAccount()

	public function testValidateWebhookAcceptsCorrectHmac(): void {
		[$adapter] = $this->build(responses: []);

		$payload = '{"hello":"world"}';
		$secret = 'adyen-hmac-secret';
		$sig = base64_encode(hash_hmac('sha256', $payload, $secret, true));

		$this->assertTrue($adapter->validateWebhook(rawPayload: $payload, signature: $sig));
		$this->assertFalse($adapter->validateWebhook(rawPayload: $payload, signature: 'bad'));
	}//end testValidateWebhookAcceptsCorrectHmac()

	public function testParseWebhookFromNotificationItems(): void {
		[$adapter] = $this->build(responses: []);

		$envelope = $adapter->parseWebhook(payload: [
			'notificationItems' => [
				[
					'NotificationRequestItem' => [
						'pspReference' => 'psp_2',
						'eventCode' => 'CAPTURE',
						'success' => 'true',
					],
				],
			],
		]);

		$this->assertSame('psp_2', $envelope['sessionId']);
		$this->assertSame('settled', $envelope['status']);
	}//end testParseWebhookFromNotificationItems()
}//end class
