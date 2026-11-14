<?php

/**
 * Pipelinq SegmentQueryCompiler.
 *
 * Compiles a segment's rule tree into OpenRegister's `_where` condition group
 * (design D4), so the audience is narrowed in the database instead of loading
 * every contact or client.
 *
 * WHAT IT COMPILES IS A SUPERSET, NEVER A SUBSET. The rows OpenRegister
 * returns are evaluated by the PHP engine afterwards, so a compiled leaf may
 * let through rows the rule rejects, but must never drop a row the rule keeps.
 * That is why `equals` on text becomes `like`: the engine compares text
 * ignoring case, OpenRegister's `eq` does not, and its `like` does. A leaf
 * that cannot be compiled that way is left out: under an AND that only
 * widens the result, under an OR or a NOT it means the group cannot be sent.
 *
 * Left out, so evaluated in PHP only: derived signals and dotted array paths
 * (the caller passes only the schema's own scalar properties), properties of
 * any type but string, integer and number, dates (`before`, `after`: stored
 * values carry offsets a text comparison gets wrong), `in`, `notIn`,
 * `containsAny`, and everything under a NOT.
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
 * Compiles a rule tree into a `_where` group that holds at least every row the tree matches.
 *
 * @spec openspec/changes/segment-groups-its-rules/design.md
 */
class SegmentQueryCompiler {

	/**
	 * The comparison operators, by every name pipelinq accepts, and OpenRegister's name.
	 *
	 * @var array<string, string>
	 */
	private const COMPARISONS = [
		'gt' => 'gt',
		'greaterThan' => 'gt',
		'gte' => 'gte',
		'greaterThanOrEqual' => 'gte',
		'lt' => 'lt',
		'lessThan' => 'lt',
		'lte' => 'lte',
		'lessThanOrEqual' => 'lte',
	];

	/**
	 * The property types whose leaves compile.
	 *
	 * @var array<int, string>
	 */
	private const SCALAR_TYPES = ['string', 'integer', 'number'];

	/**
	 * The numeric property types.
	 *
	 * @var array<int, string>
	 */
	private const NUMERIC_TYPES = ['integer', 'number'];

	/**
	 * Constructor.
	 *
	 * @param SegmentRuleTree $ruleTree Reads the stored rules as one group.
	 */
	public function __construct(
		private readonly SegmentRuleTree $ruleTree = new SegmentRuleTree(),
	) {
	}//end __construct()

	/**
	 * The `_where` group to send, or null when nothing can narrow the audience.
	 *
	 * @param mixed                 $rules The segment's rules, in any stored shape.
	 * @param array<string, string> $types The schema's own properties that may be sent, by name, with their type.
	 *
	 * @return array<string, mixed>|null A group (`type` AND or OR, `children`), or null.
	 *
	 * @spec openspec/changes/segment-groups-its-rules/design.md
	 */
	public function where(mixed $rules, array $types): ?array {
		return $this->node(node: $this->ruleTree->normalise(rules: $rules), types: $types);
	}//end where()

	/**
	 * One node compiled, or null when it cannot be.
	 *
	 * @param array<string, mixed>  $node  A group or a leaf.
	 * @param array<string, string> $types The sendable properties.
	 *
	 * @return array<string, mixed>|null
	 */
	private function node(array $node, array $types): ?array {
		$type = strtoupper((string)($node['type'] ?? ''));
		if ($type === 'AND') {
			return $this->andGroup(children: (array)($node['children'] ?? []), types: $types);
		}

		if ($type === 'OR') {
			return $this->orGroup(children: (array)($node['children'] ?? []), types: $types);
		}

		if ($type === 'NOT') {
			return null;
		}

		return $this->leaf(leaf: $node, types: $types);
	}//end node()

