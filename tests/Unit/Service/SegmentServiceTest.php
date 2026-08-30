<?php

/**
 * Unit tests for SegmentService.
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
 * @spec openspec/changes/marketing-segmentation-and-blast-09-unit-integration-tests/tasks.md#segmentservice-tests-task-4.1-of-giant
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for SegmentService — rule validation, rule evaluation against entity
 * payloads, and audience-size estimation.
 *
 * Approach (ADR-008): mock ObjectService + SchemaMapper. Realistic seed-style
 * Contact rule trees exercise the validate → evaluate → estimate sequence so
 * the assertions track the production code path, not an inert stub.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-09-unit-integration-tests/tasks.md#segmentservice-tests-task-4.1-of-giant
 */
class SegmentServiceTest extends TestCase {
	private ContainerInterface $container;
	private IAppConfig $appConfig;
	private SchemaMapService $schemaMapService;
	private ICacheFactory $cacheFactory;
	private LoggerInterface $logger;
	private object $objectService;
	private object $schemaMapper;

	/**
	 * Service under test, instantiated in setUp().
	 *
	 * @var SegmentService
	 */
	private SegmentService $service;

	/**
	 * Schema "properties" returned by the in-memory schema mapper, keyed by
	 * field name → JSON-schema type. Mirrors the shape of the seed Contact
	 * schema declared in pipelinq's register.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $contactSchemaProperties = [
		'email' => ['type' => 'string'],
		'firstName' => ['type' => 'string'],
		'lastName' => ['type' => 'string'],
		'industry' => ['type' => 'string'],
		'employees' => ['type' => 'integer'],
		'revenue' => ['type' => 'number'],
		'optedIn' => ['type' => 'boolean'],
		'tags' => ['type' => 'array'],
		'lastContact' => ['type' => 'string'],
	];

	/**
	 * Set up — wire the schema mapper, the ObjectService double, and the
	 * SegmentService under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->schemaMapService = $this->createMock(SchemaMapService::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$properties = $this->contactSchemaProperties;
		$this->schemaMapper = new class($properties) {
			/**
			 * Properties exposed by the fake schema.
			 *
			 * @var array<string, array<string, string>>
			 */
			private array $properties;

			/**
			 * Constructor.
			 *
			 * @param array<string, array<string, string>> $properties Field map.
			 */
			public function __construct(array $properties) {
				$this->properties = $properties;
			}

