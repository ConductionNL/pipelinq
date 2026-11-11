// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// A segment's `rules` tree in the editor: read as a group, and counted for
// unfinished conditions before anything is sent to the preview.

/**
 * @return {{type: string, children: Array}} An empty AND group.
 *
 * @spec openspec/changes/segment-groups-its-rules/specs/marketing-ui/spec.md#scenario-a-segment-stored-as-a-bare-condition-opens-as-a-group
 */
export function emptyTree() {
	return { type: 'AND', children: [] }
}

/**
 * A detached copy of stored rules, read as a group: a group keeps its shape
 * with `type` upper-cased; a bare condition, a list of nodes, or `children`
 * without a `type` read as an AND group of them; anything else reads as an
 * empty AND group. Only a save writes this form back.
 *
 * @param {unknown} rules The stored rules.
 * @return {{type: string, children: Array}} The group.
 *
 * @spec openspec/changes/segment-groups-its-rules/specs/marketing-ui/spec.md#scenario-a-segment-stored-as-a-bare-condition-opens-as-a-group
 */
export function cloneTree(rules) {
	let copy
	try {
		copy = JSON.parse(JSON.stringify(rules ?? null))
	} catch {
		return emptyTree()
	}
	return treeFrom(copy)
}

/**
 * A copied rule value as a group: a list becomes an AND group's children,
 * anything that is not an object an empty group.
 *
 * @param {unknown} copy The copied rules.
 * @return {{type: string, children: Array}} The group.
 *
 * @spec openspec/changes/segment-groups-its-rules/specs/marketing-ui/spec.md#scenario-a-segment-stored-as-a-bare-condition-opens-as-a-group
 */
function treeFrom(copy) {
	if (Array.isArray(copy)) {
		return { type: 'AND', children: copy }
	}
	if (!copy || typeof copy !== 'object') {
		return emptyTree()
	}
	return groupFrom(copy)
}

/**
 * A copied rule object as a group: a group keeps its children with its
 * type in capitals, a bare condition is wrapped, anything else is empty.
 *
 * @param {object} copy The copied rule object.
 * @return {{type: string, children: Array}} The group.
 *
 * @spec openspec/changes/segment-groups-its-rules/specs/marketing-ui/spec.md#scenario-a-segment-stored-as-a-bare-condition-opens-as-a-group
 */
function groupFrom(copy) {
	if (Array.isArray(copy.children)) {
		return { ...copy, type: copy.type ? String(copy.type).toUpperCase() : 'AND' }
	}
	if ('field' in copy || 'operator' in copy) {
		return { type: 'AND', children: [copy] }
	}
	return emptyTree()
}

/**
 * A tree's condition count, and how many conditions or groups are not filled
 * in yet. An empty group counts as one unfinished node.
 *
 * @param {object} node A rule tree node.
 * @return {{leaves: number, unfinished: number}} The counts.
 *
 * @spec openspec/changes/segment-groups-its-rules/specs/marketing-ui/spec.md#scenario-the-preview-counts-the-whole-tree
 */
export function tally(node) {
	if (Array.isArray(node?.children)) {
		return groupTally(node.children)
	}
	return leafTally(node)
}

/**
 * A group's counts: the sum of its children's, or one unfinished node
 * when it has none.
 *
 * @param {Array<object>} children The group's children.
 * @return {{leaves: number, unfinished: number}} The counts.
 *
 * @spec openspec/changes/segment-groups-its-rules/specs/marketing-ui/spec.md#scenario-the-preview-counts-the-whole-tree
 */
function groupTally(children) {
	if (children.length === 0) {
		return { leaves: 0, unfinished: 1 }
	}
	return children.reduce(
		(sum, child) => {
			const sub = tally(child)
			return {
				leaves: sum.leaves + sub.leaves,
				unfinished: sum.unfinished + sub.unfinished,
			}
		},
		{ leaves: 0, unfinished: 0 },
	)
}

/**
 * A condition's counts: one leaf, unfinished without a field, an operator
 * or a value.
 *
 * @param {object} node The condition.
 * @return {{leaves: number, unfinished: number}} The counts.
 *
 * @spec openspec/changes/segment-groups-its-rules/specs/marketing-ui/spec.md#scenario-the-preview-counts-the-whole-tree
 */
function leafTally(node) {
	const unfinished = !node?.field || !node?.operator || hasNoValue(node)
	return { leaves: 1, unfinished: unfinished ? 1 : 0 }
}

/**
 * @param {object} node The condition.
 * @return {boolean} Whether its value is empty, null or missing.
 */
function hasNoValue(node) {
	return node?.value === '' || node?.value === null || node?.value === undefined
}
