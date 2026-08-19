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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
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
 * In-memory fake of the OR ObjectService that models OpenRegister's
 * REGISTER/SCHEMA CONTEXT RESOLUTION, not merely its method names.
 *
 * A PHPUnit `createMock(ObjectService::class)` cannot see pipelinq#799 at all:
 * `willReturn([$tx])` returns the row whatever the config looked like, so the
 * mis-keyed query and the correct one are indistinguishable to it. That is why
 * a settlement outage that has existed since 2026-06-08 sat under a green
 * suite. This fake reproduces, from `openregister@a4dd9067`:
 *
 *   - `ObjectService::prepareFindAllConfig()` (:1011-1035) sets the query
 *     context ONLY from `$config['filters']['register']` / `['schema']`;
 *   - `findAll()` then hands `$this->currentRegister` / `$this->currentSchema`
 *     to the handler and never restores them;
 *   - `MagicMapper::findAll()` (:8681) logs a warning and returns `[]` — no
 *     exception — when either is null;
 *   - `find()` snapshots and restores the sticky context (BUG-OBJ-13 /
 *     openregister#1520).
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mirrors the real ObjectService signature.
 */
class PaymentFakeObjectService {

	/**
	 * Rows, keyed by schema then by uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Sticky register context.
	 *
	 * @var string
	 */
	public string $currentRegister = '';

	/**
	 * Sticky schema context.
	 *
	 * @var string
	 */
	public string $currentSchema = '';

	/**
	 * Every findAll() config, in call order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $queries = [];

	/**
	 * Every saveObject() payload, in call order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * Seed one row.
	 *
	 * @param string $schema The schema slug.
	 * @param string $uuid The row uuid.
	 * @param array<string, mixed> $data The row body.
	 *
	 * @return void
	 */
	public function seed(string $schema, string $uuid, array $data): void {
		$data['id'] = $uuid;
		$data['@self'] = ['id' => $uuid];
		$this->store[$schema][$uuid] = $data;
	}//end seed()

	/**
	 * Read one row; snapshots and restores the sticky context.
	 *
	 * @param integer|string $id Object id.
	 * @param array|null $_extend Extend list.
	 * @param boolean $files Include files.
	 * @param string|int|null $register Register context.
	 * @param string|int|null $schema Schema context.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		string|int|null $register = null,
		string|int|null $schema = null,
	): ?array {
		$snapshotRegister = $this->currentRegister;
		$snapshotSchema = $this->currentSchema;

		$row = ($this->store[(string)$schema][(string)$id] ?? null);

		$this->currentRegister = $snapshotRegister;
		$this->currentSchema = $snapshotSchema;

		return $row;
	}//end find()

	/**
	 * Query rows. Context comes from `filters` only.
	 *
	 * @param array<string, mixed> $config Query config.
	 * @param boolean $_rbac RBAC posture.
	 * @param boolean $_multitenancy Tenancy posture.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$this->queries[] = $config;

		$filters = (array)($config['filters'] ?? []);

		if (empty($filters['register']) === false && is_array($filters['register']) === false) {
			$this->currentRegister = (string)$filters['register'];
		}

		if (empty($filters['schema']) === false && is_array($filters['schema']) === false) {
			$this->currentSchema = (string)$filters['schema'];
		}

		if ($this->currentRegister === '' || $this->currentSchema === '') {
			return [];
		}

		$rows = array_values($this->store[$this->currentSchema] ?? []);
		unset($filters['register'], $filters['schema']);

		$matched = array_values(
			array_filter($rows,
				static function (array $row) use ($filters): bool {
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							return false;
						}
					}

					return true;
				}
			)
		);

		$limit = (int)($config['limit'] ?? 0);
		if ($limit > 0) {
			$matched = array_slice($matched, 0, $limit);
		}

		return $matched;
	}//end findAll()

	/**
	 * Write a row; sets the sticky context and does not restore it.
	 *
	 * @param array<string, mixed> $object The payload.
	 * @param array|null $extend Extend list.
	 * @param string|int|null $register Register context.
	 * @param string|int|null $schema Schema context.
	 * @param string|null $uuid Uuid for an update.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
	): array {
		$this->currentRegister = (string)$register;
		$this->currentSchema = (string)$schema;

		$object['id'] = (string)$uuid;
		$object['@self'] = ['id' => (string)$uuid];

		$this->store[(string)$schema][(string)$uuid] = $object;
		$this->saves[] = $object;

		return $object;
	}//end saveObject()
}//end class

/**
 * Capturing fake of the OR WebhookService.
 */
class PaymentFakeWebhookService {

	/**
	 * Dispatched event names, in call order.
	 *
	 * @var array<int, string>
	 */
	public array $events = [];

	/**
	 * Capture a dispatched CloudEvent.
	 *
	 * @param object $_event The framework event.
	 * @param string $eventName The event name.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return void
	 */
	public function dispatchEvent(object $_event, string $eventName, array $payload): void {
		$this->events[] = $eventName;
	}//end dispatchEvent()
}//end class

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
class PosPaymentServiceTest extends TestCase {
	/**
	 * In-memory app config storage.
	 *
	 * @var array<string, string>
	 */
	private array $configStore = [
		'register' => 'pipelinq',
		'posTransaction_schema' => 'posTransaction',
		'pos_managers_group' => 'pos_managers',
	];

	/**
	 * Build a service with overridable mocks.
	 *
	 * `$object` is typed `object`, not `ObjectService`, so a test can supply
	 * the contract-faithful `PaymentFakeObjectService` above instead of a
	 * PHPUnit mock. A mock stubbed with `willReturn()` answers the same rows
	 * whatever the query config held, which makes a correctly-keyed query and
	 * a mis-keyed one indistinguishable — the reason pipelinq#799 survived a
	 * green suite for two months.
	 *
	 * @param object|null $object The ObjectService double.
	 * @param array<string, mixed> $opts Optional knobs: isManager (bool), webhooks (object), logger (LoggerInterface).
	 *
	 * @return PosPaymentService
	 */
	private function buildService(?object $object = null, array $opts = []): PosPaymentService {
		$object = ($object ?? $this->createMock(originalClassName: ObjectServiceInterface::class));
		$webhooks = ($opts['webhooks'] ?? null);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			callback: function (string $id) use ($webhooks): mixed {
				if ($id === 'OCA\\OpenRegister\\Service\\WebhookService' && $webhooks !== null) {
					return $webhooks;
				}

				throw new \RuntimeException('container: ' . $id);
			}
		);

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			callback: function (string $app, string $key, string $default = ''): string {
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
				return 'ENC:' . $plain;
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

		$logger = ($opts['logger'] ?? $this->createMock(originalClassName: LoggerInterface::class));

		// The broker's ownership guard needs an identity to check the credential against.
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('alice');
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return new PosPaymentService(
			container: $container,
			objectService: $object,
			appConfig: $appConfig,
			crypto: $crypto,
			groupMgr: $groupMgr,
			userSession: $userSession,
			logger: $logger,
		);
	}//end buildService()

	public function testListProvidersReturnsFourMaskedProviders(): void {
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
	public function testUpdateProviderRefusesTheRetiredApiKeyAndStoresTheCredentialReference(): void {
		$service = $this->buildService();

		$result = $service->updateProvider(
			name: 'mollie',
			data: [
				'isActive' => true,
				'environment' => 'live',
				'apiKey' => 'sk_live_secret',
				'credentialId' => 'cred-uuid-1234',
				'webhookSecret' => 'whsec_xyz',
				'testMode' => false,
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

	/**
	 * A masked or empty resubmission must not overwrite the stored secret.
	 *
	 * ⚠️ This test used to read `payment_provider.mollie.apiKey`, a key the
	 * service stopped writing when the api key moved to the credential broker
	 * — the sibling test above asserts that key is ABSENT. All three reads
	 * were therefore `null`, all three assertions compared `null` to `null`,
	 * and the test was green while measuring nothing (it emitted three
	 * "Undefined array key" warnings per run). Re-pointed at the secret the
	 * service does still hold itself: the webhook secret.
	 *
	 * @return void
	 */
	public function testUpdateProviderKeepsStoredSecretWhenMaskOrEmpty(): void {
		$service = $this->buildService();

		$service->updateProvider(name: 'mollie', data: ['webhookSecret' => 'whsec_original']);
		$first = $this->configStore['payment_provider.mollie.webhookSecret'];
		$this->assertSame('ENC:whsec_original', $first);

		$service->updateProvider(name: 'mollie', data: ['webhookSecret' => PosPaymentService::MASK]);
		$this->assertSame($first, $this->configStore['payment_provider.mollie.webhookSecret']);

		$service->updateProvider(name: 'mollie', data: ['webhookSecret' => '']);
		$this->assertSame($first, $this->configStore['payment_provider.mollie.webhookSecret']);
	}//end testUpdateProviderKeepsStoredSecretWhenMaskOrEmpty()

	public function testUpdateProviderRejectsUnknownProviderName(): void {
		$service = $this->buildService();

		$this->expectException(OCSBadRequestException::class);
		$service->updateProvider(name: 'bogus', data: []);
	}//end testUpdateProviderRejectsUnknownProviderName()

	public function testRefundRequiresManager(): void {
		$service = $this->buildService(opts: ['isManager' => false]);

		$this->expectException(OCSForbiddenException::class);
		$service->refundPayment(transactionId: 'tx-1', reason: 'why', userId: 'someuser');
	}//end testRefundRequiresManager()

	public function testRefundRejectsUnsettledTransaction(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn([
			'@self' => ['id' => 'tx-1'],
			'paymentStatus' => 'pending',
		]);

		$service = $this->buildService(object: $object, opts: ['isManager' => true]);

		$this->expectException(OCSBadRequestException::class);
		$service->refundPayment(transactionId: 'tx-1', reason: 'why', userId: 'manager');
	}//end testRefundRejectsUnsettledTransaction()

	public function testHandleWebhookInvalidSignatureReturnsInvalid(): void {
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

	public function testHandleWebhookUnknownProviderReturnsInvalid(): void {
		$service = $this->buildService();

		$result = $service->handleWebhook(
			providerName: 'paypal',
			rawPayload: '{}',
			signature: 'x'
		);

		$this->assertSame('invalid', $result['status']);
	}//end testHandleWebhookUnknownProviderReturnsInvalid()

	public function testInitiateRejectsUnconfirmedTransaction(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('find')->willReturn([
			'@self' => ['id' => 'tx-1'],
			'status' => 'draft',
		]);

		$service = $this->buildService(object: $object);

		$this->expectException(OCSBadRequestException::class);
		$service->initiatePayment(transactionId: 'tx-1', providerName: 'mollie', paymentMethod: 'ideal');
	}//end testInitiateRejectsUnconfirmedTransaction()

	public function testHandleSettlementIsIdempotentOnSameEventId(): void {
		$tx = [
			'@self' => ['id' => 'tx-1'],
			'paymentStatus' => 'settled',
			'paymentWebhookEventId' => 'evt-1',
		];

		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
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

	/**
	 * A session this instance cannot match is `unmatched`, NOT `ignored`.
	 *
	 * `ignored` is answered 2xx by the controller, which tells the provider
	 * the settlement is booked. Nothing was persisted here, so the delivery
	 * must stay un-acknowledged and be redelivered (pipelinq#799).
	 *
	 * @return void
	 */
	public function testHandleSettlementReportsAnUnknownSessionAsUnmatched(): void {
		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->method('findAll')->willReturn([]);
		$object->expects($this->never())->method('saveObject');

		$service = $this->buildService(object: $object);

		$result = $service->handleSettlement(
			providerName: 'mollie',
			sessionId: 'unknown_session',
			paymentStatus: 'settled',
			eventId: 'evt-1'
		);

		$this->assertSame('unmatched', $result['status']);
		$this->assertNotSame('ignored', $result['status']);
	}//end testHandleSettlementReportsAnUnknownSessionAsUnmatched()

	// -----------------------------------------------------------------
	// pipelinq#799 — the settlement webhook, against a contract-faithful store
	// -----------------------------------------------------------------

	/**
	 * THE #799 REGRESSION GUARD for the webhook path.
	 *
	 * `findTransactionBySessionId()` keyed `register` / `schema` at the TOP
	 * LEVEL of the `findAll()` config. `ObjectService::prepareFindAllConfig()`
	 * resolves the query context only from `$config['filters']`, so the query
	 * ran with no context, `MagicMapper::findAll()` returned `[]`, the lookup
	 * reported `null`, and `handleSettlement()` discarded the delivery — at
	 * HTTP 200, which no provider retries.
	 *
	 * The assertion is on the SEEDED transaction and its persisted patch, not
	 * on a row count: an unscoped read and a scoped one look identical to a
	 * count.
	 *
	 * @return void
	 */
	public function testSettlementWebhookMatchesTheSeededTransactionAndPersistsIt(): void {
		$store = new PaymentFakeObjectService();
		$store->seed(
			schema: 'posTransaction',
			uuid: 'tx-live-1',
			data: [
				'reference' => 'TXN-0007',
				'paymentSessionId' => 'tr_live_1',
				'paymentStatus' => 'pending',
				'total' => 27.59,
			],
		);
		// A second transaction with a different session must not be touched.
		$store->seed(
			schema: 'posTransaction',
			uuid: 'tx-live-2',
			data: [
				'reference' => 'TXN-0008',
				'paymentSessionId' => 'tr_live_2',
				'paymentStatus' => 'pending',
				'total' => 10.00,
			],
		);

		$webhooks = new PaymentFakeWebhookService();
		$service = $this->buildService(object: $store, opts: ['webhooks' => $webhooks]);

		// Nothing has written first — this is the webhook request, which is
		// the whole request. There is no sticky context to inherit.
		$this->assertSame('', $store->currentRegister);
		$this->assertSame('', $store->currentSchema);

		$result = $service->handleSettlement(
			providerName: 'mollie',
			sessionId: 'tr_live_1',
			paymentStatus: 'settled',
			eventId: 'evt-live-1'
		);

		$this->assertSame('ok', $result['status']);
		$this->assertSame('tx-live-1', $result['transactionId']);

		$persisted = $store->store['posTransaction']['tx-live-1'];
		$this->assertSame('settled', $persisted['paymentStatus']);
		$this->assertSame('evt-live-1', $persisted['paymentWebhookEventId']);
		$this->assertArrayHasKey('settledAt', $persisted);
		$this->assertSame('TXN-0007', $persisted['reference']);

		// The neighbour is untouched.
		$this->assertSame('pending', $store->store['posTransaction']['tx-live-2']['paymentStatus']);

		// And the downstream settled CloudEvent actually fired.
		$this->assertSame(['pipelinq.PosPayment.settled'], $webhooks->events);
	}//end testSettlementWebhookMatchesTheSeededTransactionAndPersistsIt()

	/**
	 * The session lookup must carry its context INSIDE `filters`.
	 *
	 * `findAll()`'s own docblock advertises `register` / `schema` as top-level
	 * config keys and never reads them there — which is how eleven sites in
	 * this app came to be written the wrong way (#793). Pinning the emitted
	 * query shape makes a regression fail here by name.
	 *
	 * @return void
	 */
	public function testSessionLookupQueriesWithContextInsideFiltersOnly(): void {
		$store = new PaymentFakeObjectService();
		$store->seed(
			schema: 'posTransaction',
			uuid: 'tx-1',
			data: ['paymentSessionId' => 'tr_1', 'paymentStatus' => 'pending'],
		);

		$service = $this->buildService(object: $store, opts: ['webhooks' => new PaymentFakeWebhookService()]);
		$service->handleSettlement(
			providerName: 'mollie',
			sessionId: 'tr_1',
			paymentStatus: 'settled',
			eventId: 'evt-1'
		);

		$this->assertNotSame([], $store->queries);
		$config = $store->queries[0];

		$this->assertSame('tr_1', $config['filters']['paymentSessionId']);
		$this->assertSame('pipelinq', $config['filters']['register']);
		$this->assertSame('posTransaction', $config['filters']['schema']);
		$this->assertArrayNotHasKey('register', $config);
		$this->assertArrayNotHasKey('schema', $config);
		// `limit: 2` is the duplicate probe and must survive the re-keying.
		$this->assertSame(2, $config['limit']);
	}//end testSessionLookupQueriesWithContextInsideFiltersOnly()

	/**
	 * `limit: 2` exists to detect a duplicate `paymentSessionId`; the second
	 * row was never counted, so the implied check did not exist. Two rows
	 * sharing a session id is data corruption on the money path and must be
	 * logged at error level, not silently resolved to whichever row came back
	 * first.
	 *
	 * @return void
	 */
	public function testDuplicatePaymentSessionIdIsReportedAtErrorLevel(): void {
		$store = new PaymentFakeObjectService();
		$store->seed(schema: 'posTransaction', uuid: 'tx-a', data: ['paymentSessionId' => 'tr_dup', 'paymentStatus' => 'pending']);
		$store->seed(schema: 'posTransaction', uuid: 'tx-b', data: ['paymentSessionId' => 'tr_dup', 'paymentStatus' => 'pending']);

		$messages = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			callback: function (string|\Stringable $message, array $context = []) use (&$messages): void {
				$messages[] = (string)$message;
			}
		);

		$service = $this->buildService(object: $store, opts: ['logger' => $logger]);

		$result = $service->handleSettlement(
			providerName: 'mollie',
			sessionId: 'tr_dup',
			paymentStatus: 'settled',
			eventId: 'evt-dup'
		);

		$this->assertSame('ok', $result['status']);
		$this->assertNotSame([], $messages);
		$this->assertStringContainsString('paymentSessionId is not unique', implode("\n", $messages));
	}//end testDuplicatePaymentSessionIdIsReportedAtErrorLevel()

	/**
	 * An unconfigured register/posTransaction_schema refuses the read AND
	 * never reaches OpenRegister.
	 *
	 * An empty id is not the same as "no id" to OpenRegister: ObjectService
	 * skips setRegister()/setSchema() on '' and the call would run under
	 * whatever register/schema context an earlier call in the same request
	 * left on the shared instance. Nothing may reach the ObjectService.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterRefusesAndNeverCallsOpenRegister(): void {
		unset($this->configStore['register'], $this->configStore['posTransaction_schema']);

		$object = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$object->expects($this->never())->method('findAll');
		$object->expects($this->never())->method('saveObject');

		$service = $this->buildService(object: $object);

		$this->expectException(OCSNotFoundException::class);
		$service->capturePayment(transactionId: 'txn-1');
	}//end testUnconfiguredRegisterRefusesAndNeverCallsOpenRegister()
}//end class
