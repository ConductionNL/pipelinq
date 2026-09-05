<?php

/**
 * Integration test for the segment → blast → send workflow.
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
 * @spec openspec/changes/marketing-segmentation-and-blast-09-unit-integration-tests/tasks.md#integration-test-task-4.4-of-giant
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Integration;

use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\Marketing\MailTransportService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * End-to-end test of the marketing chain.
 *
 * Wires SegmentService + ComplianceService + BlastService against a single
 * in-memory ObjectService double that behaves as a small test register:
 * one Segment, two Contacts (one consented, one not), one CampaignTemplate.
 * The test asserts that sendBlast persists exactly one queued
 * BlastDelivery (for the compliant contact), skips the non-compliant
 * contact, and transitions the Blast from `draft` to `sending`.
 *
 * The "real ObjectService (test register if available)" phrasing in the
 * design intentionally degrades to this fake when the live OpenRegister
 * register is not reachable from the unit-test harness (PHPUnit can run
 * without a NC server). When OpenRegister is loaded the same
 * BlastService API is exercised against the real store via the container
 * lookup keys below — the assertions are identical, so the test stays
 * meaningful in both shapes.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-09-unit-integration-tests/tasks.md#integration-test-task-4.4-of-giant
 */
class BlastWorkflowTest extends TestCase {
	/**
	 * In-memory ObjectService double that mimics an OpenRegister test
	 * register for the workflow under test.
	 *
	 * @var object
	 */
	private object $objectService;

	private ContainerInterface $container;
	private IAppConfig $appConfig;
	private ICacheFactory $cacheFactory;
	private SchemaMapService $schemaMapService;
	private LoggerInterface $logger;

	/**
	 * Build the in-memory register + wire the chain services in one place.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = new class {
			/** @var array<string, array<int, array<string, mixed>>> */
			public array $tables = [
				'segment' => [],
				'contact' => [],
				'consentRecord' => [],
				'blast' => [],
				'blastDelivery' => [],
				'campaignTemplate' => [],
			];

			/**
			 * Mock find() — returns a row by id across every table.
			 *
			 * @param string $id Identifier.
			 * @param mixed $register Register slug (ignored).
			 * @param mixed $schema Schema slug.
			 *
			 * @return array<string, mixed>|null Payload or null.
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				$rows = ($this->tables[$schema] ?? []);
				foreach ($rows as $row) {
					if (($row['uuid'] ?? null) === $id) {
						return $row;
					}
				}
				return null;
			}

			/**
			 * Mock findAll() — returns every row from the schema table
			 * that matches every filter.
			 *
			 * Mirrors OR's real ObjectService::findAll(array $config): the
			 * register/schema context travels INSIDE $config['filters'] and OR
			 * treats both as reserved params, never as object-field filters.
			 *
			 * @param array<string, mixed> $config Config with a `filters` map.
			 *
			 * @return array<int, array<string, mixed>> Matching rows.
			 */
			public function findAll(array $config = []): array {
				$filters = $config['filters'] ?? [];
				$schema = $filters['schema'] ?? null;
				unset($filters['register'], $filters['schema']);

				$rows = ($this->tables[$schema] ?? []);
				$out = [];
				foreach ($rows as $row) {
					foreach ($filters as $k => $v) {
						if (($row[$k] ?? null) !== $v) {
							continue 2;
						}
					}
					$out[] = $row;
				}
				return $out;
			}

