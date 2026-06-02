<?php

/**
 * Unit tests for PosPaymentService.
 *
 * Exercises the orchestration + webhook security without a live provider or OR:
 * an in-memory fake ObjectService backs the paymentProvider + posTransaction
 * schemas, a fake IClientService hands the service a fake HTTP client, and a
 * fake WebhookService captures emitted CloudEvents. The covered behaviours are
 * the security-critical ones: webhook signature rejection (401), idempotent
 * settlement (a re-delivered event id is a no-op), the settled / refunded
 * status transitions, the settled CloudEvent emission, and the manager gate +
 * settled-state precondition on refund.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosPaymentService;
use OCA\Pipelinq\Tests\Unit\Service\Payment\FakeHttpClient;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

require_once __DIR__.'/Payment/PaymentAdapterTest.php';

/**
 * In-memory ObjectService backing payment provider + transaction schemas.
 */
class FakePaymentObjectService
{
    /** @var array<string, array<string, array<string, mixed>>> */
    public array $store = [];

    /**
     * @param array<string, mixed>|null $object
     */
    public function find(string $id, string $register, string $schema): ?array
    {
        return $this->store[$schema][$id] ?? null;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $config): array
    {
        $filters = $config['filters'] ?? [];
        $schema  = (string) ($filters['schema'] ?? '');
        $rows    = array_values($this->store[$schema] ?? []);

        return array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            foreach (['name', 'paymentSessionId'] as $key) {
                if (isset($filters[$key]) === true && ($row[$key] ?? null) !== $filters[$key]) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array
    {
        $id           = ($uuid !== '') ? $uuid : (string) ($object['id'] ?? uniqid());
        $object['id'] = $id;
        $this->store[$schema][$id] = $object;

        return $object;
    }
}

/**
 * WebhookService capturing emitted CloudEvents.
 */
class FakePaymentWebhookService
{
    /** @var array<int, array{eventName: string, payload: array<string, mixed>}> */
    public array $events = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchEvent(object $_event, string $eventName, array $payload): void
    {
        $this->events[] = ['eventName' => $eventName, 'payload' => $payload];
    }
}

/**
 * Container resolving the OR ObjectService + WebhookService fakes.
 */
class FakePaymentContainer implements ContainerInterface
{
    /**
     * Constructor.
     *
     * @param FakePaymentObjectService  $objects  The object service fake.
     * @param FakePaymentWebhookService $webhooks The webhook service fake.
     */
    public function __construct(
        private FakePaymentObjectService $objects,
        private FakePaymentWebhookService $webhooks,
    ) {
    }

    /**
     * @param string $id The service id.
     *
     * @return mixed
     */
    public function get(string $id): mixed
    {
        if ($id === 'OCA\OpenRegister\Service\ObjectService') {
            return $this->objects;
        }

        if ($id === 'OCA\OpenRegister\Service\WebhookService') {
            return $this->webhooks;
        }

        throw new \RuntimeException("unknown service {$id}");
    }

    /**
     * @param string $id The service id.
     */
    public function has(string $id): bool
    {
        return in_array($id, [
            'OCA\OpenRegister\Service\ObjectService',
            'OCA\OpenRegister\Service\WebhookService',
        ], true);
    }
}

/**
 * IClientService handing out a shared FakeHttpClient.
 */
class FakeClientService implements IClientService
{
    /**
     * Constructor.
     *
     * @param FakeHttpClient $client The shared fake client.
     */
    public function __construct(private FakeHttpClient $client)
    {
    }

    /** {@inheritDoc} */
    public function newClient(): \OCP\Http\Client\IClient
    {
        return $this->client;
    }
}

/**
 * Tests for PosPaymentService.
 */
class PosPaymentServiceTest extends TestCase
{
    /** @var FakePaymentObjectService */
    private FakePaymentObjectService $objects;

    /** @var FakePaymentWebhookService */
    private FakePaymentWebhookService $webhooks;

    /** @var FakeHttpClient */
    private FakeHttpClient $http;

    /** @var IAppConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $appConfig;

    /**
     * Seed the fakes and app-config before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objects  = new FakePaymentObjectService();
        $this->webhooks = new FakePaymentWebhookService();
        $this->http     = new FakeHttpClient();

        // App config: register/schema ids + provider secrets.
        $values = [
            'register'                          => 'reg1',
            'posTransaction_schema'             => 'txn',
            'paymentProvider_schema'            => 'prov',
            'payment_provider_mollie_webhookSecret' => 'shh',
            'payment_provider_mollie_apiKey'    => 'key',
        ];
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default = '', bool $lazy = false): string => ($values[$key] ?? $default)
        );

        // Seed an active Mollie provider config.
        $this->objects->store['prov']['p-mollie'] = [
            'id'       => 'p-mollie',
            'name'     => 'mollie',
            'isActive' => true,
            'environment' => 'sandbox',
            'testMode' => true,
            'config'   => [],
        ];
    }

    /**
     * Build the service with the seeded fakes.
     *
     * @param bool $isManager Whether the access policy reports the user a manager.
     *
     * @return PosPaymentService
     */
    private function service(bool $isManager = false): PosPaymentService
    {
        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('isAdmin')->willReturn($isManager);
        $groupManager->method('isInGroup')->willReturn($isManager);

        $policy = new PosAccessPolicy(appConfig: $this->appConfig, groupManager: $groupManager);

        return new PosPaymentService(
            container: new FakePaymentContainer($this->objects, $this->webhooks),
            appConfig: $this->appConfig,
            clientService: new FakeClientService($this->http),
            policy: $policy,
            logger: new NullLogger()
        );
    }

    /**
     * A webhook with an invalid signature is not authenticated and mutates nothing.
     *
     * @return void
     */
    public function testWebhookInvalidSignatureRejected(): void
    {
        $this->objects->store['txn']['t1'] = [
            'id' => 't1', 'paymentSessionId' => 'tr_X', 'paymentStatus' => 'pending', 'status' => 'confirmed',
        ];

        $result = $this->service()->handleWebhook(providerName: 'mollie', rawBody: 'id=tr_X', signature: 'forged');
        $this->assertFalse($result['authenticated']);
        // The transaction status is untouched (no settlement on a forged webhook).
        $this->assertSame('pending', $this->objects->store['txn']['t1']['paymentStatus']);
        $this->assertEmpty($this->webhooks->events);
    }

    /**
     * A valid Mollie webhook settles the matching transaction and emits the event.
     *
     * @return void
     */
    public function testWebhookSettlesAndEmits(): void
    {
        $this->objects->store['txn']['t1'] = [
            'id' => 't1', 'reference' => 'TXN-1', 'paymentProvider' => 'mollie',
            'paymentSessionId' => 'tr_X', 'paymentStatus' => 'pending', 'status' => 'confirmed', 'total' => 21.53,
        ];
        // Mollie re-fetch returns paid → settled.
        $this->http->queue(200, json_encode(['status' => 'paid']));

        $body      = 'id=tr_X';
        $signature = hash_hmac('sha256', $body, 'shh');
        $result    = $this->service()->handleWebhook(providerName: 'mollie', rawBody: $body, signature: $signature);

        $this->assertSame('processed', $result['status']);
        $this->assertSame('settled', $this->objects->store['txn']['t1']['paymentStatus']);
        $this->assertCount(1, $this->webhooks->events);
        $this->assertSame(PosPaymentService::EVENT_SETTLED, $this->webhooks->events[0]['eventName']);
    }

    /**
     * A re-delivered webhook (same provider event id) is idempotent — no second event.
     *
     * @return void
     */
    public function testWebhookIdempotent(): void
    {
        $this->objects->store['txn']['t1'] = [
            'id' => 't1', 'reference' => 'TXN-1', 'paymentProvider' => 'mollie',
            'paymentSessionId' => 'tr_X', 'paymentStatus' => 'pending', 'status' => 'confirmed', 'total' => 21.53,
        ];
        $this->http->queue(200, json_encode(['status' => 'paid']));
        $this->http->queue(200, json_encode(['status' => 'paid']));

        $body      = 'id=tr_X';
        $signature = hash_hmac('sha256', $body, 'shh');
        $svc       = $this->service();

        $first  = $svc->handleWebhook(providerName: 'mollie', rawBody: $body, signature: $signature);
        $second = $svc->handleWebhook(providerName: 'mollie', rawBody: $body, signature: $signature);

        $this->assertSame('processed', $first['status']);
        $this->assertSame('duplicate', $second['status']);
        // Only ONE settled CloudEvent despite two deliveries.
        $this->assertCount(1, $this->webhooks->events);
    }

    /**
     * A webhook for an unknown session is ignored (no mutation, no throw).
     *
     * @return void
     */
    public function testWebhookUnknownSessionIgnored(): void
    {
        $this->http->queue(200, json_encode(['status' => 'paid']));
        $body      = 'id=tr_NOPE';
        $signature = hash_hmac('sha256', $body, 'shh');

        $result = $this->service()->handleWebhook(providerName: 'mollie', rawBody: $body, signature: $signature);
        $this->assertSame('ignored', $result['status']);
        $this->assertEmpty($this->webhooks->events);
    }

    /**
     * Refund requires manager permission (fail closed).
     *
     * @return void
     */
    public function testRefundRequiresManager(): void
    {
        $this->objects->store['txn']['t1'] = [
            'id' => 't1', 'paymentProvider' => 'mollie', 'paymentSessionId' => 'tr_X',
            'paymentStatus' => 'settled', 'status' => 'settled',
        ];

        $this->expectException(OCSForbiddenException::class);
        $this->service(isManager: false)->refundPayment(transactionId: 't1', reason: 'defect', userId: 'bob');
    }

    /**
     * Refund is rejected when the payment is not settled/captured.
     *
     * @return void
     */
    public function testRefundRequiresSettled(): void
    {
        $this->objects->store['txn']['t1'] = [
            'id' => 't1', 'paymentProvider' => 'mollie', 'paymentSessionId' => 'tr_X',
            'paymentStatus' => 'pending', 'status' => 'confirmed',
        ];

        $this->expectException(OCSBadRequestException::class);
        $this->service(isManager: true)->refundPayment(transactionId: 't1', reason: 'defect', userId: 'mgr');
    }

    /**
     * A manager refund of a settled payment marks it refunded and emits the event.
     *
     * @return void
     */
    public function testManagerRefundSucceeds(): void
    {
        $this->objects->store['txn']['t1'] = [
            'id' => 't1', 'reference' => 'TXN-1', 'paymentProvider' => 'mollie', 'paymentSessionId' => 'tr_X',
            'paymentStatus' => 'settled', 'status' => 'settled', 'total' => 21.53,
        ];
        // Mollie refund API response.
        $this->http->queue(201, json_encode(['id' => 're_1']));

        $result = $this->service(isManager: true)->refundPayment(transactionId: 't1', reason: 'defect', userId: 'mgr');
        $this->assertSame('refunded', $result['status']);
        $this->assertSame('refunded', $this->objects->store['txn']['t1']['paymentStatus']);
        $this->assertSame('defect', $this->objects->store['txn']['t1']['refundReason']);
        $this->assertSame(PosPaymentService::EVENT_REFUNDED, $this->webhooks->events[0]['eventName']);
    }

    /**
     * Initiating against an unconfirmed transaction is rejected.
     *
     * @return void
     */
    public function testInitiateRequiresConfirmed(): void
    {
        $this->objects->store['txn']['t1'] = ['id' => 't1', 'cashier' => 'mgr', 'status' => 'draft', 'total' => 10.0];

        $this->expectException(OCSBadRequestException::class);
        $this->service(isManager: true)->initiatePayment(
            transactionId: 't1',
            providerName: 'mollie',
            paymentMethod: 'ideal',
            userId: 'mgr'
        );
    }

    /**
     * A masked provider list never leaks secrets.
     *
     * @return void
     */
    public function testListProvidersMasksSecrets(): void
    {
        $providers = $this->service()->listProviders();
        $this->assertNotEmpty($providers);
        foreach ($providers as $provider) {
            $this->assertArrayNotHasKey('apiKey', $provider);
            $this->assertArrayNotHasKey('apiSecret', $provider);
            $this->assertArrayNotHasKey('webhookSecret', $provider);
        }
    }
}
