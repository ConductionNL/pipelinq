<?php

/**
 * Unit tests for MollieAdapter — POS payment provider adapter.
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

use OCA\Pipelinq\Service\Payment\MollieAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/StubHttpTransport.php';

/**
 * Tests for MollieAdapter.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/tasks.md#11.1
 */
class MollieAdapterTest extends TestCase {
	/**
	 * Build an adapter with the in-memory transport stub.
	 *
	 * @param array<int, array<string, mixed>> $responses Queued transport responses.
	 *
	 * @return array{0: MollieAdapter, 1: StubHttpTransport}
	 */
	private function build(array $responses = []): array {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$transport = new StubHttpTransport(responses: $responses);
		$adapter = new MollieAdapter(
			credentials: [
				'apiKey' => 'test_dummy_key',
				'webhookSecret' => 'super-secret',
			],
			config: ['environment' => 'sandbox'],
			logger: $logger,
			http: $transport
		);

		return [$adapter, $transport];
	}//end build()

	public function testInitiateSendsCorrectMolliePayload(): void {
		[$adapter, $transport] = $this->build(responses: [
			[
				'status' => 201,
				'body' => [
					'id' => 'tr_WDqYK6vllg',
					'_links' => ['checkout' => ['href' => 'https://mollie.com/pay/abc']],
				],
				'raw' => '{}',
			],
		]);

		$result = $adapter->initiate(
			transactionData: ['reference' => 'TXN-2026-0001', 'id' => 'uuid-1'],
			amount: 21.53,
			paymentMethod: 'ideal'
		);

		$this->assertSame('tr_WDqYK6vllg', $result['sessionId']);
		$this->assertSame('https://mollie.com/pay/abc', $result['redirectUrl']);
		$this->assertSame('pending', $result['status']);

		$request = $transport->lastRequest();
		$this->assertSame('POST', $request['method']);
		$this->assertStringContainsString('/v2/payments', $request['url']);
		$body = json_decode($request['body'], true);
		$this->assertSame('21.53', $body['amount']['value']);
		$this->assertSame('EUR', $body['amount']['currency']);
		$this->assertSame('ideal', $body['method']);
		$this->assertSame('TXN-2026-0001', $body['metadata']['reference']);
	}//end testInitiateSendsCorrectMolliePayload()

	public function testInitiateWithoutApiKeyFails(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$adapter = new MollieAdapter(
			credentials: [],
			config: [],
			logger: $logger,
			http: new StubHttpTransport(responses: [])
		);

		$result = $adapter->initiate(transactionData: ['reference' => 'X'], amount: 10.0, paymentMethod: 'ideal');

		$this->assertSame('failed', $result['status']);
		$this->assertSame('', $result['sessionId']);
	}//end testInitiateWithoutApiKeyFails()

	public function testInitiateMapsHttpErrorToFailed(): void {
		[$adapter, $transport] = $this->build(responses: [
			['status' => 401, 'body' => [], 'raw' => 'unauthorized'],
		]);

		$result = $adapter->initiate(transactionData: ['reference' => 'X'], amount: 10.0, paymentMethod: 'ideal');

		$this->assertSame('failed', $result['status']);
		$this->assertStringContainsString('401', $result['error']);
	}//end testInitiateMapsHttpErrorToFailed()

	public function testValidateWebhookAcceptsCorrectSignature(): void {
		[$adapter] = $this->build(responses: []);

		$payload = '{"id":"tr_WDqYK6vllg","status":"paid"}';
		$signature = hash_hmac('sha256', $payload, 'super-secret');

		$this->assertTrue($adapter->validateWebhook(rawPayload: $payload, signature: $signature));
	}//end testValidateWebhookAcceptsCorrectSignature()

	public function testValidateWebhookRejectsBadSignature(): void {
		[$adapter] = $this->build(responses: []);

		$payload = '{"id":"tr_WDqYK6vllg","status":"paid"}';

		$this->assertFalse($adapter->validateWebhook(rawPayload: $payload, signature: 'bogus'));
		$this->assertFalse($adapter->validateWebhook(rawPayload: '', signature: 'anything'));
		$this->assertFalse($adapter->validateWebhook(rawPayload: $payload, signature: ''));
	}//end testValidateWebhookRejectsBadSignature()

	public function testParseWebhookNormalisesStatus(): void {
		[$adapter] = $this->build(responses: []);

		$envelope = $adapter->parseWebhook(payload: [
			'id' => 'tr_xyz',
			'status' => 'paid',
			'eventId' => 'evt_1',
		]);

		$this->assertSame('tr_xyz', $envelope['sessionId']);
		$this->assertSame('settled', $envelope['status']);
		$this->assertSame('evt_1', $envelope['eventId']);
	}//end testParseWebhookNormalisesStatus()

	public function testRefundCallsRefundEndpoint(): void {
		[$adapter, $transport] = $this->build(responses: [
			['status' => 201, 'body' => ['id' => 're_123'], 'raw' => '{}'],
		]);

		$result = $adapter->refund(sessionId: 'tr_xyz', reason: 'Artikel defect');

		$this->assertSame('re_123', $result['refundId']);
		$this->assertSame('refunded', $result['status']);
		$this->assertStringContainsString('/v2/payments/tr_xyz/refunds', $transport->lastRequest()['url']);
	}//end testRefundCallsRefundEndpoint()

	public function testTestConnectionOkAndFail(): void {
		[$adapter, $transport] = $this->build(responses: [
			['status' => 200, 'body' => ['count' => 5], 'raw' => '{}'],
			['status' => 401, 'body' => [], 'raw' => ''],
		]);

		$ok = $adapter->testConnection();
		$this->assertSame('ok', $ok['status']);

		$bad = $adapter->testConnection();
		$this->assertSame('error', $bad['status']);
	}//end testTestConnectionOkAndFail()
}//end class
