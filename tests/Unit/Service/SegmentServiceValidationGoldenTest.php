<?php

/**
 * Characterisation tests for every SegmentService rule-tree validation
 * error, with its path.
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pins validateRules() for composite, NOT and leaf nodes.
 */
class SegmentServiceValidationGoldenTest extends TestCase {

	/**
	 * Build the service over a fixed contact schema.
	 *
	 * @return SegmentService
	 */
	private function service(): SegmentService {
		$mapper = new class {
			/**
			 * Find a schema.
			 *
			 * @param string|int $id            Slug.
			 * @param array|null $_extend       Ignored.
			 * @param bool       $_rbac         Ignored.
			 * @param bool       $_multitenancy Ignored.
			 *
			 * @return object
			 */
			public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): object {
				return new class {
					/**
					 * The properties.
					 *
					 * @return array<string, mixed>
					 */
					public function getProperties(): array {
						return [
							'name' => ['type' => 'string'],
							'age' => ['type' => 'integer'],
							'active' => ['type' => 'boolean'],
							'tags' => ['type' => 'array'],
						];
					}
				};
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$signals = $this->createMock(SegmentSignalService::class);
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
	 * Every validation answer, keyed by the expected error (null = valid).
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function trees(): array {
		$ok = ['field' => 'name', 'operator' => 'equals', 'value' => 'x'];
		return [
			'valid leaf' => [$ok, null],
			'and empty' => [['type' => 'AND', 'children' => []], '$: composite "AND" node requires non-empty "children".'],
			'or missing' => [['type' => 'or'], '$: composite "OR" node requires non-empty "children".'],
			'and scalar child' => [['type' => 'AND', 'children' => [$ok, 'x']], '$.children[1]: child must be an object.'],
			'and keyed scalar child' => [['type' => 'AND', 'children' => ['k' => 'x']], '$.children[0]: child must be an object.'],
			'and keyed bad child' => [['type' => 'AND', 'children' => ['k' => ['field' => '']]], '$.children[k]: leaf predicate requires non-empty "field".'],
			'nested bad leaf' => [
				['type' => 'OR', 'children' => [$ok, ['type' => 'NOT', 'children' => [['field' => 'nope', 'operator' => 'equals', 'value' => 1]]]]],
				'$.children[1].children[0]: field "nope" is not declared on the entity schema.',
			],
			'nested valid' => [['type' => 'AND', 'children' => [$ok, ['type' => 'NOT', 'children' => [$ok]]]], null],
			'not two children' => [['type' => 'NOT', 'children' => [$ok, $ok]], '$: NOT node requires exactly one child.'],
			'not scalar children' => [['type' => 'NOT', 'children' => 'x'], '$: NOT node requires exactly one child.'],
			'not scalar child' => [['type' => 'NOT', 'children' => ['x']], '$.children[0]: child must be an object.'],
			'leaf no field' => [['operator' => 'equals'], '$: leaf predicate requires non-empty "field".'],
			'leaf int field' => [['field' => 3], '$: leaf predicate requires non-empty "field".'],
			'leaf unknown operator' => [['field' => 'name', 'operator' => 'like', 'value' => 'x'], '$: operator "like" is not supported.'],
			'leaf missing operator' => [['field' => 'name', 'value' => 'x'], '$: operator "" is not supported.'],
			'leaf type mismatch' => [['field' => 'active', 'operator' => 'gt', 'value' => 1], '$: operator "gt" is not valid for field "active" of type "boolean".'],
			'leaf isNull no value' => [['field' => 'name', 'operator' => 'isNull'], null],
			'leaf isNotNull' => [['field' => 'tags', 'operator' => 'isNotNull', 'value' => 'whatever'], null],
			'leaf requires value' => [['field' => 'name', 'operator' => 'equals'], '$: operator "equals" requires a "value".'],
			'leaf null value' => [['field' => 'age', 'operator' => 'equals', 'value' => null], null],
			'leaf not coercible' => [['field' => 'age', 'operator' => 'equals', 'value' => 'x'], '$: value for field "age" is not coercible to type "integer".'],
			'in list ok' => [['field' => 'age', 'operator' => 'in', 'value' => [1, '2']], null],
			'in list bad' => [['field' => 'age', 'operator' => 'in', 'value' => [1, 'x']], '$: value for field "age" is not coercible to type "integer".'],
			'in scalar' => [['field' => 'age', 'operator' => 'in', 'value' => 1], '$: value for field "age" is not coercible to type "integer".'],
			'between pair' => [['field' => 'age', 'operator' => 'between', 'value' => [1, 2]], null],
			'between triple' => [['field' => 'age', 'operator' => 'between', 'value' => [1, 2, 3]], '$: value for field "age" is not coercible to type "integer".'],
			'containsAny string list' => [['field' => 'name', 'operator' => 'containsAny', 'value' => ['a', 'b']], null],
			'containsAny string scalar' => [['field' => 'name', 'operator' => 'containsAny', 'value' => 'a'], '$: value for field "name" is not coercible to type "string".'],
			'contains array member' => [['field' => 'tags', 'operator' => 'contains', 'value' => 'a'], null],
			'containsAny array members' => [['field' => 'tags', 'operator' => 'containsAny', 'value' => ['a', 1]], null],
			'contains array nested' => [['field' => 'tags', 'operator' => 'contains', 'value' => [['a']]], '$: value for field "tags" is not coercible to type "array".'],
			'equals array scalar' => [['field' => 'tags', 'operator' => 'equals', 'value' => 'a'], '$: value for field "tags" is not coercible to type "array".'],
		];
	}//end trees()

	/**
	 * Each tree validates to its expected answer.
	 *
	 * @param array<string, mixed> $tree     The rule tree.
	 * @param string|null          $expected The expected error.
	 *
	 * @return void
	 */
	#[DataProvider('trees')]
	public function testValidation(array $tree, ?string $expected): void {
		$this->assertSame($expected, $this->service()->validateRules($tree, 'contact'));
	}//end testValidation()
}//end class
