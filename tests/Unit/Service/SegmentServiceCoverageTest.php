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

use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Signal double with a fixed catalogue and no real collaborators.
 */
class CoverageSignalService extends SegmentSignalService {

	/**
	 * Construct without the real collaborators.
	 */
	public function __construct() {
	}//end __construct()

	/**
	 * One integer signal.
	 *
	 * @return array<string, array{type: string, title: string, source: string, description: string}> Catalogue.
	 */
	public function catalogue(): array {
		return [
			'shillinqValueTier' => [
				'type' => 'string',
				'title' => 'Value tier',
				'source' => 'shillinq',
				'description' => 'Test signal.',
			],
		];
	}//end catalogue()

	/**
	 * Every source is available.
	 *
	 * @return array<string, bool> Availability.
	 */
	public function availability(): array {
		return ['shillinq' => true];
	}//end availability()

	/**
	 * No signal resolves.
	 *
	 * @param string $field Signal.
	 * @param array<string, mixed> $entity Entity.
	 *
	 * @return mixed Null.
	 */
	public function valueFor(string $field, array $entity): mixed {
		return null;
	}//end valueFor()
}//end class

/**
 * CRUD, pagination, caching and failure paths of SegmentService that the
 * rule and golden tests leave untouched.
 *
 * @covers \OCA\Pipelinq\Service\SegmentService
 * @uses   \OCA\Pipelinq\Service\Marketing\SegmentSignalService
 * @uses   \OCA\Pipelinq\Service\Marketing\SegmentRuleTree
 * @uses   \OCA\Pipelinq\Service\SegmentRulePosition
 * @uses   \OCA\Pipelinq\Service\SegmentRuleLeaf
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class SegmentServiceCoverageTest extends TestCase {

	private ContainerInterface $container;

	private IAppConfig $appConfig;

	private ICacheFactory $cacheFactory;

	private LoggerInterface $logger;

	private object $objectService;

	/**
	 * Container ids that resolve, keyed by id.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * App-config overrides; every other key answers its default.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Backing store of the cache double.
	 *
	 * @var array<string, mixed>
	 */
	private array $cacheStore = [];

	/**
	 * Every set() on the cache: [key, value, ttl].
	 *
	 * @var array<int, array{0: string, 1: mixed, 2: int}>
	 */
	private array $cacheSets = [];

	private SegmentService $service;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$schemaMapper = new class {
			/**
			 * A contact schema with a handful of typed properties.
			 *
			 * @param string|int $id Slug.
			 * @param array|null $_extend Ignored.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return object Schema.
			 */
			public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): object {
				return new class {
					/**
					 * Properties.
					 *
					 * @return array<string, array<string, string>> Properties.
					 */
					public function getProperties(): array {
						return [
							'industry' => ['type' => 'string'],
							'employees' => ['type' => 'integer'],
						];
					}
				};
			}
		};

		$this->objectService = new class {
			/**
			 * Rows keyed by schema, then id.
			 *
			 * @var array<string, array<string, array<string, mixed>>>
			 */
			public array $store = [];

			/**
			 * Every saveObject() call.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			public bool $failFind = false;

			public bool $failFindAll = false;

			public bool $failSave = false;

			/**
			 * Overrides what saveObject() answers, when set.
			 *
			 * @var array<string, mixed>|null
			 */
			public ?array $saveAnswer = null;

			/**
			 * Find one row.
			 *
			 * @param string $id Id.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 *
			 * @return array<string, mixed>|null Row.
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				if ($this->failFind === true) {
					throw new RuntimeException('find exploded');
				}

				return ($this->store[$schema][$id] ?? null);
			}

			/**
			 * Every row of the filtered schema.
			 *
			 * @param array<string, mixed> $config Config.
			 *
			 * @return array<int, array<string, mixed>> Rows.
			 */
			public function findAll(array $config = []): array {
				if ($this->failFindAll === true) {
					throw new RuntimeException('findAll exploded');
				}

				return array_values($this->store[$config['filters']['schema'] ?? ''] ?? []);
			}

			/**
			 * Save a row.
			 *
			 * @param array<string, mixed> $object Payload.
			 * @param mixed $register Register.
			 * @param mixed $schema Schema.
			 * @param string|null $uuid Id.
			 *
			 * @return array<string, mixed> Saved row.
			 */
			public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
				if ($this->failSave === true) {
					throw new RuntimeException('save exploded');
				}

				$this->saved[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];
				if ($this->saveAnswer !== null) {
					return $this->saveAnswer;
				}

				$id = ($uuid ?? ('seg-' . count($this->saved)));
				$object['uuid'] = $id;
				$this->store[$schema][$id] = $object;
				return $object;
			}
		};

		$this->services = [
			'OCA\\OpenRegister\\Service\\ObjectService' => $this->objectService,
			'OCA\\OpenRegister\\Db\\SchemaMapper' => $schemaMapper,
		];
		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if (isset($this->services[$id]) === false) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $this->services[$id];
			}
		);

		$this->appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn ($key) => ($this->cacheStore[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function ($key, $value, $ttl = 0) {
				$this->cacheSets[] = [$key, $value, $ttl];
				$this->cacheStore[$key] = $value;
				return true;
			}
		);
		$this->cacheFactory->method('isAvailable')->willReturn(true);
		$this->cacheFactory->method('createDistributed')->willReturn($cache);
		$this->cacheFactory->method('createLocal')->willReturn($cache);

		$this->service = new SegmentService(
			$this->container,
			$this->appConfig,
			$this->createMock(SchemaMapService::class),
			$this->cacheFactory,
			$this->logger,
			new CoverageSignalService(),
		);

		$this->objectService->store['contact'] = [
			'c1' => ['id' => 'c1', 'industry' => 'Retail', 'employees' => 10, 'email' => 'a@x.nl', 'name' => 'Ada  van Lovelace'],
			'c2' => ['id' => 'c2', 'industry' => 'Retail', 'employees' => 50, 'first_name' => 'Bob'],
			'c3' => ['@self' => ['slug' => 'c3'], 'industry' => 'Public', 'employees' => 5],
		];
	}//end setUp()

	/**
	 * A rule on industry = Retail.
	 *
	 * @return array<string, mixed> Rule.
	 */
	private function retail(): array {
		return ['field' => 'industry', 'operator' => 'equals', 'value' => 'Retail'];
	}//end retail()

	/**
	 * The signal catalogue and availability are the signal service's.
	 *
	 * @return void
	 */
	public function testSignalCatalogueAndAvailabilityDelegate(): void {
		$this->assertSame(['shillinqValueTier'], array_keys($this->service->signalCatalogue()));
		$this->assertSame(['shillinq' => true], $this->service->signalAvailability());
	}//end testSignalCatalogueAndAvailabilityDelegate()

	/**
	 * A preview reports an invalid tree, and otherwise counts matches.
	 *
	 * @return void
	 */
	public function testPreviewRulePayload(): void {
		$invalid = $this->service->previewRulePayload(rules: ['field' => 'nope', 'operator' => 'equals', 'value' => 'x'], entityType: 'contact');
		$this->assertFalse($invalid['valid']);
		$this->assertIsString($invalid['error']);
		$this->assertSame(0, $invalid['estimatedSize']);

		$this->assertSame(
			['valid' => true, 'error' => null, 'estimatedSize' => 2],
			$this->service->previewRulePayload(rules: $this->retail(), entityType: 'contact')
		);
	}//end testPreviewRulePayload()

	/**
	 * Members are projected with ids and names from every supported shape.
	 *
	 * @return void
	 */
	public function testPreviewSegmentMembersProjectsAndLimits(): void {
		$this->objectService->store['segment']['s1'] = [
			'rules' => json_encode(['field' => 'employees', 'operator' => 'lt', 'value' => 100]),
			'entityType' => 'contact',
		];
		$this->objectService->store['contact']['c4'] = ['@self' => ['other' => 'x'], 'employees' => 1];

		$this->assertSame([], $this->service->previewSegmentMembers(segmentId: ''));
		$members = $this->service->previewSegmentMembers(segmentId: 's1', limit: 10);

		$this->assertSame(
			[
				['contactId' => 'c1', 'email' => 'a@x.nl', 'firstName' => 'Ada', 'lastName' => 'van Lovelace'],
				['contactId' => 'c2', 'email' => '', 'firstName' => 'Bob', 'lastName' => ''],
				['contactId' => 'c3', 'email' => '', 'firstName' => '', 'lastName' => ''],
				['contactId' => '', 'email' => '', 'firstName' => '', 'lastName' => ''],
			],
			$members
		);
		$this->assertCount(1, $this->service->previewSegmentMembers(segmentId: 's1', limit: 0));
	}//end testPreviewSegmentMembersProjectsAndLimits()

	/**
	 * A segment with unreadable rules, no entity type or an unmapped entity
	 * type has no members and size zero.
	 *
	 * @return void
	 */
	public function testSegmentsWithoutUsableRulesHaveNoMembers(): void {
		$this->objectService->store['segment']['no-type'] = ['rules' => $this->retail()];
		$this->objectService->store['segment']['bad-json'] = ['rules' => '{nope', 'entityType' => 'contact'];
		$this->objectService->store['segment']['widget'] = ['rules' => $this->retail(), 'entityType' => 'widget'];

		$this->assertSame([], $this->service->getMembersForBlast(segmentId: 'no-type'));
		$this->assertSame([], $this->service->getMembersForBlast(segmentId: 'widget'));
		$this->assertSame(0, $this->service->recomputeSize(segmentId: 'bad-json'));
		$this->assertSame([], $this->cacheSets);
	}//end testSegmentsWithoutUsableRulesHaveNoMembers()

	/**
	 * Listing paginates the stored segments and clamps page and limit.
	 *
	 * @return void
	 */
	public function testListSegmentsPaginates(): void {
		$this->assertSame(
			['data' => [], 'pagination' => ['page' => 1, 'limit' => 1, 'total' => 0, 'pages' => 0]],
			$this->service->listSegments(page: 0, limit: 0)
		);

		$this->objectService->store['segment'] = ['a' => ['n' => 1], 'b' => ['n' => 2], 'c' => ['n' => 3]];
		$this->assertSame(
			['data' => [['n' => 3]], 'pagination' => ['page' => 2, 'limit' => 2, 'total' => 3, 'pages' => 2]],
			$this->service->listSegments(page: 2, limit: 2)
		);
	}//end testListSegmentsPaginates()

	/**
	 * Listing degrades to empty when findAll fails or OpenRegister is absent.
	 *
	 * @return void
	 */
	public function testListSegmentsDegrades(): void {
		$this->objectService->store['segment'] = ['a' => ['n' => 1]];
		$this->objectService->failFindAll = true;
		$this->logger->expects($this->once())->method('warning')->with(
			'SegmentService.loadEntities: findAll failed',
			['schema' => 'segment', 'exception' => 'findAll exploded']
		);
		$this->assertSame(0, $this->service->listSegments(page: 1, limit: 10)['pagination']['total']);
	}//end testListSegmentsDegrades()

	/**
	 * Without OpenRegister nothing is listed and the failure is logged.
	 *
	 * @return void
	 */
	public function testListSegmentsWithoutOpenRegister(): void {
		unset($this->services['OCA\\OpenRegister\\Service\\ObjectService']);
		$this->logger->expects($this->once())->method('warning')->with(
			'SegmentService.getObjectService: OpenRegister unavailable',
			['exception' => 'not registered: OCA\\OpenRegister\\Service\\ObjectService']
		);

		$this->assertSame([], $this->service->listSegments(page: 1, limit: 10)['data']);
	}//end testListSegmentsWithoutOpenRegister()

	/**
	 * By-id lookup refuses an empty id and survives a throwing store.
	 *
	 * @return void
	 */
	public function testGetSegmentById(): void {
		$this->objectService->store['segment']['s1'] = ['name' => 'One'];
		$this->assertSame(['name' => 'One'], $this->service->getSegmentById(segmentId: 's1'));
		$this->assertNull($this->service->getSegmentById(segmentId: ''));

		$this->objectService->failFind = true;
		$this->logger->expects($this->once())->method('info')->with(
			'SegmentService.loadSegment: not found',
			['segmentId' => 's1', 'exception' => 'find exploded']
		);
		$this->assertNull($this->service->getSegmentById(segmentId: 's1'));
	}//end testGetSegmentById()

	/**
	 * Creation refuses each invalid field with its own message.
	 *
	 * @return void
	 */
	public function testCreateSegmentValidation(): void {
		$this->assertSame(['error' => 'Invalid name'], $this->service->createSegment(payload: ['name' => ' '], createdByUid: 'u'));
		$this->assertSame(['error' => 'Invalid entityType'], $this->service->createSegment(payload: ['name' => 'N', 'entityType' => 'widget'], createdByUid: 'u'));
		$this->assertSame(['error' => 'Invalid rules'], $this->service->createSegment(payload: ['name' => 'N', 'entityType' => 'contact', 'rules' => 'x'], createdByUid: 'u'));
		$result = $this->service->createSegment(
			payload: ['name' => 'N', 'entityType' => 'contact', 'rules' => ['field' => 'nope', 'operator' => 'equals', 'value' => 'x']],
			createdByUid: 'u'
		);
		$this->assertStringStartsWith('Invalid rule tree: ', $result['error']);
		$this->assertSame([], $this->objectService->saved);
	}//end testCreateSegmentValidation()

	/**
	 * A created segment is stored and carries its freshly computed size.
	 *
	 * @return void
	 */
	public function testCreateSegmentStoresAndSizes(): void {
		$result = $this->service->createSegment(
			payload: ['name' => 'Retail', 'entityType' => 'Contact', 'rules' => $this->retail(), 'description' => 'D'],
			createdByUid: 'alice'
		);

		$this->assertSame(2, $result['estimatedSize']);
		$this->assertSame(2, $result['segment']['estimatedSize']);
		$saved = $this->objectService->saved[0];
		$this->assertSame('segment', $saved['schema']);
		$this->assertNull($saved['uuid']);
		$this->assertSame('contact', $saved['object']['entityType']);
		$this->assertSame('alice', $saved['object']['createdBy']);
		$this->assertSame('D', $saved['object']['description']);
		$this->assertSame([['estimate:seg-1', 2, 3600]], $this->cacheSets);
	}//end testCreateSegmentStoresAndSizes()

	/**
	 * A saved row identified only by @self is still sized; one with no id is not.
	 *
	 * @return void
	 */
	public function testCreateSegmentResolvesIdFromSelf(): void {
		$this->objectService->store['segment']['s-self'] = ['rules' => $this->retail(), 'entityType' => 'contact'];
		$this->objectService->saveAnswer = ['@self' => ['uuid' => 's-self']];
		$result = $this->service->createSegment(payload: ['name' => 'N', 'entityType' => 'contact', 'rules' => $this->retail()], createdByUid: 'u');
		$this->assertSame(2, $result['estimatedSize']);

		$this->objectService->saveAnswer = ['name' => 'no id'];
		$result = $this->service->createSegment(payload: ['name' => 'N', 'entityType' => 'contact', 'rules' => $this->retail()], createdByUid: 'u');
		$this->assertSame(['segment' => ['name' => 'no id'], 'estimatedSize' => 0], $result);
	}//end testCreateSegmentResolvesIdFromSelf()

	/**
	 * A failed save is reported, whether it throws or OpenRegister is gone.
	 *
	 * @return void
	 */
	public function testCreateSegmentReportsSaveFailure(): void {
		$this->objectService->failSave = true;
		$this->logger->expects($this->once())->method('warning')->with(
			'SegmentService.saveSegmentObject: save failed',
			['exception' => 'save exploded']
		);

		$this->assertSame(
			['error' => 'Could not create segment'],
			$this->service->createSegment(payload: ['name' => 'N', 'entityType' => 'customer', 'rules' => $this->retail()], createdByUid: 'u')
		);
	}//end testCreateSegmentReportsSaveFailure()

	/**
	 * Update refuses an empty id and a segment with no rules anywhere.
	 *
	 * @return void
	 */
	public function testUpdateSegmentRefusals(): void {
		$this->assertSame(['error' => 'Invalid id'], $this->service->updateSegment(segmentId: '', payload: []));

		$this->objectService->store['segment']['s1'] = ['name' => 'Old', 'entityType' => 'contact'];
		$this->assertSame(['error' => 'Invalid rules'], $this->service->updateSegment(segmentId: 's1', payload: []));
	}//end testUpdateSegmentRefusals()

	/**
	 * An update persists the new tree with a size computed from it, and
	 * writes that size through the cache using the configured TTL.
	 *
	 * @return void
	 */
	public function testUpdateSegmentPersistsAndWritesThroughCache(): void {
		$this->config['segment.estimate_ttl_seconds'] = '120';
		$this->objectService->store['segment']['s1'] = ['name' => 'Old', 'description' => 'keep', 'entityType' => 'contact', 'rules' => $this->retail()];

		$result = $this->service->updateSegment(
			segmentId: 's1',
			payload: ['rules' => ['field' => 'employees', 'operator' => 'gte', 'value' => 10]]
		);

		$this->assertSame(2, $result['estimatedSize']);
		$saved = $this->objectService->saved[0];
		$this->assertSame('s1', $saved['uuid']);
		$this->assertSame('Old', $saved['object']['name']);
		$this->assertSame('keep', $saved['object']['description']);
		$this->assertSame(2, $saved['object']['estimatedSize']);
		$this->assertSame([['estimate:s1', 2, 120]], $this->cacheSets);
	}//end testUpdateSegmentPersistsAndWritesThroughCache()

	/**
	 * A failed update save is reported and nothing is cached.
	 *
	 * @return void
	 */
	public function testUpdateSegmentReportsSaveFailure(): void {
		$this->objectService->store['segment']['s1'] = ['name' => 'Old', 'entityType' => 'contact', 'rules' => $this->retail()];
		$this->objectService->failSave = true;

		$this->assertSame(['error' => 'Could not update segment'], $this->service->updateSegment(segmentId: 's1', payload: []));
		$this->assertSame([], $this->cacheSets);
	}//end testUpdateSegmentReportsSaveFailure()

	/**
	 * A negative TTL falls back to the default one hour.
	 *
	 * @return void
	 */
	public function testNegativeTtlFallsBackToDefault(): void {
		$this->config['segment.estimate_ttl_seconds'] = '-5';
		$this->objectService->store['segment']['s1'] = ['entityType' => 'contact', 'rules' => $this->retail()];

		$this->assertSame(2, $this->service->recomputeSize(segmentId: 's1'));
		$this->assertSame([['estimate:s1', 2, 3600]], $this->cacheSets);
	}//end testNegativeTtlFallsBackToDefault()

	/**
	 * A numeric-string cached estimate is served as an int without a recount.
	 *
	 * @return void
	 */
	public function testEstimateSizeServesNumericStringFromCache(): void {
		$this->cacheStore['estimate:s1'] = '7';
		$this->objectService->failFind = true;

		$this->assertSame(7, $this->service->estimateSize(segmentId: 's1'));
	}//end testEstimateSizeServesNumericStringFromCache()

	/**
	 * Refresh refuses an empty or unknown id, and throws when it cannot save.
	 *
	 * @return void
	 */
	public function testRefreshSegmentSize(): void {
		$this->assertSame(0, $this->service->refreshSegmentSize(segmentId: ''));
		$this->assertSame(0, $this->service->refreshSegmentSize(segmentId: 'missing'));

		$this->objectService->store['segment']['s1'] = ['uuid' => 's1', 'entityType' => 'contact', 'rules' => $this->retail()];
		$this->objectService->failSave = true;
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Pipelinq: the refreshed segment size could not be persisted.');
		$this->service->refreshSegmentSize(segmentId: 's1');
	}//end testRefreshSegmentSize()

	/**
	 * A distributed cache that throws falls back to the local one.
	 *
	 * @return void
	 */
	public function testEstimateCacheFallsBackToLocal(): void {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willThrowException(new RuntimeException('no redis'));
		$local = $this->createMock(ICache::class);
		$local->expects($this->once())->method('get')->with('estimate:s1')->willReturn(4);
		$factory->expects($this->once())->method('createLocal')->with('pipelinq_segment_estimate')->willReturn($local);
		$this->logger->expects($this->once())->method('info')->with(
			'SegmentService.getEstimateCache: distributed cache unavailable',
			['exception' => 'no redis']
		);
		$service = new SegmentService(
			$this->container,
			$this->appConfig,
			$this->createMock(SchemaMapService::class),
			$factory,
			$this->logger,
			new CoverageSignalService(),
		);

		$this->assertSame(4, $service->estimateSize(segmentId: 's1'));
	}//end testEstimateCacheFallsBackToLocal()

	/**
	 * Loading entities for a type degrades when findAll throws or OR is gone.
	 *
	 * @return void
	 */
	public function testPreviewDegradesWhenEntitiesCannotLoad(): void {
		$this->objectService->failFindAll = true;
		$this->logger->expects($this->once())->method('warning')->with(
			'SegmentService.loadEntitiesForType: findAll failed',
			['entityType' => 'contact', 'exception' => 'findAll exploded']
		);
		$this->assertSame(0, $this->service->previewRulePayload(rules: $this->retail(), entityType: 'contact')['estimatedSize']);
	}//end testPreviewDegradesWhenEntitiesCannotLoad()

	/**
	 * Without OpenRegister a valid preview counts nothing.
	 *
	 * @return void
	 */
	public function testPreviewWithoutOpenRegisterCountsNothing(): void {
		unset($this->services['OCA\\OpenRegister\\Service\\ObjectService']);
		$this->logger->expects($this->once())->method('warning')->with('SegmentService.getObjectService: OpenRegister unavailable', $this->anything());

		$this->assertSame(
			['valid' => true, 'error' => null, 'estimatedSize' => 0],
			$this->service->previewRulePayload(rules: $this->retail(), entityType: 'contact')
		);
	}//end testPreviewWithoutOpenRegisterCountsNothing()

	/**
	 * Evaluation edge cases: empty NOT, empty field, unknown operator, list
	 * items that are not objects, in / notIn, and equal dates.
	 *
	 * @return void
	 */
	public function testEvaluationEdgeCases(): void {
		$entity = [
			'industry' => 'Retail',
			'phones' => ['plain', ['kind' => 'mobile']],
			'createdAt' => '2026-01-01T00:00:00Z',
		];

		$this->assertFalse($this->service->evaluateRules(rules: ['type' => 'NOT', 'children' => []], entity: $entity));
		$this->assertFalse($this->service->evaluateRules(rules: ['field' => '', 'operator' => 'isNull'], entity: $entity));
		$this->assertFalse($this->service->evaluateRules(rules: ['field' => 'industry', 'operator' => 'soundsLike', 'value' => 'Retail'], entity: $entity));
		$this->assertTrue($this->service->evaluateRules(rules: ['field' => 'phones.kind', 'operator' => 'equals', 'value' => 'mobile'], entity: $entity));
		$this->assertFalse($this->service->evaluateRules(rules: ['field' => 'phones.kind', 'operator' => 'equals', 'value' => 'home'], entity: $entity));

		$this->assertFalse($this->service->evaluateRules(rules: ['field' => 'industry', 'operator' => 'in', 'value' => 'Retail'], entity: $entity));
		$this->assertFalse($this->service->evaluateRules(rules: ['field' => 'industry', 'operator' => 'in', 'value' => ['Public']], entity: $entity));
		$this->assertTrue($this->service->evaluateRules(rules: ['field' => 'industry', 'operator' => 'in', 'value' => ['Public', 'Retail']], entity: $entity));
		$this->assertTrue($this->service->evaluateRules(rules: ['field' => 'industry', 'operator' => 'notIn', 'value' => 'Retail'], entity: $entity));
		$this->assertTrue($this->service->evaluateRules(rules: ['field' => 'industry', 'operator' => 'notIn', 'value' => ['Public']], entity: $entity));
		$this->assertFalse($this->service->evaluateRules(rules: ['field' => 'industry', 'operator' => 'notIn', 'value' => ['Retail']], entity: $entity));

		$this->assertFalse($this->service->evaluateRules(rules: ['field' => 'createdAt', 'operator' => 'before', 'value' => '2026-01-01T00:00:00Z'], entity: $entity));
		$this->assertFalse($this->service->evaluateRules(rules: ['field' => 'createdAt', 'operator' => 'after', 'value' => '2026-01-01T00:00:00Z'], entity: $entity));
		$this->assertTrue($this->service->evaluateRules(rules: ['field' => 'createdAt', 'operator' => 'before', 'value' => '2026-01-02T00:00:00Z'], entity: $entity));
	}//end testEvaluationEdgeCases()

	/**
	 * The public segment-schema accessor answers the configured slug.
	 *
	 * @return void
	 */
	public function testSegmentSchemaSlugPublicHonoursConfig(): void {
		$this->assertSame('segment', $this->service->getSegmentSchemaSlugPublic());
		$this->config['segment_schema'] = 'audiences';
		$this->assertSame('audiences', $this->service->getSegmentSchemaSlugPublic());
	}//end testSegmentSchemaSlugPublicHonoursConfig()
}//end class
