<?php

/**
 * Unit tests for SegmentQueryCompiler: a segment's rules as OpenRegister's
 * `_where`, holding at least every row the rules match.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
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

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\SegmentQueryCompiler;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Marketing\SegmentQueryCompiler
 * @uses   \OCA\Pipelinq\Service\Marketing\SegmentRuleTree
 */
class SegmentQueryCompilerTest extends TestCase {

	/**
	 * The client properties a rule may send.
	 */
	private const TYPES = [
		'place' => 'string',
		'type' => 'string',
		'employees' => 'integer',
		'revenue' => 'number',
		'industry' => 'array',
		'optedIn' => 'boolean',
	];

	/**
	 * Compile with the client properties.
	 *
	 * @param mixed $rules The rules.
	 *
	 * @return array<string, mixed>|null
	 */
	private function where(mixed $rules): ?array {
		return (new SegmentQueryCompiler())->where(rules: $rules, types: self::TYPES);
	}//end where()

	/**
	 * The board's segment: the root AND sends what compiles and leaves the rest to PHP.
	 *
	 * @return void
	 */
	public function testRootAndPushesCompilableChildren(): void {
		$where = $this->where(
			[
				'type' => 'AND',
				'children' => [
					['field' => 'place', 'operator' => 'equals', 'value' => 'Zuiddrecht'],
					[
						'type' => 'OR',
						'children' => [
							['field' => 'type', 'operator' => 'equals', 'value' => 'organization'],
							['field' => 'place', 'operator' => 'contains', 'value' => 'drecht'],
						],
					],
					['field' => 'pipelinqLastContactAt', 'operator' => 'after', 'value' => '2025-11-01'],
					['field' => 'industry', 'operator' => 'equals', 'value' => 'horeca'],
					['type' => 'NOT', 'children' => [['field' => 'place', 'operator' => 'equals', 'value' => 'Oost']]],
				],
			]
		);

		$this->assertSame(
			[
				'type' => 'AND',
				'children' => [
					['field' => 'place', 'operator' => 'like', 'value' => 'Zuiddrecht'],
					[
						'type' => 'OR',
						'children' => [
							['field' => 'type', 'operator' => 'like', 'value' => 'organization'],
							['field' => 'place', 'operator' => 'like', 'value' => 'drecht'],
						],
					],
				],
			],
			$where
		);
	}//end testRootAndPushesCompilableChildren()

	/**
	 * notEquals keeps the rows without a value, as the engine does.
	 *
	 * @return void
	 */
	public function testNotEqualsCompilesWithIsNull(): void {
		$this->assertSame(
			[
				'type' => 'OR',
				'children' => [
					['field' => 'place', 'operator' => 'ne', 'value' => 'Oost'],
					['field' => 'place', 'operator' => 'isnull', 'value' => true],
				],
			],
			$this->where(['field' => 'place', 'operator' => 'notEquals', 'value' => 'Oost'])
		);
		$this->assertNull($this->where(['field' => 'place', 'operator' => 'notEquals', 'value' => ['a']]));
	}//end testNotEqualsCompilesWithIsNull()

	/**
	 * An OR with one child that cannot be sent is not sent at all; neither is a NOT.
	 *
	 * @return void
	 */
	public function testAnOrWithAnUnsendableChildAndANotAreNotSent(): void {
		$this->assertNull(
			$this->where(
				[
					'type' => 'OR',
					'children' => [
						['field' => 'place', 'operator' => 'equals', 'value' => 'Zuiddrecht'],
						['field' => 'industry', 'operator' => 'equals', 'value' => 'horeca'],
					],
				]
			)
		);
		$this->assertNull($this->where(['type' => 'OR', 'children' => [['field' => 'place', 'operator' => 'equals', 'value' => 'x'], 'junk']]));
		$this->assertNull($this->where(['type' => 'NOT', 'children' => [['field' => 'place', 'operator' => 'equals', 'value' => 'x']]]));
		$this->assertNull($this->where([]));
	}//end testAnOrWithAnUnsendableChildAndANotAreNotSent()

