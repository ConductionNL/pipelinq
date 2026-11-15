<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Payment\AbstractPaymentAdapter;
use OCA\Pipelinq\Service\Payment\AdyenAdapter;
use OCA\Pipelinq\Service\Payment\BrokerHttpTransport;
use OCA\Pipelinq\Service\Payment\CcvAdapter;
use OCA\Pipelinq\Service\Payment\MollieAdapter;
use OCA\Pipelinq\Service\Payment\PaymentProviderInterface;
use OCA\Pipelinq\Service\Payment\StripeAdapter;
use OCA\Pipelinq\Service\PosPaymentService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Fake OR ObjectService for the payment coverage tests.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mirrors the real ObjectService signature.
 */
class PayCovObjectService {

	/**
	 * Answer for find() (Throwable is thrown).
	 *
	 * @var mixed
	 */
	public mixed $found = null;

	/**
	 * Answer for findAll() (Throwable is thrown).
	 *
	 * @var mixed
	 */
	public mixed $rows = [];

	/**
	 * Throwable for saveObject(), or null to echo.
	 *
	 * @var Throwable|null
	 */
	public ?Throwable $saveThrows = null;

	/**
	 * Every saveObject() payload.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saves = [];

	/**
	 * Every findAll() config.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $queries = [];

	/**
	 * Answer a find().
	 *
	 * @param string $id The id.
	 * @param string|null $register The register.
	 * @param string|null $schema The schema.
	 *
	 * @return mixed The configured answer.
	 */
	public function find(string $id, ?string $register = null, ?string $schema = null): mixed {
		if ($this->found instanceof Throwable) {
			throw $this->found;
		}

		return $this->found;
	}//end find()

	/**
	 * Answer a findAll().
	 *
	 * @param array<string, mixed> $config The query config.
	 *
	 * @return mixed The configured rows.
	 */
	public function findAll(array $config = []): mixed {
		$this->queries[] = $config;
		if ($this->rows instanceof Throwable) {
			throw $this->rows;
		}

		return $this->rows;
	}//end findAll()

	/**
	 * Record and echo a save.
	 *
	 * @param array<string, mixed> $object The payload.
	 * @param array<string, mixed>|null $extend Extend list.
	 * @param string|null $register The register.
	 * @param string|null $schema The schema.
	 * @param string|null $uuid The uuid.
	 *
	 * @return array<string, mixed> The payload.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		?string $register = null,
		?string $schema = null,
		?string $uuid = null,
	): array {
		if ($this->saveThrows !== null) {
			throw $this->saveThrows;
		}

		$this->saves[] = $object;
		return $object;
	}//end saveObject()
}//end class

/**
 * Fake OR WebhookService recording each dispatched CloudEvent.
 */
class PayCovWebhooks {

	/**
	 * Dispatched event names.
	 *
	 * @var array<int, string>
	 */
	public array $names = [];

	/**
	 * Dispatched payloads.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $payloads = [];

	/**
	 * Record a dispatch.
	 *
	 * @param object $_event The event.
	 * @param string $eventName The name.
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return void
	 */
	public function dispatchEvent(object $_event, string $eventName, array $payload): void {
		$this->names[] = $eventName;
		$this->payloads[] = $payload;
	}//end dispatchEvent()
}//end class

/**
 * Coverage tests for PosPaymentService: provider resolution, capture,
 * connection test, storage failure paths and the manager check.
 *
 * @covers \OCA\Pipelinq\Service\PosPaymentService
 * @uses \OCA\Pipelinq\Service\Payment\AbstractPaymentAdapter
 * @uses \OCA\Pipelinq\Service\Payment\MollieAdapter
 * @uses \OCA\Pipelinq\Service\Payment\CcvAdapter
 * @uses \OCA\Pipelinq\Service\Payment\AdyenAdapter
 * @uses \OCA\Pipelinq\Service\Payment\StripeAdapter
 * @uses \OCA\Pipelinq\Service\Payment\BrokerHttpTransport
 * @uses \OCA\Pipelinq\Service\Payment\PaymentWebhook
 * @uses \OCA\Pipelinq\Service\Payment\PaymentRefund
 * @uses \OCA\Pipelinq\Service\Payment\SettlementUpdate
 * @uses \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses \OCA\Pipelinq\Util\ObjectToPlainArray
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
class PosPaymentServiceCoverageTest extends TestCase {

	/**
	 * App-config store.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Container services by id (Throwable entries are thrown).
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	/**
	 * Logged messages as "level: message".
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Fake ObjectService.
	 *
	 * @var PayCovObjectService
	 */
	private PayCovObjectService $objects;

