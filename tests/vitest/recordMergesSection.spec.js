// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Merges section on a client or contact: a merge inside its thirty day
 * window offers Undo, which asks OpenRegister to reverse it after one
 * confirmation and reads the list again; a merge older than the window shows
 * the date it became final and no Undo; an undone merge says so; a record
 * that never survived a merge says that instead.
 *
 * @spec openspec/changes/clients-reversible-merge
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ fetchMergesInto: vi.fn(), reverseMergeOperation: vi.fn() }))

vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['disabled', 'type'], emits: ['click'], template: '<button :disabled="disabled" v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span class="loading" />' },
}))
vi.mock('../../src/services/mergeOperations.js', async (importOriginal) => ({ ...(await importOriginal()), ...api }))
vi.mock('../../src/services/localeUtils.js', () => ({ formatDateFull: (value) => String(value).slice(0, 10) }))

const { default: RecordMergesSection } = await import('../../src/components/merge/RecordMergesSection.vue')

const SURVIVOR = 'c-survivor'
const NOW = new Date('2026-11-14T12:00:00Z')

/**
 * One merge operation into the survivor.
 *
 * @param {string} id The operation id.
 * @param {string} mergedAt The merge time.
 * @param {object} [extra] Fields to override.
 * @return {object} The operation.
 */
function operation(id, mergedAt, extra = {}) {
	return {
		id,
		mergedIntoUuid: SURVIVOR,
		mergedFromUuids: ['c-' + id],
		mergedAt,
		reversible: true,
		preMergeSnapshot: { objects: { ['c-' + id]: { name: 'Bakker BV ' + id } } },
		...extra,
	}
}

/**
 * Mount the section and wait for its rows.
 *
 * @param {object} props The section's props.
 * @return {Promise<object>} The wrapper.
 */
async function mounted(props = { recordId: SURVIVOR }) {
	const wrapper = mount(RecordMergesSection, {
		props,
		global: { mocks: { t: (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_m, key) => vars[key] ?? '') } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(NOW)
	api.fetchMergesInto.mockReset()
	api.reverseMergeOperation.mockReset().mockResolvedValue({ reversible: false })
})

afterEach(() => {
	vi.useRealTimers()
})

describe('the Merges section', () => {
	it('offers Undo on last week\'s merge and names the record that was merged away', async () => {
		api.fetchMergesInto.mockResolvedValue([operation('a', '2026-11-07T09:00:00Z')])
		const wrapper = await mounted()

		expect(api.fetchMergesInto).toHaveBeenCalledWith(SURVIVOR)
		const row = wrapper.find('[data-testid="record-merge-a"]')
		expect(row.text()).toContain('Bakker BV a merged into this record on 2026-11-07')
		expect(row.text()).toContain('Can be undone until 2026-12-07')
		expect(row.find('[data-testid="record-merge-undo"]').exists()).toBe(true)
	})

	it('reverses only after the confirmation, then reads the list again', async () => {
		api.fetchMergesInto
			.mockResolvedValueOnce([operation('a', '2026-11-07T09:00:00Z')])
			.mockResolvedValueOnce([operation('a', '2026-11-07T09:00:00Z', { reversible: false, reversedAt: '2026-11-14T12:00:00Z' })])
		const wrapper = await mounted()

		await wrapper.find('[data-testid="record-merge-undo"]').trigger('click')
		expect(api.reverseMergeOperation).not.toHaveBeenCalled()

		await wrapper.find('[data-testid="record-merge-confirm"]').trigger('click')
		await flushPromises()

		expect(api.reverseMergeOperation).toHaveBeenCalledWith('a')
		expect(api.fetchMergesInto).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('reversed')).toEqual([['a']])
		expect(wrapper.find('[data-testid="record-merge-a"]').text()).toContain('Undone on 2026-11-14')
		expect(wrapper.find('[data-testid="record-merge-undo"]').exists()).toBe(false)
		expect(wrapper.find('.record-merges__notice').text()).toBe('The merge was undone. Both records exist again with their own links.')
	})

	it('shows the date a merge from two months ago became final and no Undo', async () => {
		api.fetchMergesInto.mockResolvedValue([operation('b', '2026-09-15T09:00:00Z')])
		const wrapper = await mounted()

		const row = wrapper.find('[data-testid="record-merge-b"]')
		expect(row.text()).toContain('Final since 2026-10-15')
		expect(row.find('[data-testid="record-merge-undo"]').exists()).toBe(false)
	})

	it('treats a merge OpenRegister no longer marks reversible as final inside the window', async () => {
		api.fetchMergesInto.mockResolvedValue([operation('c', '2026-11-10T09:00:00Z', { reversible: false })])
		const wrapper = await mounted()

		expect(wrapper.find('[data-testid="record-merge-c"]').text()).toContain('Final since 2026-12-10')
		expect(wrapper.find('[data-testid="record-merge-undo"]').exists()).toBe(false)
	})

	it('says so when the reversal is refused and keeps the merge listed', async () => {
		api.fetchMergesInto.mockResolvedValue([operation('a', '2026-11-07T09:00:00Z')])
		api.reverseMergeOperation.mockRejectedValue(new Error('Reversal window has expired.'))
		const wrapper = await mounted()

		await wrapper.find('[data-testid="record-merge-undo"]').trigger('click')
		await wrapper.find('[data-testid="record-merge-confirm"]').trigger('click')
		await flushPromises()

		expect(wrapper.emitted('reversed')).toBeUndefined()
		expect(wrapper.find('.record-merges__notice').text()).toBe('The merge could not be undone. The reversal window may have passed.')
		expect(wrapper.find('[data-testid="record-merge-a"]').exists()).toBe(true)
	})

	it('says that nothing was merged into a record that never survived a merge', async () => {
		api.fetchMergesInto.mockResolvedValue([])
		const wrapper = await mounted()

		expect(wrapper.find('[data-testid="record-merges-empty"]').text()).toBe('No other record was merged into this one.')
	})

	it('names a failed load instead of an empty list', async () => {
		api.fetchMergesInto.mockRejectedValue(new Error('500'))
		const wrapper = await mounted()

		expect(wrapper.find('[role="alert"]').text()).toBe('The merges of this record could not be loaded.')
		expect(wrapper.find('[data-testid="record-merges-empty"]').exists()).toBe(false)
	})

	it('reads the record id from the section context when no prop is given', async () => {
		api.fetchMergesInto.mockResolvedValue([])
		mount(RecordMergesSection, {
			global: { mocks: { t: (_app, text) => text }, provide: { cnSectionContext: { value: { objectId: 'c-ctx' } } } },
		})
		await flushPromises()

		expect(api.fetchMergesInto).toHaveBeenCalledWith('c-ctx')
	})
})
