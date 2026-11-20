/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The known-issues list of the back office accessibility sweep: its format,
 * and the comparison of one page's axe-core violations against it.
 *
 * A violation is matched by page, axe rule id and node target. One that is not
 * on the list is new and fails the sweep; a listed one that no longer occurs
 * fails it too, so the list cannot keep issues that are already fixed.
 *
 * @spec openspec/changes/platform-accessibility-statement
 */

const ENTRY_KEYS = ['page', 'rule', 'target', 'change']

/**
 * Check the format of the known-issues list.
 *
 * @spec openspec/changes/platform-accessibility-statement
 * @param {object} list The parsed a11y-known-issues.json.
 * @return {string[]} One message per problem; empty when the list is valid.
 */
export function validateKnownIssues(list) {
	if (list === null || typeof list !== 'object' || !Array.isArray(list.issues)) {
		return ['the list must be an object with an "issues" array']
	}
	const errors = []
	const seen = new Set()
	list.issues.forEach((entry, index) => {
		if (entry === null || typeof entry !== 'object' || Array.isArray(entry)) {
			errors.push(`issue ${index} must be an object`)
			return
		}
		for (const key of ENTRY_KEYS) {
			if (typeof entry[key] !== 'string' || entry[key].trim() === '') {
				errors.push(`issue ${index} needs a non-empty "${key}"`)
			}
		}
		const id = issueKey(entry.page, entry.rule, entry.target)
		if (seen.has(id)) {
			errors.push(
				`issue ${index} repeats ${entry.page} ${entry.rule} at ${entry.target}`,
			)
		}
		seen.add(id)
	})
	return errors
}

/**
 * Compare one page's axe-core violations with the known-issues list.
 *
 * @spec openspec/changes/platform-accessibility-statement
 * @param {string} page The sample page name, as the list names it.
 * @param {Array<{id: string, nodes: Array<{target: Array<string>|string}>}>} violations The `violations` of an axe run.
 * @param {object} list The parsed a11y-known-issues.json.
 * @return {{unexpected: string[], resolved: string[]}} New violations, and listed ones that no longer occur.
 */
export function compareWithKnownIssues(page, violations, list) {
	const listed = (list && Array.isArray(list.issues) ? list.issues : []).filter(
		(entry) => entry && entry.page === page,
	)
	const found = new Map()
	for (const violation of violations || []) {
		for (const node of violation.nodes || []) {
			const target = Array.isArray(node.target)
				? node.target.join(' ')
				: String(node.target)
			found.set(issueKey(page, violation.id, target), {
				rule: violation.id,
				target,
			})
		}
	}
	const listedKeys = new Set(
		listed.map((entry) => issueKey(page, entry.rule, entry.target)),
	)
	const unexpected = []
	for (const [key, hit] of found) {
		if (!listedKeys.has(key)) {
			unexpected.push(`${page}: new violation ${hit.rule} at ${hit.target}`)
		}
	}
	const resolved = listed
		.filter((entry) => !found.has(issueKey(page, entry.rule, entry.target)))
		.map(
			(entry) =>
				`${page}: ${entry.rule} at ${entry.target} no longer occurs (fixed by ${entry.change}); remove it from a11y-known-issues.json`,
		)
	return { unexpected, resolved }
}

/**
 * The identity of one violation.
 *
 * @param {string} page The page name.
 * @param {string} rule The axe rule id.
 * @param {string} target The node target selector.
 * @return {string} The key.
 */
function issueKey(page, rule, target) {
	return `${page}\u0000${rule}\u0000${target}`
}
