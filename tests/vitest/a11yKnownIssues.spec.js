/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The known-issues list of the back office accessibility sweep: the shipped
 * list is well formed, a new violation is reported, and a listed issue that no
 * longer occurs is reported too (REQ-PAS-001).
 *
 * @spec openspec/changes/platform-accessibility-statement
 */

import { describe, expect, it } from 'vitest'
import list from '../e2e/a11y-known-issues.json'
import {
	compareWithKnownIssues,
	validateKnownIssues,
} from '../e2e/helpers/a11yKnownIssues.js'

const prospect = {
	page: 'Dashboard',
	rule: 'nested-interactive',
	target: '.prospect-widget__header',
	change: 'keyboard-accessible-click-toggles',
}

describe('the known-issues list', () => {
	it('ships well formed', () => {
		expect(validateKnownIssues(list)).toEqual([])
	})

	it('refuses a list without an issues array', () => {
		expect(validateKnownIssues({})).toEqual([
			'the list must be an object with an "issues" array',
		])
		expect(validateKnownIssues(null)).toEqual([
			'the list must be an object with an "issues" array',
		])
	})

	it('names every missing field of an entry', () => {
		expect(
			validateKnownIssues({
				issues: [{ page: 'Tickets', rule: '', target: '#x' }],
			}),
		).toEqual([
			'issue 0 needs a non-empty "rule"',
			'issue 0 needs a non-empty "change"',
		])
	})

	it('refuses an entry that is not an object, and a repeated entry', () => {
		expect(
			validateKnownIssues({ issues: ['x', prospect, { ...prospect }] }),
		).toEqual([
			'issue 0 must be an object',
			'issue 2 repeats Dashboard nested-interactive at .prospect-widget__header',
		])
	})
})

describe('comparing a page with the list', () => {
	it('reports a new unlabeled button with its page and target', () => {
		const violations = [
			{
				id: 'button-name',
				nodes: [{ target: ['.client-detail', 'button.icon-only'] }],
			},
		]
		expect(
			compareWithKnownIssues('ClientDetail', violations, { issues: [] }),
		).toEqual({
			unexpected: [
				'ClientDetail: new violation button-name at .client-detail button.icon-only',
			],
			resolved: [],
		})
	})

	it('accepts a violation that is on the list', () => {
		const violations = [
			{
				id: 'nested-interactive',
				nodes: [{ target: ['.prospect-widget__header'] }],
			},
		]
		expect(
			compareWithKnownIssues('Dashboard', violations, { issues: [prospect] }),
		).toEqual({ unexpected: [], resolved: [] })
	})

	it('says a fixed known issue can leave the list', () => {
		expect(
			compareWithKnownIssues('Dashboard', [], { issues: [prospect] }),
		).toEqual({
			unexpected: [],
			resolved: [
				'Dashboard: nested-interactive at .prospect-widget__header no longer occurs (fixed by keyboard-accessible-click-toggles); remove it from a11y-known-issues.json',
			],
		})
	})

	it('only weighs the entries of the page it checks', () => {
		expect(
			compareWithKnownIssues('Tickets', [], { issues: [prospect] }),
		).toEqual({ unexpected: [], resolved: [] })
	})
})
