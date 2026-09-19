<?php

/**
 * Contract-ring integration tests for the outbound messaging pipeline.
 *
 * Drives SmsAdapter / WhatsAppAdapter send() end-to-end against an in-memory
 * OpenRegister stub with the vendor clients returning the canned mock-mode
 * vendor shapes (`wamid.MOCK…` / `MOCK-SMS…`) that OpenConnector's mock-flagged
 * sources emit — zero external network. Asserts the persisted outbound message
 * row and the outbound contactmoment audit (REQ-OM-006) written through the
 * unified ContactmomentService write path resolved via the container.
 *
 * Since unify-ticket-supertype the audit lands on the `ticket` schema with
 * `ticketType: contactmoment` and the renamed ticket fields.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Integration
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/outbound-messaging-provider-wiring/specs/outbound-messaging/spec.md#requirement-req-om-007--contract-tests-in-ci-and-the-live-gate
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Integration;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactmomentService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\Provider\SmsProviderClientInterface;
use OCA\Pipelinq\Service\SmsAdapter;
use OCA\Pipelinq\Service\SmsProviderFactory;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Outbound messaging contract ring (network-free, mock vendor shapes).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class OutboundMessagingContractTest extends TestCase {
	private ContainerInterface $container;
	private object $objectService;
	private ChannelProviderRepository $providerRepo;
	private SmsProviderFactory $providerFactory;
	private SmsAdapter $adapter;

	/**
	 * Wire an in-memory OR stub + a real ContactmomentService in the container.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);

		// The double EXTENDS the ObjectService stub, so it satisfies the
		// injected `ObjectServiceInterface` type hint the services now carry
		// (ADR-084). Both overrides reproduce the contract signatures exactly —
		// PHP checks compatibility at CLASS-LOAD time, so a narrower one is a
		// fatal before test 1 rather than a test failure.
		$this->objectService = new class extends \OCA\OpenRegister\Service\ObjectService {
			/** @var array<string, array<string, mixed>> */
			public array $store = [];

			/**
			 * @param array<string, mixed> $object Payload.
			 * @param array<string, mixed>|null $extend Extend list.
			 * @param string|int|null $register Register.
			 * @param string|int|null $schema Schema.
			 * @param string|null $uuid Id.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 * @param bool $silent Unused.
			 * @param bool $_validation Unused.
			 * @param array<string, mixed>|null $uploadedFiles Unused.
			 * @param IUser|null $currentUser Unused.
			 * @param bool $failIfExists Unused.
			 *
			 * @return ObjectEntityInterface The stored row, wrapped as an entity.
			 */
			public function saveObject(
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $silent = false,
				bool $_validation = true,
				?array $uploadedFiles = null,
				?IUser $currentUser = null,
				bool $failIfExists = false,
			): ObjectEntityInterface {
				if ($uuid === null || $uuid === '') {
					$uuid = ('row-' . count($this->store));
				}

				$object['uuid'] = $uuid;
				$object['_schema'] = (string)$schema;
				$this->store[$uuid] = $object;

				$entity = new ObjectEntity();
				$entity->setUuid($uuid);
				$entity->setRegister((string)$register);
				$entity->setSchema((string)$schema);
				$entity->setObject($object);

				return $entity;
			}

			/**
			 * @param int|string $id Id.
			 * @param array<string, mixed>|null $_extend Unused.
			 * @param bool $files Unused.
			 * @param string|int|null $register Register.
			 * @param string|int|null $schema Schema.
			 * @param bool $_rbac Unused.
			 * @param bool $_multitenancy Unused.
			 * @param bool $_render Unused.
			 * @param bool $_audit Unused.
			 *
			 * @return ObjectEntityInterface|null The row, wrapped as an entity.
			 */
			public function find(
				int|string $id,
				?array $_extend = [],
				bool $files = false,
				string|int|null $register = null,
				string|int|null $schema = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
				bool $_render = true,
				bool $_audit = true,
			): ?ObjectEntityInterface {
				$row = ($this->store[(string)$id] ?? null);
				if ($row === null) {
					return null;
				}

				$entity = new ObjectEntity();
				$entity->setUuid((string)$id);
				$entity->setRegister((string)$register);
				$entity->setSchema((string)$schema);
				$entity->setObject($row);

				return $entity;
			}

			/**
			 * Mirrors OR's real ObjectService::findAll(array $config).
			 *
			 * @param array<string, mixed> $config Config with a `filters` map.
			 * @param bool $_rbac RBAC posture.
			 * @param bool $_multitenancy Tenancy posture.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return [];
			}
		};

		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				return match ($key) {
					'register' => 'pipelinq',
					'tenant_id' => 'tenant-1',
					'ticket_schema' => 'ticket',
					default => $default,
				};
			}
		);

		$contactmomentService = new ContactmomentService(
			new TicketService($appConfig, $logger,
				objectService: $this->objectService,
			),
			$this->createMock(IGroupManager::class),
			$logger,
			objectService: $this->objectService,
		);

		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($contactmomentService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}

				if ($id === 'OCA\\Pipelinq\\Service\\ContactmomentService') {
					return $contactmomentService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$this->providerRepo = $this->createMock(ChannelProviderRepository::class);
		$this->providerFactory = $this->createMock(SmsProviderFactory::class);

		$this->adapter = new SmsAdapter($this->container,
			$appConfig,
			$this->providerRepo,
			$this->providerFactory,
			new ConsentService($this->container, $appConfig, $logger),
			new BudgetService($this->container, $appConfig, $this->createMock(NotificationService::class), $logger),
			$this->createMock(NotificationService::class),
			$logger,
		);
	}//end setUp()

	/**
	 * A vendor client returning the canned mock-mode SMS id.
	 *
	 * @return SmsProviderClientInterface
	 */
	private function mockModeClient(): SmsProviderClientInterface {
		return new class implements SmsProviderClientInterface {
			/**
			 * @param string $toNumber Recipient.
			 * @param string $body Body.
			 *
			 * @return array<string, mixed>
			 */
			public function send(string $toNumber, string $body): array {
				// Shape emitted by OpenConnector's mock-flagged messagebird-sms
				// source (configuration.mock: true → canned mockResponse).
				return ['externalMessageId' => 'MOCK-SMS-0001', 'vendor' => 'messagebird'];
			}

			/**
			 * @param string $rawBody Raw body.
			 * @param string $signature Signature.
			 *
			 * @return bool
			 */
			public function verifySignature(string $rawBody, string $signature): bool {
				return true;
			}

			/**
			 * @return string
			 */
			public function getVendor(): string {
				return 'messagebird';
			}
		};
	}//end mockModeClient()

	/**
	 * A network-free SMS send persists the outbound row with the mock id and
	 * writes an outbound `channel: sms` contactmoment-ticket audit (REQ-OM-006).
	 *
	 * @return void
	 */
	public function testSmsSendPersistsAndAudits(): void {
		$this->providerRepo->method('listActive')->willReturn([
			['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird', 'sourceId' => 'messagebird-sms'],
		]);
		$this->providerFactory->method('create')->willReturn($this->mockModeClient());

		$result = $this->adapter->send(
			contact: ['uuid' => 'contact-1', 'phoneNumber' => '+31611111111', 'client' => 'client-1'],
			body: 'Your request is being handled.',
			providerHint: null,
			context: ['agent' => 'agent-1', 'clientId' => 'client-1'],
		);

		$this->assertSame('sent', $result['status']);
		$this->assertSame('MOCK-SMS-0001', $result['externalMessageId']);

		$outbound = $this->rowsBySchema(schema: 'channelMessage');
		$this->assertNotEmpty($outbound, 'outbound message row not persisted');

		$contactmomenten = $this->rowsBySchema(schema: 'ticket');
		$this->assertCount(1, $contactmomenten, 'exactly one outbound contactmoment audit row expected');
		$audit = $contactmomenten[0];
		$this->assertSame('interaction', $audit['ticketType']);
		$this->assertSame('sms', $audit['channel']);
		$this->assertSame('client-1', $audit['client']);
		// Ticket field names: subject → title, summary → description, agent → assignee.
		$this->assertSame('Outbound SMS', $audit['title']);
		$this->assertSame('Your request is being handled.', $audit['description']);
		$this->assertSame('agent-1', $audit['assignee']);
		$this->assertSame('outbound', $audit['channelMetadata']['direction']);
		$this->assertSame('sms', $audit['channelMetadata']['platform']);
	}//end testSmsSendPersistsAndAudits()

	/**
	 * The contactmoment audit is log-and-continue: a send still reports `sent`
	 * even when the contactmoment write is impossible (schema unconfigured).
	 *
	 * @return void
	 */
	public function testAuditFailureNeverBlocksSend(): void {
		// Fully isolated wiring: the ticket schema is unconfigured, so
		// ContactmomentService.getConfig throws → recordOutboundMessage returns
		// null → the send is unaffected.
		$logger = $this->createMock(LoggerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				return match ($key) {
					'register' => 'pipelinq',
					'tenant_id' => 'tenant-1',
					default => $default,
				};
			}
		);

		$store = $this->objectService;
		$container = $this->createMock(ContainerInterface::class);
		$contactmomentService = new ContactmomentService(
			new TicketService($appConfig, $logger,
				objectService: $this->createMock(ObjectServiceInterface::class),
			),
			$this->createMock(IGroupManager::class),
			$logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($store, $contactmomentService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $store;
				}

				if ($id === 'OCA\\Pipelinq\\Service\\ContactmomentService') {
					return $contactmomentService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$adapter = new SmsAdapter($container,
			$appConfig,
			$this->providerRepo,
			$this->providerFactory,
			new ConsentService($container, $appConfig, $logger),
			new BudgetService($container, $appConfig, $this->createMock(NotificationService::class), $logger),
			$this->createMock(NotificationService::class),
			$logger,
		);

		$this->providerRepo->method('listActive')->willReturn([
			['uuid' => 'prov-1', 'kind' => 'sms', 'vendor' => 'messagebird', 'sourceId' => 'messagebird-sms'],
		]);
		$this->providerFactory->method('create')->willReturn($this->mockModeClient());

		$result = $adapter->send(
			contact: ['uuid' => 'contact-1', 'phoneNumber' => '+31611111111'],
			body: 'Handled.',
		);

		$this->assertSame('sent', $result['status']);
		$this->assertSame([], $this->rowsBySchema(schema: 'ticket'));
	}//end testAuditFailureNeverBlocksSend()

	/**
	 * All persisted rows written under the given schema.
	 *
	 * @param string $schema Schema slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rowsBySchema(string $schema): array {
		$out = [];
		foreach ($this->objectService->store as $row) {
			if (($row['_schema'] ?? '') === $schema) {
				$out[] = $row;
			}
		}

		return $out;
	}//end rowsBySchema()
}//end class