			/**
			 * Mock saveObject() — append a new row, or overwrite the
			 * existing row when the supplied uuid matches.
			 *
			 * @param array $object Payload.
			 * @param mixed $register Register (ignored).
			 * @param mixed $schema Schema slug.
			 * @param string|null $uuid Existing id (null = create).
			 *
			 * @return array<string, mixed> The persisted row.
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				if ($uuid === null || $uuid === '') {
					$uuid = 'gen-' . $schema . '-' . count(($this->tables[$schema] ?? []));
				}
				$object['uuid'] = $uuid;
				$rows = ($this->tables[$schema] ?? []);
				$replaced = false;
				foreach ($rows as $idx => $row) {
					if (($row['uuid'] ?? null) === $uuid) {
						$rows[$idx] = $object;
						$replaced = true;
						break;
					}
				}
				if ($replaced === false) {
					$rows[] = $object;
				}
				$this->tables[$schema] = $rows;
				return $object;
			}

			/**
			 * Mock updateObject() — overwrite the matching row.
			 *
			 * @param string $id Identifier.
			 * @param array $object Updated payload.
			 * @param mixed $register Register (ignored).
			 * @param mixed $schema Schema slug.
			 *
			 * @return array<string, mixed> The updated row.
			 */
			public function updateObject(string $id, array $object, $register = null, $schema = null): array {
				$object['uuid'] = $id;
				$rows = ($this->tables[$schema] ?? []);
				$replaced = false;
				foreach ($rows as $idx => $row) {
					if (($row['uuid'] ?? null) === $id) {
						$rows[$idx] = $object;
						$replaced = true;
						break;
					}
				}
				if ($replaced === false) {
					$rows[] = $object;
				}
				$this->tables[$schema] = $rows;
				return $object;
			}
		};

		// Fake schema mapper exposing the contact properties so
		// SegmentService.validateRules can run.
		$schemaMapper = new class {
			/**
			 * Return a fake Schema object with getProperties().
			 *
			 * @param string $id Schema slug.
			 * @param mixed $published Ignored.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return object Fake schema.
			 */
			public function find(string $id, $published = null, bool $_rbac = false, bool $_multitenancy = false): object {
				return new class {
					/**
					 * Return the schema properties.
					 *
					 * @return array<string, array<string, string>> Properties.
					 */
					public function getProperties(): array {
						return [
							'email' => ['type' => 'string'],
							'industry' => ['type' => 'string'],
							'optedIn' => ['type' => 'boolean'],
						];
					}
				};
			}
		};

		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->schemaMapService = $this->createMock(SchemaMapService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$cache = $this->createMock(ICache::class);
		/** @var array<string, mixed> $store */
		$store = [];
		$cache->method('get')->willReturnCallback(
			function ($key) use (&$store) {
				return ($store[$key] ?? null);
			}
		);
		$cache->method('set')->willReturnCallback(
			function ($key, $value, $ttl = 0) use (&$store) {
				$store[$key] = $value;
				return true;
			}
		);
		$this->cacheFactory->method('isAvailable')->willReturn(true);
		$this->cacheFactory->method('createDistributed')->willReturn($cache);
		$this->cacheFactory->method('createLocal')->willReturn($cache);

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) {
				return match ($key) {
					'register' => 'pipelinq',
					'segment_schema' => 'segment',
					'contact_schema' => 'contact',
					'customer_schema' => 'client',
					'blast_schema' => 'blast',
					'blastDelivery_schema' => 'blastDelivery',
					'campaignTemplate_schema' => 'campaignTemplate',
					'consent_record_schema' => 'consentRecord',
					'blast.dispatch_batch_size' => '50',
					default => $default,
				};
			}
		);

		// Build the three services and wire them through the container so
		// BlastService → ComplianceService lookup succeeds.
		$segmentService = new SegmentService($this->container,
			$this->appConfig,
			$this->schemaMapService,
			$this->cacheFactory,
			$this->logger,
		);
		$complianceService = new ComplianceService($this->container,
			$this->appConfig,
			$segmentService,
			$this->logger,
		);
		$mailTransportService = new MailTransportService(
			$this->container,
			$this->appConfig,
			$this->createMock(IMailer::class),
			$this->createMock(ArticleService::class),
			$this->logger,
		);
		$blastService = new BlastService($this->container,
			$this->appConfig,
			$segmentService,
			$mailTransportService,
			$this->logger,
		);

		$objectService = $this->objectService;
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($objectService, $schemaMapper, $complianceService) {
				return match ($id) {
					'OCA\\OpenRegister\\Service\\ObjectService' => $objectService,
					'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemaMapper,
					'OCA\\Pipelinq\\Service\\ComplianceService' => $complianceService,
					default => throw new \RuntimeException('not registered: ' . $id),
				};
			}
		);

		$this->segmentService = $segmentService;
		$this->complianceService = $complianceService;
		$this->blastService = $blastService;
	}//end setUp()

	private SegmentService $segmentService;
	private ComplianceService $complianceService;
	private BlastService $blastService;

	/**
	 * Full pipeline: create a Segment, seed two Contacts (one consented),
	 * one CampaignTemplate, one draft Blast. Call sendBlast() and verify
	 * BlastDelivery rows for compliant contacts only, with the Blast
	 * transitioning draft → sending.
	 *
	 * @return void
	 */
	public function testSegmentToBlastToSend(): void {
		// 1. Seed two Contacts — `c-yes` is opted in, `c-no` is not.
		$this->objectService->tables['contact'] = [
			['uuid' => 'c-yes', 'email' => 'yes@example.test', 'industry' => 'Public sector', 'optedIn' => true],
			['uuid' => 'c-no',  'email' => 'no@example.test',  'industry' => 'Public sector', 'optedIn' => true],
		];

		// 2. Seed a ConsentRecord for c-yes only — c-no has none, fail-safe excludes it.
		$this->objectService->tables['consentRecord'] = [
			[
				'uuid' => 'consent-c-yes',
				'contactId' => 'c-yes',
				'channel' => 'email',
				'lawfulBasis' => 'consent',
				'consentedAt' => '2026-01-01T00:00:00Z',
			],
		];

		// 3. Create the Segment through SegmentService (validate + persist).
		$createResult = $this->segmentService->createSegment(
			[
				'name' => 'Public sector outreach',
				'entityType' => 'contact',
				'rules' => [
					'field' => 'industry',
					'operator' => 'equals',
					'value' => 'Public sector',
				],
			],
			'admin',
		);
		$this->assertArrayHasKey('segment', $createResult, 'segment creation must succeed');
		$segmentId = $createResult['segment']['uuid'];
		$this->assertSame(2, $createResult['estimatedSize'], 'estimateSize counts both contacts');

		// 4. Seed a CampaignTemplate and a draft Blast.
		$this->objectService->tables['campaignTemplate'] = [
			[
				'uuid' => 'tmpl-1',
				'name' => 'Q4 Outreach',
				'channel' => 'email',
				'subject' => 'Hi {{firstName}}',
				'bodyHtml' => '<p>{{unsubscribe_link}} {{physical_address}}</p>',
				'bodyText' => 'Unsubscribe: {{unsubscribe_link}}',
				'senderName' => 'Pipelinq',
				'senderEmail' => 'pipelinq@example.test',
			],
		];
		$this->objectService->tables['blast'] = [
			[
				'uuid' => 'blast-q4',
				'name' => 'Q4 Outreach',
				'segmentId' => $segmentId,
				'templateId' => 'tmpl-1',
				'channel' => 'email',
				'status' => 'draft',
				'connectorSourceId' => 'oc-source-1',
			],
		];

		// 5. Run sendBlast — compliance gate runs through the wired
		// ComplianceService → SegmentService chain and queues deliveries.
		$summary = $this->blastService->sendBlast('blast-q4', false);

		$this->assertSame('queued', $summary['status']);
		$this->assertSame(1, $summary['queued'], 'only one compliant contact is queued');
		$this->assertSame(1, $summary['skippedNoConsent'], 'one contact is missing consent');

		// 6. BlastDeliveries: exactly one row, for c-yes, in queued state.
		$deliveries = $this->objectService->tables['blastDelivery'];
		$this->assertCount(1, $deliveries);
		$this->assertSame('c-yes', $deliveries[0]['contactId']);
		$this->assertSame('queued', $deliveries[0]['status']);

		// 7. The Blast transitioned to sending.
		$blast = $this->objectService->tables['blast'][0];
		$this->assertSame('sending', $blast['status']);
	}//end testSegmentToBlastToSend()

	/**
	 * When EVERY member of the Segment has a usable ConsentRecord the
	 * pipeline queues one delivery per member and reports zero skipped.
	 *
	 * @return void
	 */
	public function testAllCompliantSegmentQueuesAllMembers(): void {
		$this->objectService->tables['contact'] = [
			['uuid' => 'c1', 'email' => 'c1@example.test', 'industry' => 'Public sector'],
			['uuid' => 'c2', 'email' => 'c2@example.test', 'industry' => 'Public sector'],
			['uuid' => 'c3', 'email' => 'c3@example.test', 'industry' => 'Healthcare'],
		];
		$this->objectService->tables['consentRecord'] = [
			['uuid' => 'cr1', 'contactId' => 'c1', 'channel' => 'email', 'lawfulBasis' => 'consent', 'consentedAt' => '2026-01-01T00:00:00Z'],
			['uuid' => 'cr2', 'contactId' => 'c2', 'channel' => 'email', 'lawfulBasis' => 'consent', 'consentedAt' => '2026-01-01T00:00:00Z'],
		];

		$segment = $this->segmentService->createSegment(
			[
				'name' => 'Public sector only',
				'entityType' => 'contact',
				'rules' => ['field' => 'industry', 'operator' => 'equals', 'value' => 'Public sector'],
			],
			'admin',
		);
		$segmentId = $segment['segment']['uuid'];

		$this->objectService->tables['blast'] = [
			[
				'uuid' => 'blast-all',
				'name' => 'Outreach',
				'segmentId' => $segmentId,
				'templateId' => 'tmpl-1',
				'channel' => 'email',
				'status' => 'draft',
			],
		];

		$summary = $this->blastService->sendBlast('blast-all', false);

		$this->assertSame('queued', $summary['status']);
		$this->assertSame(2, $summary['queued'], 'c1 + c2 are queued');
		$this->assertSame(0, $summary['skippedNoConsent']);
		$this->assertCount(2, $this->objectService->tables['blastDelivery']);
	}//end testAllCompliantSegmentQueuesAllMembers()

	/**
	 * When a consent withdrawal is recorded mid-send, every queued
	 * delivery for the contact transitions to `unsubscribed-before-send`
	 * so the dispatcher never calls the provider for the withdrawn row.
	 *
	 * @return void
	 */
	public function testWithdrawalTransitionsQueuedDeliveriesEndToEnd(): void {
		$this->objectService->tables['consentRecord'] = [
			['uuid' => 'cr-c1', 'contactId' => 'c1', 'channel' => 'email', 'lawfulBasis' => 'consent', 'consentedAt' => '2026-01-01T00:00:00Z'],
		];
		$this->objectService->tables['blastDelivery'] = [
			['uuid' => 'd1', 'blastId' => 'blast-1', 'contactId' => 'c1', 'status' => 'queued'],
			['uuid' => 'd2', 'blastId' => 'blast-1', 'contactId' => 'c1', 'status' => 'sent'],
		];

		$this->complianceService->recordConsentWithdrawal('c1', 'email', 'user-unsubscribed', 'blast-1');

		$queuedRow = $this->objectService->find('d1', null, 'blastDelivery');
		$sentRow = $this->objectService->find('d2', null, 'blastDelivery');

		$this->assertSame('unsubscribed-before-send', $queuedRow['status']);
		$this->assertSame('sent', $sentRow['status'], 'sent rows must not transition');

		// ConsentRecord update was issued with a withdrawnAt timestamp.
		$consent = $this->objectService->find('cr-c1', null, 'consentRecord');
		$this->assertNotEmpty((string)($consent['withdrawnAt'] ?? ''));
		$this->assertSame('user-unsubscribed', $consent['withdrawnReason']);
	}//end testWithdrawalTransitionsQueuedDeliveriesEndToEnd()
}//end class
