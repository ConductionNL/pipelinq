<?php

/**
 * Pipelinq SegmentRuleTree.
 *
 * Reads a segment's stored rules as one group tree (design D3): a group stays
 * itself with its type upper-cased; a bare condition, a list, a group without a
 * type, or nothing becomes an AND group around what was there. Also measures how
 * many groups deep a tree goes, so a request cannot recurse without end (D1).
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/segment-groups-its-rules/design.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * Normalises a segment rule tree and measures its depth.
 *
 * @spec openspec/changes/segment-groups-its-rules/design.md
 */
class SegmentRuleTree {

	/**
	 * The deepest nesting of groups a tree may have, the root counted as one.
	 */
	public const MAX_DEPTH = 5;

	/**
	 * The group types.
	 */
	private const GROUP_TYPES = ['AND', 'OR', 'NOT'];

	/**
	 * The rules as one group.
	 *
	 * @param mixed $rules The stored or submitted rules.
	 *
	 * @return array<string, mixed> A group: `type` AND, OR or NOT, and `children`.
	 *
	 * @spec openspec/changes/segment-groups-its-rules/design.md
	 */
	public function normalise(mixed $rules): array {
		if (is_array($rules) === false || $rules === []) {
			return ['type' => 'AND', 'children' => []];
		}

		if (array_is_list($rules) === true) {
			return ['type' => 'AND', 'children' => $rules];
		}

		$type = $this->groupType(node: $rules);
		if ($type !== null) {
			$rules['type'] = $type;
			return $rules;
		}

		if (array_key_exists('children', $rules) === true && array_key_exists('field', $rules) === false) {
			return array_merge(['type' => 'AND'], $rules);
		}

		return ['type' => 'AND', 'children' => [$rules]];
	}//end normalise()

	/**
	 * How many groups deep a tree goes; a lone condition is 0, a group of conditions 1.
	 *
	 * Stops counting past MAX_DEPTH + 1, which is all a caller needs to refuse.
	 *
	 * @param array<string, mixed> $node The tree.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/segment-groups-its-rules/design.md
	 */
	public function depth(array $node): int {
		return $this->depthBelow(node: $node, level: 0);
	}//end depth()

	/**
	 * The depth of a node found at a given level.
	 *
	 * @param array<string, mixed> $node  The node.
	 * @param int                  $level The groups above it.
	 *
	 * @return int
	 */
	private function depthBelow(array $node, int $level): int {
		if ($this->groupType(node: $node) === null || $level > self::MAX_DEPTH) {
			return $level;
		}

		$deepest = ($level + 1);
		foreach ((array)($node['children'] ?? []) as $child) {
			if (is_array($child) === true) {
				$deepest = max($deepest, $this->depthBelow(node: $child, level: ($level + 1)));
			}
		}

		return $deepest;
	}//end depthBelow()

	/**
	 * The upper-cased group type of a node, or null for a condition.
	 *
	 * @param array<string, mixed> $node The node.
	 *
	 * @return string|null
	 */
	private function groupType(array $node): ?string {
		$type = ($node['type'] ?? null);
		if (is_string($type) === false || in_array(strtoupper($type), self::GROUP_TYPES, true) === false) {
			return null;
		}

		return strtoupper($type);
	}//end groupType()
}//end class