			/**
			 * Mock find() — returns a fake Schema object exposing the props.
			 *
			 * @param string $id Schema slug.
			 * @param mixed $published Published filter (ignored).
			 * @param bool $_rbac RBAC flag (ignored).
			 * @param bool $_multitenancy Multitenancy flag (ignored).
			 *
			 * @return object Fake schema.
			 */
			public function find(string $id, $published = null, bool $_rbac = false, bool $_multitenancy = false): object {
				$props = $this->properties;
				return new class($props) {
					/** @var array<string, array<string, string>> */
					private array $props;

					/**
					 * @param array<string, array<string, string>> $props Field map.
					 */
					public function __construct(array $props) {
						$this->props = $props;
					}

					/**
					 * Return the schema properties.
					 *
					 * @return array<string, array<string, string>> Properties.
					 */
					public function getProperties(): array {
						return $this->props;
					}
				};
			}
		};

		$this->objectService = new class {
			/** @var array<int, array<string, mixed>> */
			public array $contacts = [];

			/** @var array<string, array<string, mixed>> */
			public array $segments = [];

			/**
			 * Mock find() — returns a stored segment by id.
			 *
			 * @param string $id Identifier.
			 * @param mixed $register Register slug.
			 * @param mixed $schema Schema slug.
			 *
			 * @return array<string, mixed>|null Payload or null.
			 */
			public function find(string $id, $register = null, $schema = null): ?array {
				return ($this->segments[$id] ?? null);
			}

			/**
			 * Mock findAll() — returns contacts when the schema is `contact`.
			 *
			 * Mirrors OR's real ObjectService::findAll(array $config): the
			 * register/schema context travels INSIDE $config['filters'].
			 *
			 * @param array<string, mixed> $config Config with a `filters` map.
			 *
			 * @return array<int, array<string, mixed>> Rows.
			 */
			public function findAll(array $config = []): array {
				$schema = $config['filters']['schema'] ?? null;
				if ($schema === 'contact') {
					return $this->contacts;
				}
				return [];
			}
		};

		$this->container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}

				if ($id === 'OCA\\OpenRegister\\Db\\SchemaMapper') {
					return $this->schemaMapper;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) {
				return match ($key) {
					'register' => 'pipelinq',
					'segment_schema' => 'segment',
					'contact_schema' => 'contact',
					'customer_schema' => 'client',
					default => $default,
				};
			}
		);

		// Provide an in-memory ICache via createMock so the cache wrap on
		// estimateSize is exercised but every test starts fresh.
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

		$this->service = new SegmentService($this->container,
			$this->appConfig,
			$this->schemaMapService,
			$this->cacheFactory,
			$this->logger,
		);
	}//end setUp()

	/**
	 * validateRules: a leaf with a recognised field, an operator from the
	 * type matrix, and a coercible value passes (returns null).
	 *
	 * @return void
	 */
	public function testValidateRulesAcceptsValidLeaf(): void {
		$rules = [
			'field' => 'industry',
			'operator' => 'equals',
			'value' => 'Public sector',
		];
		$error = $this->service->validateRules($rules, 'contact');
		$this->assertNull($error);
	}//end testValidateRulesAcceptsValidLeaf()

	/**
	 * validateRules: a leaf naming a field that does NOT exist on the
	 * Contact schema is rejected with a locator-bearing error.
	 *
	 * @return void
	 */
	public function testValidateRulesRejectsUnknownField(): void {
		$rules = [
			'field' => 'not_a_field',
			'operator' => 'equals',
			'value' => 'x',
		];
		$error = $this->service->validateRules($rules, 'contact');
		$this->assertIsString($error);
		$this->assertStringContainsString('not_a_field', (string)$error);
	}//end testValidateRulesRejectsUnknownField()

	/**
	 * validateRules: an operator that is in the matrix but incompatible
	 * with the field's type is rejected (numeric comparator on boolean).
	 *
	 * @return void
	 */
	public function testValidateRulesRejectsOperatorIncompatibleWithFieldType(): void {
		$rules = [
			'field' => 'optedIn',
			'operator' => 'gt',
			'value' => 0,
		];
		$error = $this->service->validateRules($rules, 'contact');
		$this->assertIsString($error);
		$this->assertStringContainsString('gt', (string)$error);
	}//end testValidateRulesRejectsOperatorIncompatibleWithFieldType()

	/**
	 * validateRules: an operator name that is NOT in the operator-type
	 * matrix is rejected.
	 *
	 * @return void
	 */
	public function testValidateRulesRejectsUnsupportedOperator(): void {
		$rules = [
			'field' => 'industry',
			'operator' => 'pretendNonsense',
			'value' => 'x',
		];
		$error = $this->service->validateRules($rules, 'contact');
		$this->assertIsString($error);
		$this->assertStringContainsString('pretendNonsense', (string)$error);
	}//end testValidateRulesRejectsUnsupportedOperator()

	/**
	 * validateRules: a numeric value that cannot be coerced to the
	 * declared field type (integer field, "many" string) is rejected.
	 *
	 * @return void
	 */
	public function testValidateRulesRejectsIncoercibleValue(): void {
		$rules = [
			'field' => 'employees',
			'operator' => 'gt',
			'value' => 'many',
		];
		$error = $this->service->validateRules($rules, 'contact');
		$this->assertIsString($error);
		$this->assertStringContainsString('employees', (string)$error);
	}//end testValidateRulesRejectsIncoercibleValue()

	/**
	 * validateRules: an unknown entity type returns a descriptive error
	 * (no schema mapping configured).
	 *
	 * @return void
	 */
	public function testValidateRulesRejectsUnknownEntityType(): void {
		$rules = [
			'field' => 'industry',
			'operator' => 'equals',
			'value' => 'x',
		];
		$error = $this->service->validateRules($rules, 'unicorn');
		$this->assertIsString($error);
		$this->assertStringContainsString('unicorn', (string)$error);
	}//end testValidateRulesRejectsUnknownEntityType()

	/**
	 * validateRules: AND composite trees with valid children pass.
	 *
	 * @return void
	 */
	public function testValidateRulesAcceptsAndComposite(): void {
		$rules = [
			'type' => 'AND',
			'children' => [
				['field' => 'industry', 'operator' => 'equals', 'value' => 'Public sector'],
				['field' => 'employees', 'operator' => 'gte', 'value' => 50],
			],
		];
		$error = $this->service->validateRules($rules, 'contact');
		$this->assertNull($error);
	}//end testValidateRulesAcceptsAndComposite()

	/**
	 * evaluateRules: matching leaf returns true; non-matching returns
	 * false; missing fields evaluate to false (predicate fails).
	 *
	 * @return void
	 */
	public function testEvaluateRulesLeafMatchAndNonMatch(): void {
		$rule = [
			'field' => 'industry',
			'operator' => 'equals',
			'value' => 'Public sector',
		];

		$hit = $this->service->evaluateRules($rule, ['industry' => 'Public sector']);
		$miss = $this->service->evaluateRules($rule, ['industry' => 'Retail']);
		$none = $this->service->evaluateRules($rule, []);

		$this->assertTrue($hit);
		$this->assertFalse($miss);
		$this->assertFalse($none, 'missing fields must not match');
	}//end testEvaluateRulesLeafMatchAndNonMatch()

	/**
	 * evaluateRules: AND tree returns true only when every child matches.
	 *
	 * @return void
	 */
	public function testEvaluateRulesAndComposite(): void {
		$rule = [
			'type' => 'AND',
			'children' => [
				['field' => 'industry', 'operator' => 'equals', 'value' => 'Public sector'],
				['field' => 'employees', 'operator' => 'gte', 'value' => 50],
			],
		];

		$both = ['industry' => 'Public sector', 'employees' => 120];
		$one = ['industry' => 'Public sector', 'employees' => 10];
		$other = ['industry' => 'Retail',        'employees' => 120];

		$this->assertTrue($this->service->evaluateRules($rule, $both));
		$this->assertFalse($this->service->evaluateRules($rule, $one));
		$this->assertFalse($this->service->evaluateRules($rule, $other));
	}//end testEvaluateRulesAndComposite()

	/**
	 * evaluateRules: OR tree returns true when any child matches.
	 *
	 * @return void
	 */
	public function testEvaluateRulesOrComposite(): void {
		$rule = [
			'type' => 'OR',
			'children' => [
				['field' => 'industry', 'operator' => 'equals', 'value' => 'Public sector'],
				['field' => 'industry', 'operator' => 'equals', 'value' => 'Healthcare'],
			],
		];

		$this->assertTrue($this->service->evaluateRules($rule, ['industry' => 'Public sector']));
		$this->assertTrue($this->service->evaluateRules($rule, ['industry' => 'Healthcare']));
		$this->assertFalse($this->service->evaluateRules($rule, ['industry' => 'Retail']));
	}//end testEvaluateRulesOrComposite()

	/**
	 * evaluateRules: NOT composite inverts its single child.
	 *
	 * @return void
	 */
	public function testEvaluateRulesNotComposite(): void {
		$rule = [
			'type' => 'NOT',
			'children' => [
				['field' => 'optedIn', 'operator' => 'equals', 'value' => true],
			],
		];
		$this->assertTrue($this->service->evaluateRules($rule, ['optedIn' => false]));
		$this->assertFalse($this->service->evaluateRules($rule, ['optedIn' => true]));
	}//end testEvaluateRulesNotComposite()

	/**
	 * estimateSize: returns the count of contacts that match the rule
	 * tree (mocked ObjectService returns 4 contacts, 2 in Public sector).
	 *
	 * @return void
	 */
	public function testEstimateSizeReturnsMatchingCount(): void {
		$this->objectService->segments['seg-1'] = [
			'uuid' => 'seg-1',
			'entityType' => 'contact',
			'rules' => [
				'field' => 'industry',
				'operator' => 'equals',
				'value' => 'Public sector',
			],
		];
		$this->objectService->contacts = [
			['uuid' => 'c1', 'industry' => 'Public sector', 'employees' => 200],
			['uuid' => 'c2', 'industry' => 'Healthcare',    'employees' => 80],
			['uuid' => 'c3', 'industry' => 'Public sector', 'employees' => 50],
			['uuid' => 'c4', 'industry' => 'Retail',        'employees' => 5],
		];

		$count = $this->service->estimateSize('seg-1');
		$this->assertSame(2, $count);
	}//end testEstimateSizeReturnsMatchingCount()

	/**
	 * estimateSize: returns 0 when the Segment is missing.
	 *
	 * @return void
	 */
	public function testEstimateSizeReturnsZeroOnMissingSegment(): void {
		$this->assertSame(0, $this->service->estimateSize('missing-segment'));
	}//end testEstimateSizeReturnsZeroOnMissingSegment()

	/**
	 * estimateSize: a Segment with an AND composite rule tree counts only
	 * the conjunction. Seed-style rule: Public-sector AND >=100 employees.
	 *
	 * @return void
	 */
	public function testEstimateSizeAndCompositeOnSeedShape(): void {
		$this->objectService->segments['seg-and'] = [
			'uuid' => 'seg-and',
			'entityType' => 'contact',
			'rules' => [
				'type' => 'AND',
				'children' => [
					['field' => 'industry',  'operator' => 'equals', 'value' => 'Public sector'],
					['field' => 'employees', 'operator' => 'gte',    'value' => 100],
				],
			],
		];
		$this->objectService->contacts = [
			['uuid' => 'c1', 'industry' => 'Public sector', 'employees' => 200],
			['uuid' => 'c2', 'industry' => 'Public sector', 'employees' => 50],
			['uuid' => 'c3', 'industry' => 'Healthcare',    'employees' => 500],
			['uuid' => 'c4', 'industry' => 'Public sector', 'employees' => 100],
		];

		$count = $this->service->estimateSize('seg-and');
		$this->assertSame(2, $count, 'only c1 + c4 satisfy both predicates');
	}//end testEstimateSizeAndCompositeOnSeedShape()

	/**
	 * getMembersForBlast: returns recipient projection rows for matching
	 * contacts only.
	 *
	 * @return void
	 */
	public function testGetMembersForBlastReturnsProjectedRecipients(): void {
		$this->objectService->segments['seg-mem'] = [
			'uuid' => 'seg-mem',
			'entityType' => 'contact',
			'rules' => [
				'field' => 'optedIn',
				'operator' => 'equals',
				'value' => true,
			],
		];
		$this->objectService->contacts = [
			[
				'uuid' => 'c1',
				'email' => 'a@example.test',
				'firstName' => 'Ada',
				'lastName' => 'Lovelace',
				'optedIn' => true,
			],
			[
				'uuid' => 'c2',
				'email' => 'b@example.test',
				'firstName' => 'Babbage',
				'optedIn' => false,
			],
		];

		$members = $this->service->getMembersForBlast('seg-mem');
		$this->assertCount(1, $members);
		$this->assertSame('c1', $members[0]['contactId']);
		$this->assertSame('a@example.test', $members[0]['email']);
		$this->assertSame('Ada', $members[0]['firstName']);
	}//end testGetMembersForBlastReturnsProjectedRecipients()
}//end class