	/**
	 * Numbers compare as numbers; an inclusive comparison also keeps rows without a value.
	 *
	 * @return void
	 */
	public function testNumericComparisonsKeepMissingValuesWhereTheEngineDoes(): void {
		$this->assertSame(['type' => 'AND', 'children' => [['field' => 'employees', 'operator' => 'gt', 'value' => 10]]], $this->where(['field' => 'employees', 'operator' => 'greaterThan', 'value' => '10']));
		$this->assertSame(['type' => 'AND', 'children' => [['field' => 'revenue', 'operator' => 'lt', 'value' => 2.5]]], $this->where(['field' => 'revenue', 'operator' => 'lt', 'value' => 2.5]));
		$this->assertSame(
			['type' => 'OR', 'children' => [['field' => 'employees', 'operator' => 'gte', 'value' => 5], ['field' => 'employees', 'operator' => 'isnull', 'value' => true]]],
			$this->where(['field' => 'employees', 'operator' => 'gte', 'value' => 5])
		);
		$this->assertSame(
			[
				'type' => 'OR',
				'children' => [
					['type' => 'AND', 'children' => [['field' => 'employees', 'operator' => 'gte', 'value' => 1], ['field' => 'employees', 'operator' => 'lte', 'value' => 9]]],
					['field' => 'employees', 'operator' => 'isnull', 'value' => true],
				],
			],
			$this->where(['field' => 'employees', 'operator' => 'between', 'value' => [1, 9]])
		);
		$this->assertSame(
			['type' => 'AND', 'children' => [['field' => 'employees', 'operator' => 'gte', 'value' => 7], ['field' => 'employees', 'operator' => 'lte', 'value' => 7]]],
			$this->where(['field' => 'employees', 'operator' => 'equals', 'value' => 7])
		);
	}//end testNumericComparisonsKeepMissingValuesWhereTheEngineDoes()

	/**
	 * What the engine compares differently from OpenRegister stays in PHP.
	 *
	 * @return void
	 */
	public function testLeavesTheEngineReadsDifferentlyAreNotSent(): void {
		foreach (
			[
				['field' => 'place', 'operator' => 'gt', 'value' => 'M'],
				['field' => 'employees', 'operator' => 'gt', 'value' => 'many'],
				['field' => 'employees', 'operator' => 'gt', 'value' => ' 5'],
				['field' => 'employees', 'operator' => 'between', 'value' => [1]],
				['field' => 'employees', 'operator' => 'between', 'value' => [1, 'x']],
				['field' => 'employees', 'operator' => 'contains', 'value' => '5'],
				['field' => 'place', 'operator' => 'before', 'value' => '2025-01-01'],
				['field' => 'place', 'operator' => 'in', 'value' => ['a', 'b']],
				['field' => 'place', 'operator' => 'equals', 'value' => ''],
				['field' => 'place', 'operator' => 'equals', 'value' => true],
				['field' => 'optedIn', 'operator' => 'equals', 'value' => true],
				['field' => 'unknown', 'operator' => 'equals', 'value' => 'x'],
				['field' => 'place', 'operator' => 'teleport', 'value' => 'x'],
			] as $leaf
		) {
			$this->assertNull($this->where($leaf), json_encode($leaf));
		}
	}//end testLeavesTheEngineReadsDifferentlyAreNotSent()

	/**
	 * A bare condition is read as an AND around it, since OpenRegister's root is always a group; nulls are asked for as nulls.
	 *
	 * @return void
	 */
	public function testABareConditionAndTheNullOperators(): void {
		$this->assertSame(['type' => 'AND', 'children' => [['field' => 'place', 'operator' => 'isnull', 'value' => true]]], $this->where(['field' => 'place', 'operator' => 'isNull']));
		$this->assertSame(
			['type' => 'AND', 'children' => [['field' => 'place', 'operator' => 'isnull', 'value' => false], ['field' => 'type', 'operator' => 'like', 'value' => '5']]],
			$this->where([['field' => 'place', 'operator' => 'isNotNull'], ['field' => 'type', 'value' => 5]])
		);
	}//end testABareConditionAndTheNullOperators()
}//end class
