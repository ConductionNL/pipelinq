<?php

/**
 * Unit tests for BlastService.
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
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BlastService — A/B split determinism, send orchestration,
 * rate-limit resolution, and totals roll-up.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-04-blast-attribution-services/tasks.md#task-2.3
 */
class BlastServiceTest extends TestCase {

	private ContainerInterface $container;

	private IAppConfig $appConfig;

	private SegmentService $segmentService;

	private LoggerInterface $logger;

	private object $objectService;

	/**
	 * Service under test, instantiated in setUp().
	 *
	 * @var BlastService
	 */
	private BlastService $service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->segmentService = $this->createMock(SegmentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->objectService = new class {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @var array<string, array<string, mixed>>
			 */
			public array $store = [];

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $deliveries = [];

			/**
			 * Mock find() — returns a stored blast or a delivery by id.
			 *
			 * @param string $id Identifier.
			 * @param mixed $register Register slug.
			 * @param mixed $schema Schema slug.
			 *
			 * @return array<string, mixed>|null Payload or null.
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->store[$id] ?? null);
			}//end find()

			/**
			 * Mock findAll() — mirrors the real OR ObjectService signature
			 * (single $config array) and returns delivery rows filtered by
			 * blastId and optional status / contactId, honouring limit/offset.
			 *
			 * @param array<string, mixed> $config Configuration with `filters`,
			 *                                     `limit`, `offset`.
			 *
			 * @return array<int, array<string, mixed>> Rows.
			 */
			public function findAll(array $config = []): array {
				$out = $this->matchDeliveries(filters: ($config['filters'] ?? []));

				$offset = (int)($config['offset'] ?? 0);
				$limit = $config['limit'] ?? null;
				if ($offset > 0 || $limit !== null) {
					$out = array_slice($out, $offset, $limit);
				}

				return $out;
			}//end findAll()

			/**
			 * Mock count() — counts the rows the matching findAll would return,
			 * ignoring limit/offset.
			 *
			 * @param array<string, mixed> $config Configuration with `filters`.
			 *
			 * @return int
			 */
			public function count(array $config = []): int {
				return count($this->matchDeliveries(filters: ($config['filters'] ?? [])));
			}//end count()

			/**
			 * Filter the in-memory delivery rows by the given field filters,
			 * ignoring the OR metadata keys register/schema.
			 *
			 * @param array<string, mixed> $filters Filter map.
			 *
			 * @return array<int, array<string, mixed>> Matching rows.
			 */
			private function matchDeliveries(array $filters): array {
				unset($filters['register'], $filters['schema']);
				$out = [];
				foreach ($this->deliveries as $delivery) {
					foreach ($filters as $k => $v) {
						if (($delivery[$k] ?? null) !== $v) {
							continue 2;
						}
					}

					$out[] = $delivery;
				}

				return $out;
			}//end matchDeliveries()

			/**
			 * Mock saveObject() — records the saved payload + writes it
			 * back to the in-memory store keyed by id.
			 *
			 * @param array $object Payload.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 * @param string|null $uuid Existing id.
			 *
			 * @return array<string, mixed> The saved payload.
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				if ($uuid === null || $uuid === '') {
					$uuid = ('saved-' . count($this->saved));
				}

				$object['uuid'] = $uuid;
				$this->saved[] = $object;
				$this->store[$uuid] = $object;
				return $object;
			}//end saveObject()
		};

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}

				// ComplianceService and SourceService intentionally absent —
				// BlastService should fall back to the closed-fail path.
				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) {
				return match ($key) {
					'register' => 'pipelinq',
					'blast_schema' => 'blast',
					'blastDelivery_schema' => 'blastDelivery',
					'campaignTemplate_schema' => 'campaignTemplate',
					'blast.dispatch_batch_size' => '50',
					default => $default,
				};
			}
		);

		$this->service = new BlastService(
			$this->container,
			$this->appConfig,
			$this->segmentService,
			$this->logger,
		);
	}//end setUp()

	/**
	 * variantFor returns "A" when abSplitPercent is null or out-of-range.
	 *
	 * @return void
	 */
	public function testVariantForNoSplitReturnsA(): void {
		$this->assertSame('A', $this->service->variantFor('contact-1', null));
		$this->assertSame('A', $this->service->variantFor('contact-1', 0));
		$this->assertSame('A', $this->service->variantFor('contact-1', 101));
	}//end testVariantForNoSplitReturnsA()

	/**
	 * variantFor is deterministic: same input → same output across calls.
	 *
	 * @return void
	 */
	public function testVariantForIsDeterministicPerContact(): void {
		$first = $this->service->variantFor('contact-deterministic-1', 50);
		$second = $this->service->variantFor('contact-deterministic-1', 50);
		$third = $this->service->variantFor('contact-deterministic-1', 50);
		$this->assertSame($first, $second);
		$this->assertSame($second, $third);
	}//end testVariantForIsDeterministicPerContact()

	/**
	 * variantFor approximates the requested split percentage across a
	 * large synthetic contact-id population (the spec demands ~2,000/4,000
	 * per variant at 50%; we permit a 10-point tolerance to stay
	 * deterministic without flake).
	 *
	 * @return void
	 */
	public function testVariantForApproximatesRequestedSplit(): void {
		$bCount = 0;
		$size = 4000;
		for ($i = 0; $i < $size; $i++) {
			if ($this->service->variantFor('contact-' . $i, 50) === 'B') {
				$bCount++;
			}
		}

		$percent = ($bCount * 100.0 / $size);
		$this->assertGreaterThan(40.0, $percent, 'B share should be > 40% for a 50/50 split');
		$this->assertLessThan(60.0, $percent, 'B share should be < 60% for a 50/50 split');
	}//end testVariantForApproximatesRequestedSplit()

	/**
	 * sliceMembersForAb routes members to the correct Blast id per
	 * variant assignment.
	 *
	 * @return void
	 */
	public function testSliceMembersForAbRoutesByVariant(): void {
		$members = [
			['contactId' => 'contact-1', 'email' => 'a@example.test'],
			['contactId' => 'contact-2', 'email' => 'b@example.test'],
			['contactId' => 'contact-3', 'email' => 'c@example.test'],
		];
		$sliced = $this->service->sliceMembersForAb($members, 50, 'parent-blast', 'child-blast');
		$this->assertCount(3, $sliced);
		foreach ($sliced as $row) {
			$expected = $this->service->variantFor($row['member']['contactId'], 50) === 'B' ? 'child-blast' : 'parent-blast';
			$this->assertSame($expected, $row['blastId']);
		}
	}//end testSliceMembersForAbRoutesByVariant()

	/**
	 * sliceMembersForAb sends everyone to the parent blast when no split
	 * is configured.
	 *
	 * @return void
	 */
	public function testSliceMembersForAbSendsAllToParentWithoutSplit(): void {
		$members = [
			['contactId' => 'contact-1', 'email' => 'a@example.test'],
			['contactId' => 'contact-2', 'email' => 'b@example.test'],
		];
		$sliced = $this->service->sliceMembersForAb($members, null, 'parent-blast', null);
		foreach ($sliced as $row) {
			$this->assertSame('parent-blast', $row['blastId']);
			$this->assertSame('A', $row['variant']);
		}
	}//end testSliceMembersForAbSendsAllToParentWithoutSplit()

	/**
	 * sendBlast skips and stays in draft when ComplianceService is
	 * unavailable — fail-closed default (no recipient is treated as
	 * having lawful basis).
	 *
	 * @return void
	 */
	public function testSendBlastFailsClosedWhenComplianceUnavailable(): void {
		$blast = [
			'uuid' => 'blast-1',
			'name' => 'Q4',
			'segmentId' => 'seg-1',
			'templateId' => 'tmpl-1',
			'channel' => 'email',
			'status' => 'draft',
		];
		$this->objectService->store['blast-1'] = $blast;
		$this->segmentService->method('getMembersForBlast')->willReturn(
			[
				['contactId' => 'c1', 'email' => 'c1@example.test'],
			]
		);

		$summary = $this->service->sendBlast('blast-1', false);
		$this->assertSame('skipped-no-consent', $summary['status']);
		$this->assertSame(0, $summary['queued']);
		$this->assertSame(1, $summary['skippedNoConsent']);
	}//end testSendBlastFailsClosedWhenComplianceUnavailable()

	/**
	 * sendBlast refuses to queue when the Blast is not in `draft`.
	 *
	 * @return void
	 */
	public function testSendBlastRejectsNonDraftStatus(): void {
		$blast = [
			'uuid' => 'blast-sent',
			'segmentId' => 'seg-1',
			'templateId' => 'tmpl-1',
			'channel' => 'email',
			'status' => 'sent',
		];
		$this->objectService->store['blast-sent'] = $blast;
		$summary = $this->service->sendBlast('blast-sent', false);
		$this->assertSame('not-draft-sent', $summary['status']);
		$this->assertSame(0, $summary['queued']);
	}//end testSendBlastRejectsNonDraftStatus()

	/**
	 * sendBlast reports `not-found` when the Blast does not exist.
	 *
	 * @return void
	 */
	public function testSendBlastReportsNotFound(): void {
		$summary = $this->service->sendBlast('missing', false);
		$this->assertSame('not-found', $summary['status']);
	}//end testSendBlastReportsNotFound()

	/**
	 * updateBlastTotals recounts delivery statuses into the Blast totals
	 * map matching the schema's keys.
	 *
	 * @return void
	 */
	public function testUpdateBlastTotalsRecountsByStatus(): void {
		$blast = [
			'uuid' => 'blast-2',
			'segmentId' => 'seg-1',
			'channel' => 'email',
			'status' => 'sending',
			'totals' => [],
		];
		$this->objectService->store['blast-2'] = $blast;
		$this->objectService->deliveries = [
			['uuid' => 'd1', 'blastId' => 'blast-2', 'status' => 'sent'],
			['uuid' => 'd2', 'blastId' => 'blast-2', 'status' => 'sent'],
			['uuid' => 'd3', 'blastId' => 'blast-2', 'status' => 'delivered'],
			['uuid' => 'd4', 'blastId' => 'blast-2', 'status' => 'bounced'],
			['uuid' => 'd5', 'blastId' => 'other', 'status' => 'sent'],
		];

		$this->service->updateBlastTotals('blast-2');

		$persisted = end($this->objectService->saved);
		$this->assertIsArray($persisted);
		$this->assertSame(2, $persisted['totals']['sent']);
		$this->assertSame(1, $persisted['totals']['delivered']);
		$this->assertSame(1, $persisted['totals']['bounced']);
		$this->assertSame(0, $persisted['totals']['opened']);
	}//end testUpdateBlastTotalsRecountsByStatus()

	/**
	 * transitionQueuedDeliveries flips queued rows for a contact to
	 * `unsubscribed-before-send` without touching non-queued rows.
	 *
	 * @return void
	 */
	public function testTransitionQueuedDeliveriesFlipsOnlyQueuedRows(): void {
		$blast = [
			'uuid' => 'blast-3',
			'segmentId' => 'seg-1',
			'channel' => 'email',
			'status' => 'sending',
		];
		$this->objectService->store['blast-3'] = $blast;
		$this->objectService->deliveries = [
			['uuid' => 'd1', 'blastId' => 'blast-3', 'contactId' => 'c1', 'status' => 'queued'],
			['uuid' => 'd2', 'blastId' => 'blast-3', 'contactId' => 'c1', 'status' => 'sent'],
			['uuid' => 'd3', 'blastId' => 'blast-3', 'contactId' => 'c2', 'status' => 'queued'],
		];

		$this->service->transitionQueuedDeliveries('c1', 'blast-3');

		$statusForD1 = null;
		$statusForD2 = null;
		$statusForD3 = null;
		foreach ($this->objectService->saved as $row) {
			if ($row['uuid'] === 'd1') {
				$statusForD1 = $row['status'];
			}

			if ($row['uuid'] === 'd2') {
				$statusForD2 = $row['status'];
			}

			if ($row['uuid'] === 'd3') {
				$statusForD3 = $row['status'];
			}
		}

		$this->assertSame('unsubscribed-before-send', $statusForD1);
		$this->assertNull($statusForD2, 'sent rows must not be touched');
		$this->assertNull($statusForD3, 'other contact rows must not be touched');
	}//end testTransitionQueuedDeliveriesFlipsOnlyQueuedRows()

	/**
	 * createAbVariant clones name+segmentId+templateId, sets
	 * abVariantOf=parent, and defaults the name suffix to "(Variant B)".
	 *
	 * @return void
	 */
	public function testCreateAbVariantClonesParent(): void {
		$parent = [
			'uuid' => 'blast-parent',
			'name' => 'Q4 Gemeente',
			'segmentId' => 'seg-1',
			'templateId' => 'tmpl-1',
			'channel' => 'email',
			'connectorSourceId' => 'oc-source-1',
			'createdBy' => 'admin',
		];
		$this->objectService->store['blast-parent'] = $parent;

		$childId = $this->service->createAbVariant('blast-parent', []);
		$this->assertNotSame('', $childId);

		$child = end($this->objectService->saved);
		$this->assertSame('seg-1', $child['segmentId']);
		$this->assertSame('tmpl-1', $child['templateId']);
		$this->assertSame('email', $child['channel']);
		$this->assertSame('blast-parent', $child['abVariantOf']);
		$this->assertStringContainsString('Variant B', $child['name']);
	}//end testCreateAbVariantClonesParent()

	/**
	 * createAbVariant honours an override template id, name, and suffix
	 * supplied via the variantData payload — the child blast carries the
	 * overrides verbatim.
	 *
	 * @return void
	 */
	public function testCreateAbVariantHonoursOverrides(): void {
		$this->objectService->store['blast-parent2'] = [
			'uuid' => 'blast-parent2',
			'name' => 'Q4 Gemeente',
			'segmentId' => 'seg-1',
			'templateId' => 'tmpl-1',
			'channel' => 'email',
			'connectorSourceId' => 'oc-source-1',
		];
		$childId = $this->service->createAbVariant(
			'blast-parent2',
			['templateId' => 'tmpl-2', 'name' => 'Q4 Gemeente — Variant B (override)'],
		);
		$this->assertNotSame('', $childId);

		$child = end($this->objectService->saved);
		$this->assertSame('tmpl-2', $child['templateId']);
		$this->assertSame('Q4 Gemeente — Variant B (override)', $child['name']);
		$this->assertSame('blast-parent2', $child['abVariantOf']);
	}//end testCreateAbVariantHonoursOverrides()

	/**
	 * Slice 09 — sendBlast with a wired ComplianceService queues compliant
	 * BlastDeliveries and skips non-compliant ones; the Blast transitions
	 * draft → sending.
	 *
	 * @return void
	 */
	public function testSendBlastQueuesCompliantSkipsNonCompliant(): void {
		$blast = [
			'uuid' => 'blast-q4',
			'name' => 'Q4 Outreach',
			'segmentId' => 'seg-q4',
			'templateId' => 'tmpl-q4',
			'channel' => 'email',
			'status' => 'draft',
		];
		$this->objectService->store['blast-q4'] = $blast;

		$this->segmentService->method('getMembersForBlast')->willReturn(
			[
				['contactId' => 'c-yes', 'email' => 'yes@example.test'],
				['contactId' => 'c-no',  'email' => 'no@example.test'],
			]
		);

		// Wire a ComplianceService stub via the container — c-no is in
		// the missingConsent list, c-yes passes.
		$compliance = new class {
			public function checkSegmentCompliance(string $segmentId, string $channel): array {
				return [
					'compliant' => false,
					'missingConsent' => ['c-no'],
					'missingCount' => 1,
				];
			}//end checkSegmentCompliance()
		};
		$objectService = $this->objectService;
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($compliance, $objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\\Pipelinq\\Service\\ComplianceService') {
					return $compliance;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);
		$this->service = new BlastService(
			$this->container,
			$this->appConfig,
			$this->segmentService,
			$this->logger,
		);

		$summary = $this->service->sendBlast('blast-q4', false);

		$this->assertSame('queued', $summary['status']);
		$this->assertSame(1, $summary['queued']);
		$this->assertSame(1, $summary['skippedNoConsent']);
		$this->assertSame(1, $summary['variantA']);
		$this->assertSame(0, $summary['variantB']);

		// The one queued delivery row carries the compliant contact id.
		$deliveryRows = array_filter(
			$this->objectService->saved,
			fn (array $row) => ($row['status'] ?? null) === 'queued',
		);
		$this->assertCount(1, $deliveryRows);
		$deliveryRow = array_values($deliveryRows)[0];
		$this->assertSame('c-yes', $deliveryRow['contactId']);

		// Blast transitioned to sending.
		$blastSaves = array_filter(
			$this->objectService->saved,
			fn (array $row) => ($row['uuid'] ?? null) === 'blast-q4',
		);
		$finalBlast = end($blastSaves);
		$this->assertSame('sending', $finalBlast['status']);
	}//end testSendBlastQueuesCompliantSkipsNonCompliant()

	/**
	 * Slice 09 — sendBlast with abSplitPercent set on the parent creates
	 * the variant-B child Blast and persists deliveries against both
	 * blasts.
	 *
	 * @return void
	 */
	public function testSendBlastCreatesVariantChildOnAbSplit(): void {
		$blast = [
			'uuid' => 'blast-ab',
			'name' => 'A/B Trial',
			'segmentId' => 'seg-ab',
			'templateId' => 'tmpl-ab',
			'channel' => 'email',
			'status' => 'draft',
			'abSplitPercent' => 50,
		];
		$this->objectService->store['blast-ab'] = $blast;

		// 6 deterministic contact ids — at 50% some land in A, some in B.
		$members = [];
		for ($i = 1; $i <= 6; $i++) {
			$members[] = ['contactId' => 'ab-' . $i, 'email' => 'ab' . $i . '@example.test'];
		}

		$this->segmentService->method('getMembersForBlast')->willReturn($members);

		$compliance = new class {
			public function checkSegmentCompliance(string $segmentId, string $channel): array {
				return ['compliant' => true, 'missingConsent' => [], 'missingCount' => 0];
			}//end checkSegmentCompliance()
		};
		$objectService = $this->objectService;
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($compliance, $objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\\Pipelinq\\Service\\ComplianceService') {
					return $compliance;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);
		$this->service = new BlastService(
			$this->container,
			$this->appConfig,
			$this->segmentService,
			$this->logger,
		);

		$summary = $this->service->sendBlast('blast-ab', false);

		$this->assertSame('queued', $summary['status']);
		$this->assertSame(6, $summary['queued']);
		$this->assertNotNull($summary['variantBlastId']);
		$this->assertGreaterThan(0, $summary['variantA']);
		$this->assertGreaterThan(0, $summary['variantB']);

		// Variant child Blast was created with abVariantOf = parent id.
		$variantId = $summary['variantBlastId'];
		$variantRow = array_values(
			array_filter(
				$this->objectService->saved,
				fn (array $row) => ($row['uuid'] ?? null) === $variantId && isset($row['abVariantOf']),
			)
		);
		$this->assertNotEmpty($variantRow);
		$this->assertSame('blast-ab', $variantRow[0]['abVariantOf']);
	}//end testSendBlastCreatesVariantChildOnAbSplit()

	/**
	 * Slice 09 — dispatchBlastDeliveries calls openconnector's send-mail
	 * action exactly once per queued delivery, flips each row to `sent`
	 * with a providerId, and respects the rate-limit (source value < caller
	 * → throttle helper invoked at least once between batches).
	 *
	 * @return void
	 */
	public function testDispatchBlastDeliveriesCallsOpenconnectorAndRespectsRateLimit(): void {
		$blast = [
			'uuid' => 'blast-dispatch',
			'segmentId' => 'seg-d',
			'templateId' => 'tmpl-d',
			'channel' => 'email',
			'status' => 'sending',
			'connectorSourceId' => 'oc-source-x',
		];
		$template = [
			'uuid' => 'tmpl-d',
			'subject' => 'Hi {{contactId}}',
			'bodyHtml' => '<p>{{email}}</p>',
			'bodyText' => 'Hello {{email}}',
			'senderName' => 'Pipelinq',
			'senderEmail' => 'pipelinq@example.test',
		];
		$this->objectService->store['blast-dispatch'] = $blast;
		$this->objectService->store['tmpl-d'] = $template;
		$this->objectService->deliveries = [
			['uuid' => 'dx-1', 'blastId' => 'blast-dispatch', 'contactId' => 'c1', 'email' => 'c1@example.test', 'status' => 'queued'],
			['uuid' => 'dx-2', 'blastId' => 'blast-dispatch', 'contactId' => 'c2', 'email' => 'c2@example.test', 'status' => 'queued'],
			['uuid' => 'dx-3', 'blastId' => 'blast-dispatch', 'contactId' => 'c3', 'email' => 'c3@example.test', 'status' => 'queued'],
		];
		// Tighten batch size to 2 so two batches run — the throttle hook
		// fires between them.
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) {
				return match ($key) {
					'register' => 'pipelinq',
					'blast_schema' => 'blast',
					'blastDelivery_schema' => 'blastDelivery',
					'campaignTemplate_schema' => 'campaignTemplate',
					'blast.dispatch_batch_size' => '2',
					default => $default,
				};
			}
		);

		$sourceService = new class {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Return a source object whose sendRateLimit is intentionally
			 * lower than the caller-supplied rate.
			 *
			 * @param string $id Source id.
			 *
			 * @return array<string, mixed>|null Source row.
			 */
			public function find(string $id): ?array {
				return ['uuid' => $id, 'sendRateLimit' => 1];
			}//end find()

			/**
			 * Mock executeAction — captures every send-mail call and
			 * returns a synthetic provider id derived from the recipient.
			 *
			 * @param string $sourceId Source id.
			 * @param string $action Action name.
			 * @param array $payload Send-mail input.
			 *
			 * @return array<string, string> Provider response.
			 */
			public function executeAction(string $sourceId, string $action, array $payload): array {
				$this->calls[] = ['sourceId' => $sourceId, 'action' => $action, 'payload' => $payload];
				return ['providerId' => 'p-' . count($this->calls)];
			}//end executeAction()
		};

		$objectService = $this->objectService;
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($sourceService, $objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\\OpenConnector\\Service\\SourceService') {
					return $sourceService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		// Use a throttle-counting subclass to assert the rate-limit hook
		// is invoked between batches without sleeping the test.
		$service = new class($this->container, $this->appConfig, $this->segmentService, $this->logger) extends BlastService {

			/**
			 * @var integer
			 */
			public int $throttleCalls = 0;

			protected function throttle(float $seconds): void {
				$this->throttleCalls++;
			}//end throttle()
		};

		$dispatched = $service->dispatchBlastDeliveries('blast-dispatch', 100);

		$this->assertSame(3, $dispatched, 'every queued row was dispatched');
		$this->assertCount(3, $sourceService->calls, 'one send-mail call per row');
		foreach ($sourceService->calls as $call) {
			$this->assertSame('send-mail', $call['action']);
			$this->assertSame('oc-source-x', $call['sourceId']);
			$this->assertArrayHasKey('to', $call['payload']);
			$this->assertArrayHasKey('subject', $call['payload']);
		}

		// Each row was flipped to `sent` with a providerId.
		$sentRows = array_filter(
			$this->objectService->saved,
			fn (array $row) => ($row['status'] ?? null) === 'sent',
		);
		$this->assertCount(3, $sentRows);
		foreach ($sentRows as $row) {
			$this->assertNotEmpty((string)($row['providerId'] ?? ''));
			$this->assertNotEmpty((string)($row['sentAt'] ?? ''));
		}

		// batch_size=2 + 3 queued rows → 2 batches → throttle called
		// between batches at least once (source rate 1/s < caller 100).
		$this->assertGreaterThanOrEqual(
			1,
			$service->throttleCalls,
			'throttle helper must fire between batches when the source rate is tighter than the caller rate',
		);
	}//end testDispatchBlastDeliveriesCallsOpenconnectorAndRespectsRateLimit()

	/**
	 * Slice 09 — dispatchBlastDeliveries returns 0 and persists no rows
	 * when the openconnector source service is unavailable (fail-closed).
	 *
	 * @return void
	 */
	public function testDispatchBlastDeliveriesFailsClosedWhenSourceServiceUnavailable(): void {
		$blast = [
			'uuid' => 'blast-no-oc',
			'segmentId' => 'seg-no-oc',
			'templateId' => 'tmpl-no-oc',
			'channel' => 'email',
			'status' => 'sending',
			'connectorSourceId' => 'oc-source-missing',
		];
		$template = [
			'uuid' => 'tmpl-no-oc',
			'bodyHtml' => '<p>{{email}}</p>',
			'bodyText' => '{{email}}',
		];
		$this->objectService->store['blast-no-oc'] = $blast;
		$this->objectService->store['tmpl-no-oc'] = $template;
		$this->objectService->deliveries = [
			['uuid' => 'dn-1', 'blastId' => 'blast-no-oc', 'contactId' => 'c1', 'email' => 'c1@example.test', 'status' => 'queued'],
		];

		// SourceService NOT registered in the container — every send call
		// should fail-closed and the row should NOT flip to sent.
		$dispatched = $this->service->dispatchBlastDeliveries('blast-no-oc', 100);
		$this->assertSame(0, $dispatched);

		$sentRows = array_filter(
			$this->objectService->saved,
			fn (array $row) => ($row['status'] ?? null) === 'sent',
		);
		$this->assertCount(0, $sentRows, 'no deliveries should flip to sent when SourceService is unavailable');
	}//end testDispatchBlastDeliveriesFailsClosedWhenSourceServiceUnavailable()

	/**
	 * Slice 09 — updateBlastTotals on a blast with no deliveries leaves
	 * every status counter at 0.
	 *
	 * @return void
	 */
	public function testUpdateBlastTotalsZeroesAllStatusesWhenNoDeliveries(): void {
		$blast = [
			'uuid' => 'blast-empty',
			'status' => 'sending',
			'totals' => ['sent' => 99, 'delivered' => 50],
		];
		$this->objectService->store['blast-empty'] = $blast;

		$this->service->updateBlastTotals('blast-empty');

		$persisted = end($this->objectService->saved);
		$this->assertSame(0, $persisted['totals']['sent']);
		$this->assertSame(0, $persisted['totals']['delivered']);
		$this->assertSame(0, $persisted['totals']['bounced']);
		$this->assertSame(0, $persisted['totals']['unsubscribed']);
		$this->assertSame(0, $persisted['totals']['complained']);
	}//end testUpdateBlastTotalsZeroesAllStatusesWhenNoDeliveries()

	/**
	 * marketing-email-open-click-tracking — with `blast.first_party_tracking`
	 * off (the default), the rendered body sent to openconnector is
	 * byte-for-byte unchanged: TrackingLinkService is never looked up.
	 *
	 * @return void
	 */
	public function testSendOneDeliveryDoesNotInjectTrackingWhenFlagOff(): void {
		$blast = [
			'uuid' => 'blast-flag-off',
			'templateId' => 'tmpl-flag',
			'channel' => 'email',
			'status' => 'sending',
			'connectorSourceId' => 'oc-source-flag',
		];
		$template = [
			'uuid' => 'tmpl-flag',
			'subject' => 'Hi',
			'bodyHtml' => '<body><a href="https://pipelinq.nl/q4">Read more</a></body>',
		];
		$this->objectService->store['blast-flag-off'] = $blast;
		$this->objectService->store['tmpl-flag'] = $template;
		$this->objectService->deliveries = [
			['uuid' => 'd-flag-off', 'blastId' => 'blast-flag-off', 'contactId' => 'c1', 'email' => 'c1@example.test', 'status' => 'queued'],
		];

		$sourceService = new class {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			public function executeAction(string $sourceId, string $action, array $payload): array {
				$this->calls[] = $payload;
				return ['providerId' => 'p-1'];
			}//end executeAction()
		};

		$objectService = $this->objectService;
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($sourceService, $objectService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\\OpenConnector\\Service\\SourceService') {
					return $sourceService;
				}

				// TrackingLinkService must never be resolved when the flag is off.
				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$service = new BlastService($this->container, $this->appConfig, $this->segmentService, $this->logger);
		$dispatched = $service->dispatchBlastDeliveries('blast-flag-off', 100);

		$this->assertSame(1, $dispatched);
		$this->assertSame(
			'<body><a href="https://pipelinq.nl/q4">Read more</a></body>',
			$sourceService->calls[0]['bodyHtml'],
		);
	}//end testSendOneDeliveryDoesNotInjectTrackingWhenFlagOff()

	/**
	 * marketing-email-open-click-tracking — with `blast.first_party_tracking`
	 * on, the rendered body is passed through
	 * `TrackingLinkService::injectTracking()` before being sent, keyed by
	 * the delivery's own id.
	 *
	 * @return void
	 */
	public function testSendOneDeliveryInjectsTrackingWhenFlagOn(): void {
		$blast = [
			'uuid' => 'blast-flag-on',
			'templateId' => 'tmpl-flag-on',
			'channel' => 'email',
			'status' => 'sending',
			'connectorSourceId' => 'oc-source-flag-on',
		];
		$template = [
			'uuid' => 'tmpl-flag-on',
			'subject' => 'Hi',
			'bodyHtml' => '<body>original</body>',
		];
		$this->objectService->store['blast-flag-on'] = $blast;
		$this->objectService->store['tmpl-flag-on'] = $template;
		$this->objectService->deliveries = [
			['uuid' => 'd-flag-on', 'blastId' => 'blast-flag-on', 'contactId' => 'c1', 'email' => 'c1@example.test', 'status' => 'queued'],
		];

		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) {
				return match ($key) {
					'register' => 'pipelinq',
					'blast_schema' => 'blast',
					'blastDelivery_schema' => 'blastDelivery',
					'campaignTemplate_schema' => 'campaignTemplate',
					'blast.dispatch_batch_size' => '50',
					'blast.first_party_tracking' => 'true',
					default => $default,
				};
			}
		);

