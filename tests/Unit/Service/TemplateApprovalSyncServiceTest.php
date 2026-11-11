<?php

/**
 * Unit tests for TemplateApprovalSyncService.
 *
 * The sync mirrors each WhatsApp provider's template approval states into
 * the local messageTemplate objects: new remote templates are created,
 * known ones are updated in place, provider statuses are normalised to the
 * four local ones, and a status change notifies the admin. A failing
 * provider, store or notification never stops the sync.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\TemplateApprovalSyncService;
use OCA\Pipelinq\Service\WhatsAppProviderClient;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for TemplateApprovalSyncService.
 *
 * @covers \OCA\Pipelinq\Service\TemplateApprovalSyncService
 */
class TemplateApprovalSyncServiceTest extends TestCase {

	/**
	 * Mock container that hands out the object store double.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock provider repository.
	 *
	 * @var ChannelProviderRepository&MockObject
	 */
	private ChannelProviderRepository&MockObject $providers;

	/**
	 * Mock provider client.
	 *
	 * @var WhatsAppProviderClient&MockObject
	 */
	private WhatsAppProviderClient&MockObject $client;

	/**
	 * Mock notification service.
	 *
	 * @var NotificationService&MockObject
	 */
	private NotificationService&MockObject $notifications;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 *
	 * @var TemplateApprovalSyncService
	 */
	private TemplateApprovalSyncService $service;