	/**
	 * Crypto mock.
	 *
	 * @var ICrypto&MockObject
	 */
	private ICrypto $crypto;

	/**
	 * Group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager $groupMgr;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Set up default wiring.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = ['register' => '16', 'posTransaction_schema' => '41'];
		$this->objects = new PayCovObjectService();
		$this->services = ['OCA\\OpenRegister\\Service\\ObjectService' => $this->objects];
		$this->crypto = $this->createMock(ICrypto::class);
		$this->groupMgr = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * Constructor arguments for the current wiring.
	 *
	 * @return array<int, mixed> The arguments.
	 */
	private function args(): array {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				$service = ($this->services[$id] ?? new RuntimeException('unknown ' . $id));
				if ($service instanceof Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logged[] = $level . ': ' . $message;
				}
			);
		}

		return [$container, $appConfig, $this->crypto, $this->groupMgr, $this->userSession, $logger];
	}//end args()

	/**
	 * The real service.
	 *
	 * @return PosPaymentService The service.
	 */
	private function service(): PosPaymentService {
		return new PosPaymentService(...$this->args());
	}//end service()

	/**
	 * The service with getPaymentProvider() answering the given provider.
	 *
	 * @param PaymentProviderInterface $provider The provider.
	 *
	 * @return PosPaymentService&MockObject The partial mock.
	 */
	private function serviceWith(PaymentProviderInterface $provider): PosPaymentService {
		$service = $this->getMockBuilder(PosPaymentService::class)
			->setConstructorArgs($this->args())
			->onlyMethods(['getPaymentProvider'])
			->getMock();
		$service->method('getPaymentProvider')->willReturn($provider);

		return $service;
	}//end serviceWith()

	/**
	 * Read a protected adapter property.
	 *
	 * @param object $adapter The adapter.
	 * @param string $name The property.
	 *
	 * @return mixed The value.
	 */
	private static function prop(object $adapter, string $name): mixed {
		return (new \ReflectionProperty(AbstractPaymentAdapter::class, $name))->getValue($adapter);
	}//end prop()

	/**
	 * Each provider resolves to its adapter with broker placeholders and a decrypted secret.
	 *
	 * @return array<string, array{0: string, 1: class-string}>
	 */
	public static function providerProvider(): array {
		return [
			'mollie' => ['mollie', MollieAdapter::class],
			'ccv' => ['ccv', CcvAdapter::class],
			'adyen' => ['adyen', AdyenAdapter::class],
			'stripe' => ['stripe', StripeAdapter::class],
		];
	}//end providerProvider()

	/**
	 * Each provider resolves to its adapter with broker placeholders and a decrypted secret.
	 *
	 * @param string $name The provider name.
	 * @param class-string $class The expected adapter class.
	 *
	 * @return void
	 *
	 * @dataProvider providerProvider
	 */
	public function testGetPaymentProviderBuildsTheAdapter(string $name, string $class): void {
		$this->config['payment_provider.' . $name . '.credentialId'] = 'cred-1';
		$this->config['payment_provider.' . $name . '.webhookSecret'] = 'ENC';
		$this->config['payment_provider.' . $name . '.environment'] = 'live';
		$this->config['payment_provider.' . $name . '.testMode'] = 'false';
		$this->config['payment_provider.' . $name . '.config'] = '{"0":"ignored","terminalId":"T1"}';
		$this->crypto->expects($this->once())->method('decrypt')->with('ENC')->willReturn('whsec');
		$user = $this->createMock(IUser::class);
		$user->expects($this->once())->method('getUID')->willReturn('alice');
		$this->userSession->expects($this->once())->method('getUser')->willReturn($user);

		$adapter = $this->service()->getPaymentProvider($name);

		$this->assertInstanceOf($class, $adapter);
		$this->assertSame($name, $adapter->getName());
		$this->assertSame(
			[
				'apiKey' => AbstractPaymentAdapter::BROKER_MANAGED_SECRET,
				'apiSecret' => AbstractPaymentAdapter::BROKER_MANAGED_SECRET,
				'webhookSecret' => 'whsec',
			],
			self::prop($adapter, 'credentials')
		);
		$this->assertSame(['environment' => 'live', 'testMode' => false, 'terminalId' => 'T1'], self::prop($adapter, 'config'));
		$this->assertInstanceOf(BrokerHttpTransport::class, self::prop($adapter, 'http'));
	}//end testGetPaymentProviderBuildsTheAdapter()

	/**
	 * Without a stored secret and without a session user the adapter still resolves.
	 *
	 * @return void
	 */
	public function testGetPaymentProviderWithoutSecretOrUser(): void {
		$this->config['payment_provider.mollie.credentialId'] = 'cred-1';
		$this->crypto->expects($this->never())->method('decrypt');
		$this->userSession->expects($this->once())->method('getUser')->willReturn(null);

		$adapter = $this->service()->getPaymentProvider('mollie');

		$this->assertSame('', self::prop($adapter, 'credentials')['webhookSecret']);
		$this->assertSame(['environment' => 'sandbox', 'testMode' => true], self::prop($adapter, 'config'));
	}//end testGetPaymentProviderWithoutSecretOrUser()

	/**
	 * A verified Mollie webhook settles the matched transaction end to end.
	 *
	 * @return void
	 */
	public function testSignedWebhookSettlesThroughTheRealAdapter(): void {
		$this->config['payment_provider.mollie.credentialId'] = 'cred-1';
		$this->config['payment_provider.mollie.webhookSecret'] = 'ENC';
		$this->crypto->method('decrypt')->willReturn('whsec');
		$row = ['id' => 'txn-1', 'paymentSessionId' => 'tr_1', 'paymentStatus' => 'pending'];
		$this->objects->rows = ['results' => [$row]];
		$this->objects->found = $row;
		$webhooks = new PayCovWebhooks();
		$this->services['OCA\\OpenRegister\\Service\\WebhookService'] = $webhooks;

		$body = '{"id":"tr_1","status":"paid","eventId":"ev-1"}';
		$result = $this->service()->handleWebhook('mollie', $body, hash_hmac('sha256', $body, 'whsec'));

		$this->assertSame(['status' => 'ok', 'transactionId' => 'txn-1'], $result);
		$this->assertSame('settled', $this->objects->saves[0]['paymentStatus']);
		$this->assertSame(['pipelinq.PosPayment.settled'], $webhooks->names);
	}//end testSignedWebhookSettlesThroughTheRealAdapter()

	/**
	 * An undecryptable webhook secret is logged and blanked, so the signature fails.
	 *
	 * @return void
	 */
	public function testUndecryptableSecretFailsWebhookValidation(): void {
		$this->config['payment_provider.mollie.credentialId'] = 'cred-1';
		$this->config['payment_provider.mollie.webhookSecret'] = 'ENC';
		$this->crypto->method('decrypt')->willThrowException(new \Exception('bad key'));

		$body = '{"id":"tr_1","status":"paid"}';
		$result = $this->service()->handleWebhook('mollie', $body, hash_hmac('sha256', $body, 'whsec'));

		$this->assertSame(['status' => 'invalid', 'error' => 'Signature mismatch'], $result);
		$this->assertContains('warning: Pipelinq POS payment: failed to decrypt credential', $this->logged);
		$this->assertSame([], $this->objects->queries);
	}//end testUndecryptableSecretFailsWebhookValidation()

	/**
	 * Capture without a payment session is refused.
	 *
	 * @return void
	 */
	public function testCaptureRequiresASession(): void {
		$this->objects->found = ['id' => 'txn-1', 'paymentProvider' => 'mollie'];
		$provider = $this->createMock(PaymentProviderInterface::class);
		$provider->expects($this->never())->method('capture');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('No payment session to capture');

		$this->serviceWith($provider)->capturePayment('txn-1');
	}//end testCaptureRequiresASession()

	/**
	 * A failed capture leaves the transaction untouched.
	 *
	 * @return void
	 */
	public function testFailedCaptureChangesNothing(): void {
		$tx = ['id' => 'txn-1', 'paymentProvider' => 'mollie', 'paymentSessionId' => 'tr_1'];
		$this->objects->found = $tx;
		$provider = $this->createMock(PaymentProviderInterface::class);
		$provider->expects($this->once())->method('capture')->with('tr_1')->willReturn(['status' => 'failed', 'error' => 'x']);

		$result = $this->serviceWith($provider)->capturePayment('txn-1');

		$this->assertSame(['transaction' => $tx, 'payment' => ['status' => 'failed', 'error' => 'x']], $result);
		$this->assertSame([], $this->objects->saves);
	}//end testFailedCaptureChangesNothing()

	/**
	 * A settled capture persists the status and emits the settled event.
	 *
	 * @return void
	 */
	public function testSettledCapturePersistsAndEmits(): void {
		$this->objects->found = ['id' => 'txn-1', 'paymentProvider' => 'mollie', 'paymentSessionId' => 'tr_1'];
		$provider = $this->createMock(PaymentProviderInterface::class);
		$provider->method('capture')->willReturn(['status' => 'settled']);
		$webhooks = new PayCovWebhooks();
		$this->services['OCA\\OpenRegister\\Service\\WebhookService'] = $webhooks;

		$result = $this->serviceWith($provider)->capturePayment('txn-1');

		$this->assertSame(['pipelinq.PosPayment.settled'], $webhooks->names);
		$this->assertSame('txn-1', $webhooks->payloads[0]['data']['transactionId']);

		$this->assertSame('settled', $result['transaction']['paymentStatus']);
		$this->assertSame('settled', $this->objects->saves[0]['paymentStatus']);
	}//end testSettledCapturePersistsAndEmits()

	/**
	 * A non-settled capture persists the status without an event; a failed save keeps the patch.
	 *
	 * @return void
	 */
	public function testCapturedStatusWithFailingSave(): void {
		$this->objects->found = ['id' => 'txn-1', 'paymentProvider' => 'mollie', 'paymentSessionId' => 'tr_1'];
		$this->objects->saveThrows = new RuntimeException('db down');
		$provider = $this->createMock(PaymentProviderInterface::class);
		$provider->method('capture')->willReturn(['status' => 'captured']);
		$webhooks = new PayCovWebhooks();
		$this->services['OCA\\OpenRegister\\Service\\WebhookService'] = $webhooks;

		$result = $this->serviceWith($provider)->capturePayment('txn-1');

		$this->assertSame([], $webhooks->names);

		$this->assertSame('captured', $result['transaction']['paymentStatus']);
		$this->assertSame('txn-1', $result['transaction']['id']);
		$this->assertContains('warning: Pipelinq POS payment: saveTransaction failed', $this->logged);
	}//end testCapturedStatusWithFailingSave()

	/**
	 * Testing an unconfigured provider reports an error without touching settings.
	 *
	 * @return void
	 */
	public function testConnectionOfUnconfiguredProvider(): void {
		$result = $this->service()->testConnection('ccv');

		$this->assertSame(['status' => 'error', 'message' => 'Provider niet geconfigureerd'], $result);
		$this->assertArrayNotHasKey('payment_provider.ccv.lastTestedAt', $this->config);
	}//end testConnectionOfUnconfiguredProvider()

	/**
	 * A connection test records when it ran and what it found.
	 *
	 * @return void
	 */
	public function testConnectionRecordsTheResult(): void {
		$provider = $this->createMock(PaymentProviderInterface::class);
		$provider->expects($this->once())->method('testConnection')->willReturn(['status' => 'ok', 'message' => 'Verbonden']);

		$result = $this->serviceWith($provider)->testConnection('adyen');

		$this->assertSame(['status' => 'ok', 'message' => 'Verbonden'], $result);
		$this->assertSame('{"status":"ok","message":"Verbonden"}', $this->config['payment_provider.adyen.testResult']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $this->config['payment_provider.adyen.lastTestedAt']);
	}//end testConnectionRecordsTheResult()

	/**
	 * Testing an unknown provider is refused.
	 *
	 * @return void
	 */
	public function testConnectionOfUnknownProvider(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Provider paypal is not configured');

		$this->service()->testConnection('paypal');
	}//end testConnectionOfUnknownProvider()

	/**
	 * A config that cannot be JSON-encoded is not written.
	 *
	 * @return void
	 */
	public function testUnencodableConfigIsNotStored(): void {
		$result = $this->service()->updateProvider('mollie', ['config' => ['label' => "\xB1\x31"], 'isActive' => true]);

		$this->assertArrayNotHasKey('payment_provider.mollie.config', $this->config);
		$this->assertSame([], $result['config']);
		$this->assertTrue($result['isActive']);
	}//end testUnencodableConfigIsNotStored()

	/**
	 * Load failures all surface as 404.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: bool}>
	 */
	public static function loadFailureProvider(): array {
		return [
			'empty id' => ['', ['id' => 'x'], false],
			'storage unavailable' => ['txn-1', ['id' => 'x'], true],
			'find throws' => ['txn-1', new RuntimeException('db'), false],
			'find returns scalar' => ['txn-1', 'garbage', false],
		];
	}//end loadFailureProvider()

	/**
	 * Load failures all surface as 404.
	 *
	 * @param string $id The transaction id.
	 * @param mixed $found What find() answers.
	 * @param bool $noStorage Whether the ObjectService is unavailable.
	 *
	 * @return void
	 *
	 * @dataProvider loadFailureProvider
	 */
	public function testLoadFailuresAreNotFound(string $id, mixed $found, bool $noStorage): void {
		$this->objects->found = $found;
		if ($noStorage === true) {
			$this->services['OCA\\OpenRegister\\Service\\ObjectService'] = new RuntimeException('gone');
		}

		try {
			$this->service()->capturePayment($id);
			$this->fail('Expected OCSNotFoundException');
		} catch (OCSNotFoundException $e) {
			$expected = 'Transaction not found';
			if ($noStorage === true) {
				$expected = 'Transaction storage unavailable';
			}

			$this->assertSame($expected, $e->getMessage());
		}
	}//end testLoadFailuresAreNotFound()

	/**
	 * Settlement lookups that cannot match report the settlement as unmatched.
	 *
	 * @return void
	 */
	public function testUnmatchableSettlementLookups(): void {
		$service = $this->service();

		// Empty session id: nothing is queried.
		$this->assertSame(['status' => 'unmatched'], $service->handleSettlement('mollie', '', 'settled', 'e1'));
		$this->assertSame([], $this->objects->queries);

		// A non-array findAll answer yields no rows.
		$this->objects->rows = 'not-a-list';
		$this->assertSame(['status' => 'unmatched'], $service->handleSettlement('mollie', 'tr_1', 'settled', 'e1'));
		$this->assertCount(1, $this->objects->queries);

		// A failing findAll is logged at error level.
		$this->objects->rows = new RuntimeException('index down');
		$this->assertSame(['status' => 'unmatched'], $service->handleSettlement('mollie', 'tr_2', 'settled', 'e2'));
		$this->assertContains('error: Pipelinq POS payment: transaction lookup by session id failed', $this->logged);
	}//end testUnmatchableSettlementLookups()

	/**
	 * Unconfigured or unavailable storage never queries and reports unmatched.
	 *
	 * @return void
	 */
	public function testSettlementWithoutStorage(): void {
		$this->services['OCA\\OpenRegister\\Service\\ObjectService'] = new RuntimeException('gone');
		$this->assertSame(['status' => 'unmatched'], $this->service()->handleSettlement('mollie', 'tr_1', 'settled', 'e1'));

		$this->config['posTransaction_schema'] = '';
		$this->assertSame(['status' => 'unmatched'], $this->service()->handleSettlement('mollie', 'tr_1', 'settled', 'e1'));
		$this->assertContains(
			'warning: Pipelinq POS payment: app-config "posTransaction_schema" is not configured; storage is refused, not run unscoped',
			$this->logged
		);
		$this->assertSame([], $this->objects->queries);
	}//end testSettlementWithoutStorage()

	/**
	 * A refund without an acting user is refused as non-manager.
	 *
	 * @return void
	 */
	public function testRefundWithoutUserIsForbidden(): void {
		$this->groupMgr->expects($this->never())->method('isAdmin');

		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('Manager permission required');

		$this->service()->refundPayment('txn-1', 'Kapot', '');
	}//end testRefundWithoutUserIsForbidden()

	/**
	 * An admin passes the manager check and then needs a reason.
	 *
	 * @return void
	 */
	public function testAdminPassesManagerCheck(): void {
		$this->groupMgr->expects($this->once())->method('isAdmin')->with('root')->willReturn(true);
		$this->groupMgr->expects($this->never())->method('isInGroup');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('A reason is required to reverse a payment');

		$this->service()->refundPayment('txn-1', ' ', 'root');
	}//end testAdminPassesManagerCheck()
}//end class
