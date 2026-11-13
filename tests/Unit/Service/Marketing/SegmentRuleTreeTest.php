<?php

/**
 * Unit tests for SegmentRuleTree: every stored shape reads as one group, and
 * the depth of a tree is counted in groups.
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

use OCA\Pipelinq\Service\Marketing\SegmentRuleTree;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Marketing\SegmentRuleTree
 */
class SegmentRuleTreeTest extends TestCase {

	/**
	 * Each stored shape of design D3 and the group it reads as.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, mixed>}>
	 */
	public static function shapes(): array {
		$leaf = ['field' => 'place', 'operator' => 'equals', 'value' => 'Zuiddrecht'];
		return [
			'a group' => [['type' => 'OR', 'children' => [$leaf]], ['type' => 'OR', 'children' => [$leaf]]],
			'a lower-case group' => [['type' => 'not', 'children' => [$leaf]], ['type' => 'NOT', 'children' => [$leaf]]],
			'a bare leaf' => [$leaf, ['type' => 'AND', 'children' => [$leaf]]],
			'a list' => [[$leaf, $leaf], ['type' => 'AND', 'children' => [$leaf, $leaf]]],
			'children without a type' => [['children' => [$leaf]], ['type' => 'AND', 'children' => [$leaf]]],
			// Not a group and not a condition: kept as it is, so validation refuses it.
			'an unknown type' => [['type' => 'XOR', 'children' => [$leaf]], ['type' => 'XOR', 'children' => [$leaf]]],
			'empty' => [[], ['type' => 'AND', 'children' => []]],
			'missing' => [null, ['type' => 'AND', 'children' => []]],
		];
	}//end shapes()

	/**
	 * Every stored shape reads as one group.
	 *
	 * @param mixed                $stored   The stored rules.
	 * @param array<string, mixed> $expected The group.
	 *
	 * @return void
	 *
	 * @dataProvider shapes
	 */
	public function testEveryShapeReadsAsOneGroup(mixed $stored, array $expected): void {
		self::assertSame($expected, (new SegmentRuleTree())->normalise(rules: $stored));
	}//end testEveryShapeReadsAsOneGroup()

	/**
	 * Depth counts groups: a condition is 0, a group of conditions 1, and the deepest branch decides.
	 *
	 * @return void
	 */
	public function testDepthCountsTheDeepestBranch(): void {
		$tree = new SegmentRuleTree();
		$leaf = ['field' => 'a', 'operator' => 'equals', 'value' => 1];

		self::assertSame(0, $tree->depth(node: $leaf));
		self::assertSame(1, $tree->depth(node: ['type' => 'AND', 'children' => [$leaf, 'not a node']]));
		self::assertSame(3, $tree->depth(node: ['type' => 'AND', 'children' => [$leaf, ['type' => 'OR', 'children' => [['type' => 'not', 'children' => [$leaf]]]]]]));

		$deep = $leaf;
		for ($level = 0; $level < 9; $level++) {
			$deep = ['type' => 'AND', 'children' => [$deep]];
		}

		self::assertGreaterThan(SegmentRuleTree::MAX_DEPTH, $tree->depth(node: $deep));
	}//end testDepthCountsTheDeepestBranch()
}//end class