		$sourceService = new class {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			public function executeAction(string $sourceId, string $action, array $payload): array {
				$this->calls[] = $payload;
				return ['providerId' => 'p-1'];
			}//end executeAction()
		};

		$trackingLinkService = new class {
			/** @var array<int, array<string, string>> */
			public array $calls = [];

			public function injectTracking(string $html, string $blastDeliveryId): string {
				$this->calls[] = ['html' => $html, 'blastDeliveryId' => $blastDeliveryId];
				return ($html . '<!--tracked-->');
			}//end injectTracking()
		};

		$objectService = $this->objectService;
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id) use ($sourceService, $objectService, $trackingLinkService) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $objectService;
				}

				if ($id === 'OCA\\OpenConnector\\Service\\SourceService') {
					return $sourceService;
				}

				if ($id === 'OCA\\Pipelinq\\Service\\TrackingLinkService') {
					return $trackingLinkService;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$service = new BlastService($this->container, $this->appConfig, $this->segmentService, $this->logger);
		$dispatched = $service->dispatchBlastDeliveries('blast-flag-on', 100);

		$this->assertSame(1, $dispatched);
		$this->assertSame('<body>original</body><!--tracked-->', $sourceService->calls[0]['bodyHtml']);
		$this->assertCount(1, $trackingLinkService->calls);
		$this->assertSame('d-flag-on', $trackingLinkService->calls[0]['blastDeliveryId']);
	}//end testSendOneDeliveryInjectsTrackingWhenFlagOn()
}//end class
