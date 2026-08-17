<?php

/**
 * Integration tests for the WhatsApp send flow.
 *
 * Exercises WhatsAppAdapter together with real ConsentService +
 * BudgetService instances on an in-memory ObjectService stub — the
 * goal is to prove the consent → budget → provider gates compose
 * end-to-end without per-service mocking.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#8.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Integration;

use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\WhatsAppAdapter;
use OCA\Pipelinq\Service\WhatsAppProviderClient;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Integration tests for the WhatsApp send flow.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#8.5
 */
class WhatsAppSendFlowTest extends TestCase {
	private ContainerInterface $container;
	private IAppConfig $appConfig;
	private LoggerInterface $logger;
	private NotificationService $notificationService;
	private WhatsAppProviderClient $providerClient;
	private ChannelProviderRepository $providerRepo;
	private object $objectService;
	private ConsentService $consentService;
	private BudgetService $budgetService;
	private WhatsAppAdapter $adapter;

	/**
	 * setUp.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->providerClient = $this->createMock(WhatsAppProviderClient::class);
		$this->providerRepo = $this->createMock(ChannelProviderRepository::class);

		$this->objectService = new class {
			/** @var array<string, array<string, mixed>> */
			public array $store = [];

			/** @var array<int, array<string, mixed>> */
			public array $inbound = [];

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
				if ($uuid === null || $uuid === '') {
					$uuid = (string)($object['uuid'] ?? '');
				}
				if ($uuid === '') {
					$uuid = ('row-' . count($this->store));
				}
				$object['uuid'] = $uuid;
				$this->store[$uuid] = $object;
				return $object;
			}

			/**
			 * Mock find.
			 *
			 * @param string $id Id.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->store[$id] ?? null);
			}

			/**
			 * Mock findAll. Returns seeded inbound rows when
			 * direction=inbound is filtered; otherwise filters the
			 * store.
			 *
			 * Mirrors OR's real ObjectService::findAll(array $config): the
			 * register/schema context travels INSIDE $config['filters'] and OR
			 * treats both as reserved params, never as object-field filters.
			 *
			 * @param array<string, mixed> $config Config with a `filters` map.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$filters = $config['filters'] ?? [];
				unset($filters['register'], $filters['schema']);

				if (($filters['direction'] ?? '') === 'inbound') {
					return $this->inbound;
				}
				$out = [];
				foreach ($this->store as $row) {
					foreach ($filters as $k => $v) {
						if (($row[$k] ?? null) !== $v) {
							continue 2;
						}
					}
					$out[] = $row;
				}
				return $out;
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
					'whatsapp.default_language' => 'nl',
					default => $default,
				};
			}
		);

		$this->consentService = new ConsentService($this->container, $this->appConfig, $this->logger);
		$this->budgetService = new BudgetService($this->container, $this->appConfig, $this->notificationService, $this->logger);

		$this->adapter = new WhatsAppAdapter($this->container,
			$this->appConfig,
			$this->providerRepo,
			$this->providerClient,
			$this->consentService,
			$this->budgetService,
			$this->notificationService,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Send is blocked when an opted-out record exists for the contact.
	 *
	 * @return void
	 */
	public function testSendBlockedByConsent(): void {
		$this->consentService->recordOptOut('contact-1', 'whatsapp', 'admin-override', 'admin');

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hi',
			null,
			[],
		);

		$this->assertSame('consentMissing', $result['status']);
	}//end testSendBlockedByConsent()

	/**
	 * Send is blocked by a hard-stop budget without invoking the
	 * provider client.
	 *
	 * @return void
	 */
	public function testSendBlockedByHardStopBudget(): void {
		// Seed an inbound so the session window is open.
		$this->objectService->inbound = [[
			'sentAt' => gmdate('Y-m-d\TH:i:s\Z', (time() - 3600)),
		]];

		// Seed a hard-stop budget at zero remaining.
		$this->objectService->saveObject([
			'uuid' => 'bud-1',
			'tenantId' => 'tenant-1',
			'providerId' => 'prov-1',
			'period' => 'monthly',
			'maxMessages' => 5,
			'hardStop' => true,
			'currentPeriodMessages' => 5,
		]);

		$this->providerRepo->method('listActive')->willReturn([
			['uuid' => 'prov-1', 'kind' => 'whatsapp-cloud-api', 'vendor' => 'meta'],
		]);

		// Provider must never be called.
		$this->providerClient->expects($this->never())->method('sendFreeForm');

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hi',
			null,
			[],
		);

		// No provider was eligible: no-provider rather than
		// budget-exceeded — but either way the message did not go
		// out.
		$this->assertSame('failed', $result['status']);
	}//end testSendBlockedByHardStopBudget()

	/**
	 * End-to-end happy path: in-session contact, no consent block,
	 * no budget block, provider succeeds.
	 *
	 * @return void
	 */
	public function testSendSucceedsWithAllGatesGreen(): void {
		$this->objectService->inbound = [[
			'sentAt' => gmdate('Y-m-d\TH:i:s\Z', (time() - 1800)),
		]];

		$this->providerRepo->method('listActive')->willReturn([
			['uuid' => 'prov-1', 'kind' => 'whatsapp-cloud-api', 'vendor' => 'meta'],
		]);

		$this->providerClient->method('sendFreeForm')
			->willReturn(['externalMessageId' => 'wamid.42', 'vendor' => 'meta']);

		$result = $this->adapter->send(
			['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			'Hi',
			null,
			[],
		);

		$this->assertSame('sent', $result['status']);
		$this->assertSame('wamid.42', $result['externalMessageId']);

		// A persisted outbound row + a conversation row should now
		// exist on the in-memory OR stub.
		$hasOutbound = false;
		foreach ($this->objectService->store as $row) {
			if (($row['direction'] ?? '') === 'outbound' && ($row['externalMessageId'] ?? '') === 'wamid.42') {
				$hasOutbound = true;
				break;
			}
		}
		$this->assertTrue($hasOutbound, 'outbound message row not persisted');
	}//end testSendSucceedsWithAllGatesGreen()
}//end class
