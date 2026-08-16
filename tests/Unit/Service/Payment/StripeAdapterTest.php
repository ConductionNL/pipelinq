<?php

/**
 * Unit tests for StripeAdapter.
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

use OCA\Pipelinq\Service\Payment\StripeAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/StubHttpTransport.php';

/**
 * Tests for StripeAdapter.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/tasks.md#11.1
 */
class StripeAdapterTest extends TestCase {
	/**
	 * Build an adapter with stub transport.
	 *
	 * @param array<int, array<string, mixed>> $responses Queued.
	 *
	 * @return array{0: StripeAdapter, 1: StubHttpTransport}
	 */
	private function build(array $responses = []): array {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$transport = new StubHttpTransport(responses: $responses);
		$adapter = new StripeAdapter(
			credentials: [
				'apiSecret' => 'sk_test_dummy',
				'webhookSecret' => 'whsec_test_signature',
			],
			config: [],
			logger: $logger,
			http: $transport
		);

		return [$adapter, $transport];
	}//end build()

	public function testInitiateCreatesPaymentIntentInCents(): void {
		[$adapter, $transport] = $this->build(responses: [
			[
				'status' => 200,
				'body' => ['id' => 'pi_1ABC', 'client_secret' => 'pi_1ABC_secret_x'],
				'raw' => '{}',
			],
		]);

		$result = $adapter->initiate(
			transactionData: ['reference' => 'TXN-2026-0005', 'id' => 'uuid-5'],
			amount: 25.50,
			paymentMethod: 'card'
		);

		$this->assertSame('pi_1ABC', $result['sessionId']);
		$this->assertSame('pending', $result['status']);

		parse_str($transport->lastRequest()['body'], $form);
		$this->assertSame('2550', $form['amount']);
		$this->assertSame('eur', $form['currency']);
	}//end testInitiateCreatesPaymentIntentInCents()

	public function testValidateWebhookAcceptsRecentSignature(): void {
		[$adapter] = $this->build(responses: []);

		$payload = '{"id":"evt_1","type":"payment_intent.succeeded"}';
		$time = time();
		$signed = $time . '.' . $payload;
		$v1 = hash_hmac('sha256', $signed, 'whsec_test_signature');
		$header = sprintf('t=%d,v1=%s', $time, $v1);

		$this->assertTrue($adapter->validateWebhook(rawPayload: $payload, signature: $header));
	}//end testValidateWebhookAcceptsRecentSignature()

	public function testValidateWebhookRejectsExpiredSignature(): void {
		[$adapter] = $this->build(responses: []);

		$payload = '{"id":"evt_1"}';
		$time = (time() - 86400); // 24h old.
		$signed = $time . '.' . $payload;
		$v1 = hash_hmac('sha256', $signed, 'whsec_test_signature');
		$header = sprintf('t=%d,v1=%s', $time, $v1);

		$this->assertFalse($adapter->validateWebhook(rawPayload: $payload, signature: $header));
	}//end testValidateWebhookRejectsExpiredSignature()

	public function testParseWebhookMapsEventType(): void {
		[$adapter] = $this->build(responses: []);

		$envelope = $adapter->parseWebhook(payload: [
			'id' => 'evt_2',
			'type' => 'payment_intent.succeeded',
			'data' => ['object' => ['id' => 'pi_2', 'payment_intent' => 'pi_2']],
		]);

		$this->assertSame('pi_2', $envelope['sessionId']);
		$this->assertSame('settled', $envelope['status']);
		$this->assertSame('evt_2', $envelope['eventId']);
	}//end testParseWebhookMapsEventType()

	public function testRefundCallsRefundsEndpoint(): void {
		[$adapter, $transport] = $this->build(responses: [
			['status' => 200, 'body' => ['id' => 're_456'], 'raw' => '{}'],
		]);

		$result = $adapter->refund(sessionId: 'pi_2', reason: 'Artikel defect');

		$this->assertSame('re_456', $result['refundId']);
		$this->assertSame('refunded', $result['status']);
		$this->assertStringContainsString('/v1/refunds', $transport->lastRequest()['url']);
	}//end testRefundCallsRefundsEndpoint()
}//end class