	/**
	 * An AND keeps the children that compile; dropping one only widens the result.
	 *
	 * @param array<int|string, mixed> $children The children.
	 * @param array<string, string>    $types    The sendable properties.
	 *
	 * @return array<string, mixed>|null
	 */
	private function andGroup(array $children, array $types): ?array {
		$compiled = [];
		foreach ($children as $child) {
			if (is_array($child) === false) {
				continue;
			}

			$node = $this->node(node: $child, types: $types);
			if ($node !== null) {
				$compiled[] = $node;
			}
		}

		return $this->group(type: 'AND', children: $compiled);
	}//end andGroup()

	/**
	 * An OR compiles only when every child does; a missing one would drop its rows.
	 *
	 * @param array<int|string, mixed> $children The children.
	 * @param array<string, string>    $types    The sendable properties.
	 *
	 * @return array<string, mixed>|null
	 */
	private function orGroup(array $children, array $types): ?array {
		$compiled = [];
		foreach ($children as $child) {
			$node = null;
			if (is_array($child) === true) {
				$node = $this->node(node: $child, types: $types);
			}

			if ($node === null) {
				return null;
			}

			$compiled[] = $node;
		}

		return $this->group(type: 'OR', children: $compiled);
	}//end orGroup()

	/**
	 * A group of the compiled children: null when there are none, the child itself when there is one.
	 *
	 * @param string                           $type     AND or OR.
	 * @param array<int, array<string, mixed>> $children The compiled children.
	 *
	 * @return array<string, mixed>|null
	 */
	private function group(string $type, array $children): ?array {
		if ($children === []) {
			return null;
		}

		if (count($children) === 1 && isset($children[0]['type']) === true) {
			return $children[0];
		}

		return ['type' => $type, 'children' => $children];
	}//end group()

	/**
	 * One leaf compiled, wrapped in an AND when it is a bare condition at the top.
	 *
	 * @param array<string, mixed>  $leaf  The leaf.
	 * @param array<string, string> $types The sendable properties.
	 *
	 * @return array<string, mixed>|null A leaf or a group, or null.
	 */
	private function leaf(array $leaf, array $types): ?array {
		$field = ($leaf['field'] ?? null);
		if (is_string($field) === false || in_array(($types[$field] ?? null), self::SCALAR_TYPES, true) === false) {
			return null;
		}

		$operator = (string)($leaf['operator'] ?? 'equals');
		$value = ($leaf['value'] ?? null);
		$numeric = in_array($types[$field], self::NUMERIC_TYPES, true);

		if (isset(self::COMPARISONS[$operator]) === true) {
			return $this->orNull(
				field: $field,
				node: $this->comparison(field: $field, operator: self::COMPARISONS[$operator], value: $value, numeric: $numeric),
				inclusive: in_array(self::COMPARISONS[$operator], ['gte', 'lte'], true)
			);
		}

		return match ($operator) {
			'equals' => $this->equals(field: $field, value: $value, numeric: $numeric),
			'contains' => $this->textLike(field: $field, value: $value, numeric: $numeric),
			'notEquals' => $this->notEquals(field: $field, value: $value),
			'between' => $this->orNull(field: $field, node: $this->between(field: $field, value: $value, numeric: $numeric), inclusive: true),
			'isNull' => ['field' => $field, 'operator' => 'isnull', 'value' => true],
			'isNotNull' => ['field' => $field, 'operator' => 'isnull', 'value' => false],
			default => null,
		};
	}//end leaf()

	/**
	 * A numeric comparison on a numeric property; other comparisons stay in PHP.
	 *
	 * @param string $field    The property.
	 * @param string $operator OpenRegister's operator.
	 * @param mixed  $value    The rule value.
	 * @param bool   $numeric  Whether the property is numeric.
	 *
	 * @return array<string, mixed>|null
	 */
	private function comparison(string $field, string $operator, mixed $value, bool $numeric): ?array {
		if ($numeric === false || is_numeric($value) === false || is_string($value) === true && trim($value) !== $value) {
			return null;
		}

		return ['field' => $field, 'operator' => $operator, 'value' => ($value + 0)];
	}//end comparison()

