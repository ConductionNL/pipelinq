<?php

/**
 * Characterisation tests for the SegmentService rule comparisons, field
 * type resolution, value coercion and schema resolution.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\ICacheFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins evaluateRules() and validateRules() answers across every branch of
 * the comparison, type and coercion helpers.
 */
class SegmentServiceRulesGoldenTest extends TestCase {

	/**
	 * The schema properties the fake schema exposes.
	 *
	 * @var array<string, mixed>
	 */
	private const PROPERTIES = [
		'name' => ['type' => 'string'],
		'age' => ['type' => 'integer'],
		'score' => ['type' => 'number'],
		'active' => ['type' => 'boolean'],
		'tags' => ['type' => 'array'],
		'untyped' => [],
		'odd' => ['type' => 7],
		'phones' => ['type' => 'array', 'items' => ['properties' => ['kind' => ['type' => 'string'], 'rank' => ['type' => 'integer']]]],
		'flat' => ['type' => 'array'],
		'label' => 'not-an-array',
		'nested' => ['type' => 'object', 'items' => ['properties' => ['x' => ['type' => 'string']]]],
	];

	/**
	 * Schema slugs the mapper was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * Build the service.
	 *
	 * @param array<string, string> $config App config.
	 * @param mixed                 $schema What the mapper answers: a properties array, a Throwable or an object.
	 *
	 * @return SegmentService
	 */
	private function service(array $config = [], mixed $schema = self::PROPERTIES): SegmentService {
		$asked = &$this->asked;
		$mapper = new class($schema, $asked) {
			/**
			 * Constructor.
			 *
			 * @param mixed             $schema The answer.
			 * @param array<int,string> $asked  The slugs asked.
			 */
			public function __construct(private mixed $schema, private array &$asked) {
			}

			/**
			 * Find a schema.
			 *
			 * @param string|int $id            Slug.
			 * @param array|null $_extend       Ignored.
			 * @param bool       $_rbac         Ignored.
			 * @param bool       $_multitenancy Ignored.
			 *
			 * @return mixed
			 */
			public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->asked[] = (string)$id;
				if ($this->schema instanceof \Throwable) {
					throw $this->schema;
				}

				if (is_array($this->schema) === false) {
					return $this->schema;
				}

				$props = $this->schema;
				return new class($props) {
					/**
					 * Constructor.
					 *
					 * @param array<string, mixed> $props The properties.
					 */
					public function __construct(private array $props) {
					}

					/**
					 * The properties.
					 *
					 * @return array<string, mixed>
					 */
					public function getProperties(): array {
						return $this->props;
					}
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);
		$signals = $this->createMock(SegmentSignalService::class);
		$signals->method('isSignalField')->willReturn(false);
		$signals->method('schemaProperties')->willReturn([]);

		return new SegmentService(
			$container,
			$appConfig,
			$this->createMock(SchemaMapService::class),
			$this->createMock(ICacheFactory::class),
			$this->createMock(LoggerInterface::class),
			$signals
		);
	}//end service()

	/**
	 * Evaluate one leaf.
	 *
	 * @param string $operator The operator.
	 * @param mixed  $actual   The entity value under `f`.
	 * @param mixed  $value    The rule value.
	 *
	 * @return bool
	 */
	private function leaf(string $operator, mixed $actual, mixed $value): bool {
		return $this->service()->evaluateRules(['field' => 'f', 'operator' => $operator, 'value' => $value], ['f' => $actual]);
	}//end leaf()

	/**
	 * Equality: null, booleans, numbers, case, lists and non-scalars.
	 *
	 * @return void
	 */
	public function testEquals(): void {
		$cases = [
			[null, null, true],
			[true, '1', true],
			['0', false, true],
			[true, 0, false],
			['10', 10.0, true],
			['10.5', '10.50', true],
			['ABC', 'abc', true],
			['abc', 'abd', false],
			[['a', 'B'], 'b', true],
			[['a', 1], '1', true],
			[['a', ['b']], 'b', false],
			[['k' => 'b'], 'b', false],
			[['a'], ['a'], true],
			[['a'], ['b'], false],
			[null, '', false],
			[1, null, false],
		];
		foreach ($cases as $i => [$actual, $value, $expected]) {
			$this->assertSame($expected, $this->leaf('equals', $actual, $value), 'equals case ' . $i);
			$this->assertSame(!$expected, $this->leaf('notEquals', $actual, $value), 'notEquals case ' . $i);
		}
	}//end testEquals()

	/**
	 * Numeric comparisons, including string and mixed operands.
	 *
	 * @return void
	 */
	public function testNumericComparisons(): void {
		$cases = [
			['gt', '10', 9, true],
			['gt', 9, '10', false],
			['gte', 10, '10.0', true],
			['lt', 1.5, 2, true],
			['lte', 2, 2, true],
			['lte', 3, 2, false],
			['gt', 'b', 'a', true],
			['lt', 'a', 'b', true],
			['gte', 'abc', 5, true],
			['gt', 'abc', 5, false],
			['lt', null, 5, false],
			['between', 5, [1, 10], true],
			['between', 11, [1, 10], false],
			['between', 5, [1], false],
			['between', 5, 'x', false],
		];
		foreach ($cases as $i => [$operator, $actual, $value, $expected]) {
			$this->assertSame($expected, $this->leaf($operator, $actual, $value), $operator . ' case ' . $i);
		}
	}//end testNumericComparisons()

	/**
	 * Date comparisons: ISO, relative days, integers and parse failures.
	 *
	 * @return void
	 */
	public function testDateComparisons(): void {
		$cases = [
			['before', '2020-01-01', '2021-01-01', true],
			['after', '2020-01-01', '2021-01-01', false],
			['before', 1000, 2000, true],
			['after', 2000, '1970-01-01T00:00:00+00:00', true],
			['before', '2000-01-01', '3 days ago', true],
			['after', '2000-01-01', '1 day', false],
			['after', '2999-01-01', '10 DAYS AGO', true],
			['before', 'not a date', '2021-01-01', false],
			['after', '', '2021-01-01', false],
			['before', 1.5, '2021-01-01', false],
		];
		foreach ($cases as $i => [$operator, $actual, $value, $expected]) {
			$this->assertSame($expected, $this->leaf($operator, $actual, $value), $operator . ' case ' . $i);
		}
	}//end testDateComparisons()

	/**
	 * Contains and containsAny.
	 *
	 * @return void
	 */
	public function testContains(): void {
		$cases = [
			['contains', null, 'a', false],
			['contains', ['x', 'A'], 'a', true],
			['contains', ['x'], 'a', false],
			['contains', 'Hello World', 'WORLD', true],
			['contains', 'Hello', '', false],
			['contains', 'Hello', ['e'], false],
			['contains', 12345, 34, true],
			['contains', 'abc', 'z', false],
			['containsAny', 'Hello', ['z', 'ell'], true],
			['containsAny', 'Hello', ['z'], false],
			['containsAny', 'Hello', 'ell', false],
			['in', 'B', ['a', 'b'], true],
			['notIn', 'B', ['a', 'b'], false],
		];
		foreach ($cases as $i => [$operator, $actual, $value, $expected]) {
			$this->assertSame($expected, $this->leaf($operator, $actual, $value), $operator . ' case ' . $i);
		}
	}//end testContains()

	/**
	 * Composite nodes: empty, non-list and non-array children.
	 *
	 * @return void
	 */
	public function testComposites(): void {
		$true = ['field' => 'f', 'operator' => 'equals', 'value' => 1];
		$false = ['field' => 'f', 'operator' => 'equals', 'value' => 2];
		$entity = ['f' => 1];
		$service = $this->service();

		$this->assertTrue($service->evaluateRules(['type' => 'and', 'children' => [$true, $true]], $entity));
		$this->assertFalse($service->evaluateRules(['type' => 'AND', 'children' => [$true, $false]], $entity));
		$this->assertFalse($service->evaluateRules(['type' => 'AND', 'children' => [$true, 'x']], $entity));
		$this->assertFalse($service->evaluateRules(['type' => 'AND', 'children' => []], $entity));
		$this->assertFalse($service->evaluateRules(['type' => 'AND', 'children' => 'x'], $entity));
		$this->assertFalse($service->evaluateRules(['type' => 'AND'], $entity));
		$this->assertTrue($service->evaluateRules(['type' => 'OR', 'children' => ['x', $false, $true]], $entity));
		$this->assertFalse($service->evaluateRules(['type' => 'OR', 'children' => ['x', $false]], $entity));
		$this->assertFalse($service->evaluateRules(['type' => 'OR', 'children' => []], $entity));
		$this->assertFalse($service->evaluateRules(['type' => 'OR', 'children' => 3], $entity));
	}//end testComposites()

	/**
	 * Validate one leaf against the fake schema.
	 *
	 * @param array<string, mixed> $leaf The leaf.
	 *
	 * @return string|null
	 */
	private function validate(array $leaf): ?string {
		return $this->service()->validateRules($leaf, 'contact');
	}//end validate()

	/**
	 * Field type resolution, plain and dotted.
	 *
	 * @return void
	 */
	public function testFieldTypes(): void {
		$this->assertNull($this->validate(['field' => 'name', 'operator' => 'contains', 'value' => 'x']));
		$this->assertNull($this->validate(['field' => 'untyped', 'operator' => 'contains', 'value' => 'x']));
		$this->assertNull($this->validate(['field' => 'odd', 'operator' => 'contains', 'value' => 'x']));
		$this->assertNull($this->validate(['field' => 'label', 'operator' => 'contains', 'value' => 'x']));
		$this->assertNull($this->validate(['field' => 'phones.kind', 'operator' => 'contains', 'value' => 'x']));
		$this->assertSame(
			'$: operator "contains" is not valid for field "phones.rank" of type "integer".',
			$this->validate(['field' => 'phones.rank', 'operator' => 'contains', 'value' => 'x'])
		);
		foreach (['missing', 'missing.kind', 'phones.missing', 'flat.kind', 'label.kind', 'nested.x', 'name.x'] as $field) {
			$this->assertSame(
				sprintf('$: field "%s" is not declared on the entity schema.', $field),
				$this->validate(['field' => $field, 'operator' => 'equals', 'value' => 'x']),
				$field
			);
		}
	}//end testFieldTypes()

	/**
	 * Value coercion per declared type.
	 *
	 * @return void
	 */
	public function testCoercion(): void {
		$cases = [
			['age', '12', true],
			['age', '-3', true],
			['age', '1.2', false],
			['age', 4, true],
			['age', 4.0, false],
			['score', '1.5', true],
			['score', 2, true],
			['score', 'x', false],
			['active', 'TRUE', true],
			['active', '0', true],
			['active', 1, true],
			['active', 2, false],
			['active', true, true],
			['active', 'yes', false],
			['tags', ['a'], true],
			['tags', 'a', false],
			['name', 'a', true],
			['name', ['a'], false],
			['name', null, true],
		];
		foreach ($cases as $i => [$field, $value, $valid]) {
			$error = $this->validate(['field' => $field, 'operator' => 'equals', 'value' => $value]);
			$this->assertSame($valid, $error === null, 'coercion case ' . $i . ': ' . (string)$error);
		}
	}//end testCoercion()

	/**
	 * Schema slug resolution per entity type and config.
	 *
	 * @return void
	 */
	public function testSchemaSlugs(): void {
		$leaf = ['field' => 'name', 'operator' => 'equals', 'value' => 'x'];

		$this->service()->validateRules($leaf, 'Contact');
		$this->service(['contact_schema' => 'people'])->validateRules($leaf, 'contact');
		$this->service()->validateRules($leaf, 'customer');
		$this->service(['client_schema' => 'orgs'])->validateRules($leaf, 'CLIENT');
		$this->service(['customer_schema' => 'cust', 'client_schema' => 'orgs'])->validateRules($leaf, 'client');
		$this->assertSame(['contact', 'people', 'client', 'orgs', 'cust'], $this->asked);

		$this->assertSame(
			'Unknown entityType "lead" (no schema mapping configured).',
			$this->service(['contact_schema' => 'people'])->validateRules($leaf, 'lead')
		);
		$this->assertCount(5, $this->asked);
	}//end testSchemaSlugs()

	/**
	 * Schema property resolution failures all answer the unknown-type error.
	 *
	 * @return void
	 */
	public function testSchemaPropertyFailures(): void {
		$leaf = ['field' => 'name', 'operator' => 'equals', 'value' => 'x'];
		$expected = 'Unknown entityType "contact" (no schema mapping configured).';

		$this->assertSame($expected, $this->service([], new RuntimeException('down'))->validateRules($leaf, 'contact'));
		$this->assertSame($expected, $this->service([], new \stdClass())->validateRules($leaf, 'contact'));
		$this->assertSame($expected, $this->service([], 'not-an-object')->validateRules($leaf, 'contact'));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no mapper'));
		$signals = $this->createMock(SegmentSignalService::class);
		$signals->method('schemaProperties')->willReturn([]);
		$service = new SegmentService(
			$container,
			$this->createMock(IAppConfig::class),
			$this->createMock(SchemaMapService::class),
			$this->createMock(ICacheFactory::class),
			$this->createMock(LoggerInterface::class),
			$signals
		);
		$this->assertSame($expected, $service->validateRules($leaf, 'contact'));

		$withOddProperties = new class {
			/**
			 * Non-array properties.
			 *
			 * @return string
			 */
			public function getProperties(): string {
				return 'none';
			}
		};
		$this->assertSame($expected, $this->service([], $withOddProperties)->validateRules($leaf, 'contact'));
	}//end testSchemaPropertyFailures()
}//end class