	/**
	 * Wire the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(originalClassName: ContainerInterface::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->providers = $this->createMock(originalClassName: ChannelProviderRepository::class);
		$this->client = $this->createMock(originalClassName: WhatsAppProviderClient::class);
		$this->notifications = $this->createMock(originalClassName: NotificationService::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);

		$this->service = new TemplateApprovalSyncService(
			container: $this->container,
			appConfig: $this->appConfig,
			providerRepo: $this->providers,
			providerClient: $this->client,
			notificationService: $this->notifications,
			logger: $this->logger
		);
	}//end setUp()

	/**
	 * An object store double that answers findAll and records saveObject.
	 *
	 * @param array<int, array<string, mixed>> $rows The local templates.
	 * @param bool $failFind Whether findAll throws.
	 * @param bool $failSave Whether saveObject throws.
	 *
	 * @return object
	 */
	private static function store(array $rows, bool $failFind = false, bool $failSave = false): object {
		return new class($rows, $failFind, $failSave) {
			/**
			 * Recorded saves.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * The last findAll config.
			 *
			 * @var array<string, mixed>
			 */
			public array $lastFind = [];

			/**
			 * Store the fixture.
			 *
			 * @param array<int, array<string, mixed>> $rows The rows.
			 * @param bool $failFind Whether findAll throws.
			 * @param bool $failSave Whether saveObject throws.
			 */
			public function __construct(private array $rows, private bool $failFind, private bool $failSave) {
			}

			/**
			 * Return the rows.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config): array {
				if ($this->failFind === true) {
					throw new RuntimeException('index down');
				}

				$this->lastFind = $config;
				return $this->rows;
			}

			/**
			 * Record a save.
			 *
			 * @param array<string, mixed> $object The payload.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param string|null $uuid The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid): array {
				if ($this->failSave === true) {
					throw new RuntimeException('validation failed');
				}

				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				return $object;
			}
		};
	}//end store()

	/**
	 * Hand out the given store, with default register and schema slugs.
	 *
	 * @param object $store The store double.
	 *
	 * @return void
	 */
	private function useStore(object $store): void {
		$this->container->method('get')->willReturn($store);
		$this->appConfig->method('getValueString')->willReturn('');
	}//end useStore()

	/**
	 * A new remote template is created; a known one is updated in place and its status change notified.
	 *
	 * @return void
	 */
	public function testCreatesNewAndUpdatesKnownTemplates(): void {
		$store = self::store(
			rows: [
				['uuid' => 'local-1', 'externalId' => 'welcome', 'language' => 'nl', 'status' => 'pending', 'body' => 'Hoi'],
				['uuid' => 'skip', 'externalId' => ''],
			]
		);
		$this->useStore(store: $store);
		$this->client->method('listTemplates')->willReturn(
			[
				['name' => 'welcome', 'language' => 'nl', 'status' => 'APPROVED'],
				['externalId' => 'reminder', 'language' => 'en', 'status' => 'paused', 'category' => 'marketing', 'body' => 'Hi'],
				['language' => 'nl', 'status' => 'approved'],
			]
		);
		$this->notifications->expects(self::exactly(count: 2))->method('sendNotification')
			->willReturnCallback(
				static function (string $userId, string $subject, array $parameters): bool {
					self::assertSame(expected: 'admin', actual: $userId);
					self::assertSame(expected: 'messaging_template_status_changed', actual: $subject);
					self::assertSame(expected: 'pending', actual: $parameters['oldStatus']);
					return true;
				}
			);

		$summary = $this->service->syncOne(channelProvider: ['id' => 'prov-1']);

		self::assertSame(expected: ['templatesUpdated' => 2, 'statusChanges' => 2], actual: $summary);
		self::assertSame(expected: 'prov-1', actual: $store->lastFind['filters']['providerId']);
		self::assertSame(expected: 'pipelinq', actual: $store->lastFind['filters']['register']);
		self::assertSame(expected: 'messageTemplate', actual: $store->lastFind['filters']['schema']);

		self::assertSame(expected: 'local-1', actual: $store->saved[0]['uuid']);
		self::assertSame(expected: 'approved', actual: $store->saved[0]['object']['status']);
		self::assertSame(expected: 'Hoi', actual: $store->saved[0]['object']['body']);

		self::assertNull(actual: $store->saved[1]['uuid']);
		self::assertSame(expected: 'disabled', actual: $store->saved[1]['object']['status']);
		self::assertSame(expected: 'marketing', actual: $store->saved[1]['object']['category']);
		self::assertSame(expected: 'prov-1', actual: $store->saved[1]['object']['providerId']);
		self::assertMatchesRegularExpression(
			pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			string: $store->saved[1]['object']['lastSyncedAt']
		);
	}//end testCreatesNewAndUpdatesKnownTemplates()

	/**
	 * Provider statuses map to the four local ones; an unchanged status sends no notification.
	 *
	 * @return void
	 */
	public function testNormalisesProviderStatuses(): void {
		$store = self::store(rows: [['id' => 'l', 'externalId' => 'a', 'language' => 'nl', 'status' => 'rejected']]);
		$this->useStore(store: $store);
		$this->client->method('listTemplates')->willReturn(
			[
				['name' => 'a', 'status' => 'FLAGGED'],
				['name' => 'b', 'status' => 'rejected'],
				['name' => 'c', 'status' => 'DISABLED'],
				['name' => 'd', 'status' => 'in_appeal'],
				['name' => 'e'],
			]
		);
		$this->notifications->expects(self::exactly(count: 2))->method('sendNotification');

		$summary = $this->service->syncOne(channelProvider: ['@self' => ['id' => 'prov-2']]);

		self::assertSame(
			expected: ['rejected', 'rejected', 'disabled', 'pending', 'pending'],
			actual: array_map(static fn (array $s): string => $s['object']['status'], $store->saved)
		);
		self::assertSame(expected: ['templatesUpdated' => 5, 'statusChanges' => 2], actual: $summary);
	}//end testNormalisesProviderStatuses()

	/**
	 * A provider without an id, or one whose template list fails, syncs nothing.
	 *
	 * @return void
	 */
	public function testAnUnusableProviderSyncsNothing(): void {
		$this->container->expects(self::never())->method('get');
		$this->client->method('listTemplates')->willThrowException(new RuntimeException('401'));
		$this->logger->expects(self::once())->method('warning')
			->with('TemplateApprovalSyncService.syncOne: listTemplates failed', ['providerId' => 'prov-1', 'exception' => '401']);

		self::assertSame(
			expected: ['templatesUpdated' => 0, 'statusChanges' => 0],
			actual: $this->service->syncOne(channelProvider: ['name' => 'no id'])
		);
		self::assertSame(
			expected: ['templatesUpdated' => 0, 'statusChanges' => 0],
			actual: $this->service->syncOne(channelProvider: ['uuid' => 'prov-1'])
		);
	}//end testAnUnusableProviderSyncsNothing()

	/**
	 * Store and notification failures are logged and do not stop the sync.
	 *
	 * @return void
	 */
	public function testStoreAndNotificationFailuresAreLogged(): void {
		$this->useStore(store: self::store(rows: [], failFind: true, failSave: true));
		$this->client->method('listTemplates')->willReturn([['name' => 'a', 'status' => 'approved']]);
		$this->notifications->method('sendNotification')->willThrowException(new RuntimeException('no app'));

		$messages = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$summary = $this->service->syncOne(channelProvider: ['id' => 'prov-1']);

		self::assertSame(expected: ['templatesUpdated' => 1, 'statusChanges' => 1], actual: $summary);
		self::assertSame(
			expected: [
				'TemplateApprovalSyncService.buildLocalIndex: findAll failed',
				'TemplateApprovalSyncService.saveObject: save failed',
				'TemplateApprovalSyncService.notifyStatusChange: notification failed',
			],
			actual: $messages
		);
	}//end testStoreAndNotificationFailuresAreLogged()

	/**
	 * Without OpenRegister nothing is read or written, but templates are still counted.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsStored(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('not installed'));
		$this->client->method('listTemplates')->willReturn([['name' => 'a', 'status' => 'pending']]);
		$this->notifications->expects(self::never())->method('sendNotification');
		$this->logger->expects(self::exactly(count: 2))->method('warning')
			->with('TemplateApprovalSyncService.getObjectService: OpenRegister unavailable', self::anything());

		self::assertSame(
			expected: ['templatesUpdated' => 1, 'statusChanges' => 0],
			actual: $this->service->syncOne(channelProvider: ['id' => 'prov-1'])
		);
	}//end testWithoutOpenRegisterNothingIsStored()

	/**
	 * A configured register and schema are used instead of the defaults.
	 *
	 * @return void
	 */
	public function testUsesTheConfiguredRegisterAndSchema(): void {
		$store = self::store(rows: []);
		$this->container->method('get')->willReturn($store);
		$this->appConfig->method('getValueString')->willReturnMap(
			[
				['pipelinq', 'register', '', 'crm'],
				['pipelinq', 'messageTemplate_schema', '', 'template'],
			]
		);
		$this->client->method('listTemplates')->willReturn([['name' => 'a', 'status' => 'pending']]);

		$this->service->syncOne(channelProvider: ['id' => 'prov-1']);

		self::assertSame(expected: 'crm', actual: $store->saved[0]['register']);
		self::assertSame(expected: 'template', actual: $store->saved[0]['schema']);
	}//end testUsesTheConfiguredRegisterAndSchema()

	/**
	 * syncAll walks both WhatsApp provider kinds and sums their summaries.
	 *
	 * @return void
	 */
	public function testSyncAllSumsBothProviderKinds(): void {
		$this->useStore(store: self::store(rows: []));
		$this->providers->method('listActive')->willReturnMap(
			[
				['whatsapp-cloud-api', [['id' => 'cloud']]],
				['whatsapp-bsp', [['id' => 'bsp-1'], ['id' => 'bsp-2']]],
			]
		);
		$this->client->method('listTemplates')->willReturn([['name' => 'a', 'status' => 'approved']]);

		self::assertSame(
			expected: ['providers' => 3, 'templatesUpdated' => 3, 'statusChanges' => 3],
			actual: $this->service->syncAll()
		);
	}//end testSyncAllSumsBothProviderKinds()
}//end class
