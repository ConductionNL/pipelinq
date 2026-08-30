<?php

/**
 * Unit tests for SmsAdapter.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#8.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\Provider\PermanentSmsProviderException;
use OCA\Pipelinq\Service\Provider\SmsProviderClientInterface;
use OCA\Pipelinq\Service\Provider\TransientSmsProviderException;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\SmsProviderFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SmsAdapter — priority failover, provider hint, consent
 * gate, inbound webhook signature verification.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#8.2
 */
class SmsAdapterTest extends TestCase {
	private ContainerInterface $container;
	private IAppConfig $appConfig;
	private ChannelProviderRepository $providerRepo;
	private SmsProviderFactory $providerFactory;
	private ConsentService $consentService;
	private BudgetService $budgetService;
	private NotificationService $notificationService;
	private LoggerInterface $logger;
	private object $objectService;
	private SmsAdapter $adapter;

	/**
	 * setUp.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->providerRepo = $this->createMock(ChannelProviderRepository::class);
		$this->providerFactory = $this->createMock(SmsProviderFactory::class);
		$this->consentService = $this->createMock(ConsentService::class);
		$this->budgetService = $this->createMock(BudgetService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->objectService = new class {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];

			/**
			 * Mock saveObject.
			 *
			 * @param array $object Payload.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 * @param string|null $uuid Id.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				$object['uuid'] = ($uuid ?? ('row-' . count($this->saved)));
				$this->saved[] = $object;
				return $object;
			}

			/**
			 * Mock findAll — always empty (no conversation / contact match).
			 *
			 * Mirrors OR's real ObjectService::findAll(array $config).
			 *
			 * @param array<string, mixed> $config Config with a `filters` map.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				return [];
			}
		};

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}
				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) {
				return match ($key) {
					'register' => 'pipelinq',
					'tenant_id' => 'tenant-1',
					default => $default,
				};
			}
		);

		$this->adapter = new SmsAdapter($this->container,
			$this->appConfig,
			$this->providerRepo,
			$this->providerFactory,
			$this->consentService,
			$this->budgetService,
			$this->notificationService,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Build a stub SMS provider client that returns the configured
	 * outcome on send / verifySignature.
	 *
	 * @param string $vendor Vendor key.
	 * @param string $sendOutcome 'success' / 'transient' / 'permanent'.
	 * @param string $externalId Provider id on success.
	 * @param bool $sigVerifies Signature outcome.
	 *
	 * @return SmsProviderClientInterface
	 */
	private function buildClient(
		string $vendor,
		string $sendOutcome,
		string $externalId = 'ext-1',
		bool $sigVerifies = true,
	): SmsProviderClientInterface {
		return new class($vendor, $sendOutcome, $externalId, $sigVerifies) implements SmsProviderClientInterface {
			/** @var array<int, array{to: string, body: string}> */
			public array $calls = [];
			private string $vendor;
			private string $sendOutcome;
			private string $externalId;
			private bool $sigVerifies;

			public function __construct(string $vendor, string $sendOutcome, string $externalId, bool $sigVerifies) {
				$this->vendor = $vendor;
				$this->sendOutcome = $sendOutcome;
				$this->externalId = $externalId;
				$this->sigVerifies = $sigVerifies;
			}

			public function send(string $toNumber, string $body): array {
				$this->calls[] = ['to' => $toNumber, 'body' => $body];
				if ($this->sendOutcome === 'transient') {
					throw new TransientSmsProviderException('simulated 5xx');
				}
				if ($this->sendOutcome === 'permanent') {
					throw new PermanentSmsProviderException('simulated 4xx');
				}
				return ['externalMessageId' => $this->externalId, 'vendor' => $this->vendor];
			}

			public function verifySignature(string $rawBody, string $signature): bool {
				return $this->sigVerifies;
			}

			public function getVendor(): string {
				return $this->vendor;
			}
		};
	}//end buildClient()

	/**
	 * Send fails over from primary (transient) to secondary
	 * (success) without exposing the failover to the caller.
	 *
	 * @return void
	 */
	public function testSendFailsOverOnTransient(): void {
		$primary = ['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird', 'priority' => 1];
		$secondary = ['uuid' => 'prov-2', 'kind' => 'sms', 'vendor' => 'twilio',      'priority' => 2];

		$this->providerRepo->method('listActive')->willReturn([$primary, $secondary]);
		$this->consentService->method('canSend')->willReturn(true);
		$this->budgetService->method('canSend')->willReturn(true);

		$this->providerFactory
			->expects($this->exactly(2))
			->method('create')
			->willReturnOnConsecutiveCalls($this->buildClient('messagebird', 'transient'),
				$this->buildClient('twilio', 'success', 'twilio-sid'),
			);

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hello',
		);