	/**
	 * An inclusive comparison also keeps rows without a value.
	 *
	 * The engine compares a missing value as equal to any number, so `gte`,
	 * `lte` and `between` match it; OpenRegister's comparison does not.
	 *
	 * @param string                    $field     The property.
	 * @param array<string, mixed>|null $node      The compiled comparison.
	 * @param bool                      $inclusive Whether the comparison includes equality.
	 *
	 * @return array<string, mixed>|null
	 */
	private function orNull(string $field, ?array $node, bool $inclusive): ?array {
		if ($node === null || $inclusive === false) {
			return $node;
		}

		return ['type' => 'OR', 'children' => [$node, ['field' => $field, 'operator' => 'isnull', 'value' => true]]];
	}//end orNull()

	/**
	 * `equals`: a closed range on a number (the engine compares numbers as numbers), a substring on text.
	 *
	 * @param string $field   The property.
	 * @param mixed  $value   The rule value.
	 * @param bool   $numeric Whether the property is numeric.
	 *
	 * @return array<string, mixed>|null
	 */
	private function equals(string $field, mixed $value, bool $numeric): ?array {
		if ($numeric === false) {
			return $this->like(field: $field, value: $value);
		}

		return $this->between(field: $field, value: [$value, $value], numeric: true);
	}//end equals()

	/**
	 * `contains` on a text property.
	 *
	 * @param string $field   The property.
	 * @param mixed  $value   The rule value.
	 * @param bool   $numeric Whether the property is numeric.
	 *
	 * @return array<string, mixed>|null
	 */
	private function textLike(string $field, mixed $value, bool $numeric): ?array {
		if ($numeric === true) {
			return null;
		}

		return $this->like(field: $field, value: $value);
	}//end textLike()

	/**
	 * Text: a case-insensitive substring, which holds every equal value and every value containing it.
	 *
	 * @param string $field The property.
	 * @param mixed  $value The rule value.
	 *
	 * @return array<string, mixed>|null
	 */
	private function like(string $field, mixed $value): ?array {
		if ((is_string($value) === false && is_int($value) === false && is_float($value) === false) || (string)$value === '') {
			return null;
		}

		return ['field' => $field, 'operator' => 'like', 'value' => (string)$value];
	}//end like()

	/**
	 * `notEquals`: a different value or none, since the engine counts a missing value as not equal.
	 *
	 * OpenRegister's `ne` compares case-sensitively, so it keeps a few rows
	 * the engine rejects (same text, other case) and never drops one it keeps.
	 *
	 * @param string $field The property.
	 * @param mixed  $value The rule value.
	 *
	 * @return array<string, mixed>|null
	 */
	private function notEquals(string $field, mixed $value): ?array {
		if (is_string($value) === false && is_int($value) === false && is_float($value) === false) {
			return null;
		}

		return [
			'type' => 'OR',
			'children' => [
				['field' => $field, 'operator' => 'ne', 'value' => $value],
				['field' => $field, 'operator' => 'isnull', 'value' => true],
			],
		];
	}//end notEquals()

	/**
	 * `between` on a numeric property: both bounds, inclusive.
	 *
	 * @param string $field   The property.
	 * @param mixed  $value   The rule value: a list of two numbers.
	 * @param bool   $numeric Whether the property is numeric.
	 *
	 * @return array<string, mixed>|null
	 */
	private function between(string $field, mixed $value, bool $numeric): ?array {
		if (is_array($value) === false || array_is_list($value) === false || count($value) !== 2) {
			return null;
		}

		$low = $this->comparison(field: $field, operator: 'gte', value: $value[0], numeric: $numeric);
		$high = $this->comparison(field: $field, operator: 'lte', value: $value[1], numeric: $numeric);
		if ($low === null || $high === null) {
			return null;
		}

		return ['type' => 'AND', 'children' => [$low, $high]];
	}//end between()
}//end class
