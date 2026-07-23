<?php

/**
 * Unit tests for PosPaymentService — POS payment orchestration.
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
 *
 * @spec openspec/changes/pos-payment-provider-adapter/tasks.md#11.1
 * @spec openspec/changes/pos-payment-provider-adapter/tasks.md#11.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\PosPaymentService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PosPaymentService.
 *
 * Tests focus on the orchestration concerns the unit can drive without
 * touching the network: credential masking, provider registry, refund
 * authorization, webhook idempotency, and the failed-init no-mutate path.
 * Adapter-level wire formats live in the per-adapter tests.
 *
 * @spec openspec/changes/pos-payment-provider-adapter/tasks.md#11.1
 */
class PosPaymentServiceTest extends TestCase
{
    /**
     * In-memory app config storage.
     *
     * @var array<string, string>
     */
    private array $configStore = [
        'register'               => 'pipelinq',
        'posTransaction_schema'  => 'posTransaction',
        'pos_managers_group'     => 'pos_managers',
    ];

    /**
     * Build a service with overridable mocks.
     *
     * @param ObjectService|null $object   The ObjectService mock.
     * @param array<string, mixed> $opts   Optional knobs: isManager (bool).
     *
     * @return PosPaymentService
     */
    private function buildService(?ObjectService $object=null, array $opts=[]): PosPaymentService
    {
        $object = ($object ?? $this->createMock(originalClassName: ObjectService::class));

        $container = $this->createMock(originalClassName: ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            callback: function (string $id) use ($object): mixed {
                if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $object;
                }
                throw new \RuntimeException('container: '.$id);
            }
        );