		$this->assertSame('sent', $result['status']);
		$this->assertSame('twilio', $result['vendor']);
		$this->assertSame('twilio-sid', $result['externalMessageId']);
	}//end testSendFailsOverOnTransient()

	/**
	 * Both providers transient → persisted as failed + admin
	 * notified.
	 *
	 * @return void
	 */
	public function testSendAllProvidersTransientPersistsFailedAndAlerts(): void {
		$primary = ['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird', 'priority' => 1];
		$secondary = ['uuid' => 'prov-2', 'kind' => 'sms', 'vendor' => 'twilio',      'priority' => 2];

		$this->providerRepo->method('listActive')->willReturn([$primary, $secondary]);
		$this->consentService->method('canSend')->willReturn(true);
		$this->budgetService->method('canSend')->willReturn(true);

		$this->providerFactory
			->method('create')
			->willReturnOnConsecutiveCalls($this->buildClient('messagebird', 'transient'),
				$this->buildClient('twilio', 'transient'),
			);

		$this->notificationService->expects($this->once())
			->method('sendNotification');

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hello',
		);

		$this->assertSame('failed', $result['status']);
	}//end testSendAllProvidersTransientPersistsFailedAndAlerts()

	/**
	 * providerHint pins a vendor and skips failover.
	 *
	 * @return void
	 */
	public function testSendProviderHintIsPinned(): void {
		$cmcom = ['uuid' => 'prov-3', 'kind' => 'sms', 'vendor' => 'cm-com', 'priority' => 3];
		$this->providerRepo->method('findByVendor')->willReturn($cmcom);
		$this->consentService->method('canSend')->willReturn(true);
		$this->budgetService->method('canSend')->willReturn(true);

		$this->providerFactory
			->expects($this->once())
			->method('create')
			->with($this->equalTo($cmcom))
			->willReturn($this->buildClient('cm-com', 'success', 'cm-sid'));

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hello',
			'cm-com',
		);

		$this->assertSame('sent', $result['status']);
		$this->assertSame('cm-com', $result['vendor']);
	}//end testSendProviderHintIsPinned()

	/**
	 * Consent-missing returns consentMissing without calling a provider.
	 *
	 * @return void
	 */
	public function testSendRefusedWhenConsentMissing(): void {
		$this->consentService->method('canSend')->willReturn(false);

		$this->providerFactory->expects($this->never())->method('create');

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hello',
		);

		$this->assertSame('consentMissing', $result['status']);
	}//end testSendRefusedWhenConsentMissing()

	/**
	 * Inbound webhook with invalid signature returns invalidSignature
	 * (the controller maps this to HTTP 400).
	 *
	 * @return void
	 */
	public function testHandleInboundWebhookInvalidSignature(): void {
		$row = ['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'twilio'];
		$this->providerRepo->method('findById')->willReturn($row);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('twilio', 'success', 'ext-1', false));

		$result = $this->adapter->handleInboundWebhook('body', 'bad-sig', 'prov-1');

		$this->assertSame('invalidSignature', $result['status']);
	}//end testHandleInboundWebhookInvalidSignature()

	/**
	 * Inbound webhook with valid signature persists the message and
	 * fires placeholder creation (the OR mock returns no existing
	 * contact).
	 *
	 * @return void
	 */
	public function testHandleInboundWebhookPersistsAndRoutes(): void {
		$row = ['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'twilio'];
		$this->providerRepo->method('findById')->willReturn($row);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('twilio', 'success', 'ext-1', true));

		$this->consentService->method('isOptOutKeyword')->willReturn(false);
		$this->consentService->method('isOptInKeyword')->willReturn(false);

		$rawBody = json_encode(['From' => '+31600000000', 'Body' => 'hello']);
		$result = $this->adapter->handleInboundWebhook($rawBody, 'sig', 'prov-1');

		$this->assertSame('received', $result['status']);
		$this->assertTrue($result['placeholderCreated']);
	}//end testHandleInboundWebhookPersistsAndRoutes()

	/**
	 * STOP keyword on inbound triggers opt-out recording.
	 *
	 * @return void
	 */
	public function testHandleInboundWebhookStopKeywordOptsOut(): void {
		$row = ['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'twilio'];
		$this->providerRepo->method('findById')->willReturn($row);
		$this->providerFactory->method('create')
			->willReturn($this->buildClient('twilio', 'success', 'ext-1', true));

		$this->consentService->method('isOptOutKeyword')->willReturn(true);
		$this->consentService->expects($this->once())
			->method('recordOptOut')
			->with($this->isType('string'),
				$this->equalTo('sms'),
				$this->equalTo('keyword-stop'),
				$this->stringContains('STOP'),
			);

		$rawBody = json_encode(['From' => '+31600000000', 'Body' => 'STOP']);
		$result = $this->adapter->handleInboundWebhook($rawBody, 'sig', 'prov-1');

		$this->assertSame('received', $result['status']);
		$this->assertTrue($result['optOutRecorded']);
	}//end testHandleInboundWebhookStopKeywordOptsOut()
}//end class
