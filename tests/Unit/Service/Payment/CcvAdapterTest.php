<?php

/**
 * Unit tests for CcvAdapter.
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

use OCA\Pipelinq\Service\Payment\CcvAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__.'/StubHttpTransport.php';

/**
 * Tests for CcvAdapter.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/tasks.md#11.1
 */
class CcvAdapterTest extends TestCase
{
    /**
     * Build an adapter with the stub transport.
     *
     * @param array<int, array<string, mixed>> $responses Queued transport responses.
     *
     * @return array{0: CcvAdapter, 1: StubHttpTransport}
     */
    private function build(array $responses=[]): array
    {
        $logger    = $this->createMock(originalClassName: LoggerInterface::class);
        $transport = new StubHttpTransport(responses: $responses);
        $adapter   = new CcvAdapter(
            credentials: [
                'apiKey'        => 'ccv-key',
                'webhookSecret' => 'ccv-hmac-secret',
            ],
            config: [
                'environment' => 'sandbox',
                'terminalId'  => 'kassa-01',
                'merchantId'  => 'merchant-1',
            ],
            logger: $logger,
            http: $transport
        );

        return [$adapter, $transport];
    }//end build()

    public function testInitiateSendsCorrectCcvPayload(): void
    {
        [$adapter, $transport] = $this->build(responses: [
            ['status' => 201, 'body' => ['reference' => 'CCV20260520102833001'], 'raw' => '{}'],
        ]);

        $result = $adapter->initiate(
            transactionData: ['reference' => 'TXN-2026-0003', 'id' => 'uuid-3'],
            amount: 89.97,
            paymentMethod: 'card'
        );

        $this->assertSame('CCV20260520102833001', $result['sessionId']);
        $this->assertNull($result['redirectUrl']);
        $this->assertSame('pending', $result['status']);

        $body = json_decode($transport->lastRequest()['body'], true);
        $this->assertSame(8997, $body['amount']);
        $this->assertSame('EUR', $body['currency']);
        $this->assertSame('kassa-01', $body['terminalId']);
    }//end testInitiateSendsCorrectCcvPayload()

    public function testValidateWebhookUsesHmacSha512WithMerchantConcat(): void
    {
        [$adapter] = $this->build(responses: []);

        $payload   = '{"reference":"CCV-1","status":"success"}';
        $expected  = hash_hmac('sha512', 'merchant-1'.$payload, 'ccv-hmac-secret');

        $this->assertTrue($adapter->validateWebhook(rawPayload: $payload, signature: $expected));
        $this->assertFalse($adapter->validateWebhook(rawPayload: $payload, signature: 'wrong'));
    }//end testValidateWebhookUsesHmacSha512WithMerchantConcat()

    public function testTestConnectionRecognisesInvalidCredentials(): void
    {
        [$adapter] = $this->build(responses: [
            ['status' => 401, 'body' => [], 'raw' => ''],
        ]);

        $result = $adapter->testConnection();

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Invalid API credentials', $result['message']);
    }//end testTestConnectionRecognisesInvalidCredentials()
}//end class
