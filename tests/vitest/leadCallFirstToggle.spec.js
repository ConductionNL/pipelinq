// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Call first on the lead list is a true toggle: switching it off brings back
 * the sort it replaced instead of dropping to no sort at all.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-the-lead-list-shows-and-sorts-by-score-req-lscore-001
 */

import { beforeAll, describe, expect, it, vi } from 'vitest'
import { CALL_FIRST_SORT } from '../../src/services/leadScore.js'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnIndexPage: {},
	openRowTarget: vi.fn(),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/settings.js', () => ({
	useSettingsStore: () => ({}),
}))

let LeadList

beforeAll(async () => {
	globalThis.t = (app, text) => text
	LeadList = (await import('../../src/views/leads/LeadList.vue')).default
})

/**
 * A list context whose index page applies every sort event it gets.
 *
 * @param {Array} keys The sort the list starts with.
 * @return {object}
 */
function list(keys) {
	const index = {
		effectiveSortKeys: keys,
		onSortEvent: vi.fn(({ keys: next }) => {
			index.effectiveSortKeys = next
		}),
	}
	return { $refs: { index }, ...LeadList.data.call({}) }
}

describe('setCallFirst', () => {
	it('switching off restores the sort it replaced', () => {
		const byValue = [{ key: 'value', order: 'desc' }]
		const ctx = list(byValue)

		LeadList.methods.setCallFirst.call(ctx, true)
		expect(ctx.$refs.index.effectiveSortKeys).toEqual(CALL_FIRST_SORT)

		LeadList.methods.setCallFirst.call(ctx, false)
		expect(ctx.$refs.index.effectiveSortKeys).toEqual(byValue)
	})

	it('switching off with no earlier sort drops to none', () => {
		const ctx = list([])

		LeadList.methods.setCallFirst.call(ctx, true)
		LeadList.methods.setCallFirst.call(ctx, false)

		expect(ctx.$refs.index.effectiveSortKeys).toEqual([])
	})
})