        $appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            callback: function (string $app, string $key, string $default=''): string {
                return ($this->configStore[$key] ?? $default);
            }
        );
        $appConfig->method('setValueString')->willReturnCallback(
            callback: function (string $app, string $key, string $value): bool {
                $this->configStore[$key] = $value;
                return true;
            }
        );

        $crypto = $this->createMock(originalClassName: ICrypto::class);
        $crypto->method('encrypt')->willReturnCallback(
            callback: static function (string $plain): string {
                return 'ENC:'.$plain;
            }
        );
        $crypto->method('decrypt')->willReturnCallback(
            callback: static function (string $encrypted): string {
                if (str_starts_with($encrypted, 'ENC:') === true) {
                    return substr($encrypted, 4);
                }
                return $encrypted;
            }
        );

        $groupMgr = $this->createMock(originalClassName: IGroupManager::class);
        $isManager = ($opts['isManager'] ?? false);
        $groupMgr->method('isAdmin')->willReturn(false);
        $groupMgr->method('isInGroup')->willReturn($isManager);

        $logger = $this->createMock(originalClassName: LoggerInterface::class);

        // The broker's ownership guard needs an identity to check the credential against.
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(originalClassName: IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        return new PosPaymentService(
            container: $container,
            appConfig: $appConfig,
            crypto: $crypto,
            groupMgr: $groupMgr,
            userSession: $userSession,
            logger: $logger,
        );
    }//end buildService()

    public function testListProvidersReturnsFourMaskedProviders(): void
    {
        $service = $this->buildService();

        $providers = $service->listProviders();

        $this->assertCount(4, $providers);
        $names = array_map(static fn ($p) => $p['name'], $providers);
        $this->assertContains('mollie', $names);
        $this->assertContains('ccv', $names);
        $this->assertContains('adyen', $names);
        $this->assertContains('stripe', $names);

        foreach ($providers as $p) {
            // The apiKey field is GONE from the config surface — Pipelinq does not hold
            // one. What a provider carries now is a `credentialId`: a broker credential
            // UUID, unset until an admin picks one.
            $this->assertArrayNotHasKey('apiKey', $p);
            $this->assertArrayNotHasKey('apiSecret', $p);
            $this->assertSame('', $p['credentialId']);
        }
    }//end testListProvidersReturnsFourMaskedProviders()

    /**
     * The PSP key is not stored, even when a client insists on sending one.
     *
     * This test used to assert the OPPOSITE — that `apiKey` was encrypted to
     * `ENC:sk_live_secret` and masked in the response. Encrypting it was good hygiene, but
     * it was still Pipelinq's secret to decrypt, which made Pipelinq the trust boundary
     * for a key that moves money. The key now lives in OpenRegister's credential broker;
     * a submitted `apiKey` must be ignored outright, not quietly persisted.
     *
     * `webhookSecret` still IS stored, and still encrypted: it verifies an HMAC on an
     * inbound webhook, which a constrained outbound proxy cannot do.
     *
     * @return void
     */
    public function testUpdateProviderRefusesTheRetiredApiKeyAndStoresTheCredentialReference(): void
    {
        $service = $this->buildService();

        $result = $service->updateProvider(
            name: 'mollie',
            data: [
                'isActive'      => true,
                'environment'   => 'live',
                'apiKey'        => 'sk_live_secret',
                'credentialId'  => 'cred-uuid-1234',
                'webhookSecret' => 'whsec_xyz',
                'testMode'      => false,
            ]
        );

        $this->assertTrue($result['isActive']);
        $this->assertSame('live', $result['environment']);

        // The credential REFERENCE is stored and returned unmasked — it is not a secret.
        $this->assertSame('cred-uuid-1234', $result['credentialId']);
        $this->assertSame('cred-uuid-1234', $this->configStore['payment_provider.mollie.credentialId']);

        // The submitted key is nowhere: not in the response, not in config, not encrypted
        // "for safety". Not stored at all.
        $this->assertArrayNotHasKey('apiKey', $result);
        $this->assertArrayNotHasKey('payment_provider.mollie.apiKey', $this->configStore);
        $this->assertStringNotContainsString('sk_live_secret', json_encode($this->configStore));

        // The webhook secret is still app-held, still encrypted (the stub prepends ENC:).
        $this->assertSame(PosPaymentService::MASK, $result['webhookSecret']);
        $this->assertSame('ENC:whsec_xyz', $this->configStore['payment_provider.mollie.webhookSecret']);
    }//end testUpdateProviderRefusesTheRetiredApiKeyAndStoresTheCredentialReference()

    public function testUpdateProviderKeepsStoredSecretWhenMaskOrEmpty(): void
    {
        $service = $this->buildService();

        $service->updateProvider(name: 'mollie', data: ['apiKey' => 'sk_live_original']);
        $first = $this->configStore['payment_provider.mollie.apiKey'];

        $service->updateProvider(name: 'mollie', data: ['apiKey' => PosPaymentService::MASK]);
        $this->assertSame($first, $this->configStore['payment_provider.mollie.apiKey']);

        $service->updateProvider(name: 'mollie', data: ['apiKey' => '']);
        $this->assertSame($first, $this->configStore['payment_provider.mollie.apiKey']);
    }//end testUpdateProviderKeepsStoredSecretWhenMaskOrEmpty()

    public function testUpdateProviderRejectsUnknownProviderName(): void
    {
        $service = $this->buildService();

        $this->expectException(OCSBadRequestException::class);
        $service->updateProvider(name: 'bogus', data: []);
    }//end testUpdateProviderRejectsUnknownProviderName()

    public function testRefundRequiresManager(): void
    {
        $service = $this->buildService(opts: ['isManager' => false]);

        $this->expectException(OCSForbiddenException::class);
        $service->refundPayment(transactionId: 'tx-1', reason: 'why', userId: 'someuser');
    }//end testRefundRequiresManager()

    public function testRefundRejectsUnsettledTransaction(): void
    {
        $object = $this->createMock(originalClassName: ObjectService::class);
        $object->method('find')->willReturn([
            '@self'         => ['id' => 'tx-1'],
            'paymentStatus' => 'pending',
        ]);

        $service = $this->buildService(object: $object, opts: ['isManager' => true]);

        $this->expectException(OCSBadRequestException::class);
        $service->refundPayment(transactionId: 'tx-1', reason: 'why', userId: 'manager');
    }//end testRefundRejectsUnsettledTransaction()

    public function testHandleWebhookInvalidSignatureReturnsInvalid(): void
    {
        $service = $this->buildService();

        // Mollie provider is configured with NO webhookSecret — validateWebhook
        // returns false immediately (empty-secret = fail-closed).
        $result = $service->handleWebhook(
            providerName: 'mollie',
            rawPayload: '{"id":"tr_1","status":"paid"}',
            signature: 'bogus'
        );

        $this->assertSame('invalid', $result['status']);
    }//end testHandleWebhookInvalidSignatureReturnsInvalid()

    public function testHandleWebhookUnknownProviderReturnsInvalid(): void
    {
        $service = $this->buildService();

        $result = $service->handleWebhook(
            providerName: 'paypal',
            rawPayload: '{}',
            signature: 'x'
        );

        $this->assertSame('invalid', $result['status']);
    }//end testHandleWebhookUnknownProviderReturnsInvalid()

    public function testInitiateRejectsUnconfirmedTransaction(): void
    {
        $object = $this->createMock(originalClassName: ObjectService::class);
        $object->method('find')->willReturn([
            '@self'  => ['id' => 'tx-1'],
            'status' => 'draft',
        ]);

        $service = $this->buildService(object: $object);

        $this->expectException(OCSBadRequestException::class);
        $service->initiatePayment(transactionId: 'tx-1', providerName: 'mollie', paymentMethod: 'ideal');
    }//end testInitiateRejectsUnconfirmedTransaction()

    public function testHandleSettlementIsIdempotentOnSameEventId(): void
    {
        $tx = [
            '@self'                 => ['id' => 'tx-1'],
            'paymentStatus'         => 'settled',
            'paymentWebhookEventId' => 'evt-1',
        ];

        $object = $this->createMock(originalClassName: ObjectService::class);
        $object->method('findAll')->willReturn([$tx]);
        $object->method('find')->willReturn($tx);
        // saveObject MUST NOT be called for the duplicate webhook.
        $object->expects($this->never())->method('saveObject');

        $service = $this->buildService(object: $object);

        $result = $service->handleSettlement(
            providerName: 'mollie',
            sessionId: 'tr_1',
            paymentStatus: 'settled',
            eventId: 'evt-1'
        );

        $this->assertSame('duplicate', $result['status']);
    }//end testHandleSettlementIsIdempotentOnSameEventId()

    public function testHandleSettlementIgnoresUnknownSession(): void
    {
        $object = $this->createMock(originalClassName: ObjectService::class);
        $object->method('findAll')->willReturn([]);
        $object->expects($this->never())->method('saveObject');

        $service = $this->buildService(object: $object);

        $result = $service->handleSettlement(
            providerName: 'mollie',
            sessionId: 'unknown_session',
            paymentStatus: 'settled',
            eventId: 'evt-1'
        );

        $this->assertSame('ignored', $result['status']);
    }//end testHandleSettlementIgnoresUnknownSession()
}//end class
