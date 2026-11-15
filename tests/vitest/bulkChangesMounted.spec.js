// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Bulk changes page, mounted: what it shows while loading, on a failed
 * load and with no jobs, the row it draws per client job, and the Undo flow
 * through OpenRegister's reverse and commit calls (the API client doubled).
 *
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ list: vi.fn(), reverse: vi.fn(), commit: vi.fn() }))
const settings = vi.hoisted(() => ({ config: null, fetchSettings: vi.fn() }))

vi.mock('../../src/services/bulkJobsApi.js', () => ({ bulkJobsApi: api }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => settings }))
vi.mock('../../src/dialogs/BulkUndoDialog.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'BulkUndoDialog',
			props: ['reversal', 'busy', 'error'],
			emits: ['cancel', 'confirm'],
			render() {
				return h('div', { 'data-dialog': String(this.reversal?.id), 'data-error': this.error })
			},
		},
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcButton: { name: 'NcButton', emits: ['click'], render() { return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
		NcEmptyContent: { name: 'NcEmptyContent', props: ['name'], render() { return h('p', { 'data-empty': '' }, this.name) } },
		NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span', { 'data-loading': '' }) },
	}
})

globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: BulkChanges } = await import('../../src/views/bulk/BulkChanges.vue')

const FUTURE = '2999-01-01T00:00:00+00:00'

/**
 * A completed client job on register 3, schema 11.
 *
 * @param {object} extra Fields to override.
 * @return {object} The job.
 */
function job(extra = {}) {
	return {
		id: 1,
		registerId: 3,
		schemaId: 11,
		state: 'completed',
		created: '2026-11-13T09:00:00+00:00',
		parameters: { properties: { industry: 'Energy', size: 'L' } },
		total: 3,
		counts: { applied: 3 },
		reversible: true,
		reversibleUntil: FUTURE,
		reversesJobId: null,
		reversedByJobId: null,
		...extra,
	}
}

/**
 * Mount the page and let its load settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function page() {
	const wrapper = mount(BulkChanges, { global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	for (const fn of Object.values(api)) {
		fn.mockReset()
	}
	settings.fetchSettings.mockReset()
	settings.config = { register: 3, client_schema: 11, contact_schema: 12 }
})

describe('the Bulk changes page', () => {
	it('fetches the settings first when none are loaded, then lists the jobs', async () => {
		settings.config = null
		settings.fetchSettings.mockImplementation(async () => {
			settings.config = { register: 3, client_schema: 11, contact_schema: 12 }
		})
		api.list.mockResolvedValue([job()])

		const wrapper = await page()

		expect(settings.fetchSettings).toHaveBeenCalledTimes(1)
		expect(api.list).toHaveBeenCalledTimes(1)
		expect(wrapper.findAll('tbody tr')).toHaveLength(1)
	})

	it('does not refetch settings that are already loaded', async () => {
		api.list.mockResolvedValue([])
		await page()
		expect(settings.fetchSettings).not.toHaveBeenCalled()
	})

	it('shows the reason when the jobs cannot be loaded', async () => {
		api.list.mockRejectedValue(new Error('OpenRegister is down'))

		const wrapper = await page()

		expect(wrapper.find('[role="alert"]').text()).toBe('OpenRegister is down')
		expect(wrapper.find('table').exists()).toBe(false)
		expect(wrapper.find('[data-loading]').exists()).toBe(false)
	})

	it('says there are no bulk changes when no job is a client or contact job', async () => {
		api.list.mockResolvedValue([job({ schemaId: 99 })])

		const wrapper = await page()

		expect(wrapper.find('[data-empty]').text()).toBe('No bulk changes yet')
	})

	it('draws one row per job with its fields, count, state and undo state', async () => {
		api.list.mockResolvedValue([
			job({ id: 1, created: '2026-11-13T09:00:00+00:00' }),
			job({ id: 2, schemaId: 12, created: '2026-11-12T09:00:00+00:00', reversedByJobId: 9 }),
			job({ id: 3, created: '2026-11-11T09:00:00+00:00', reversibleUntil: '2026-01-01T00:00:00+00:00' }),
			job({ id: 4, created: 'not a date', reversible: false, reversibleUntil: null }),
		])

		const wrapper = await page()
		const rows = wrapper.findAll('tbody tr')

		expect(rows.map((row) => row.attributes('data-job'))).toEqual(['4', '1', '2', '3'])
		const cells = rows[1].findAll('td').map((td) => td.text())
		expect(cells[0]).toBe(new Date('2026-11-13T09:00:00+00:00').toLocaleString())
		expect(cells.slice(1, 4)).toEqual(['industry, size', '3', 'completed'])
		expect(rows[1].find('button').text()).toBe('Undo')
		expect(rows[2].find('td:last-child').text()).toBe('Undone')
		expect(rows[3].find('td:last-child').text()).toBe('Could be undone until ' + new Date('2026-01-01T00:00:00+00:00').toLocaleString())
		expect(rows[0].findAll('td')[0].text()).toBe('not a date')
		expect(rows[0].find('td:last-child').text()).toBe('')
	})

	it('previews the reversal on Undo, commits it on confirm and reloads', async () => {
		api.list.mockResolvedValueOnce([job()]).mockResolvedValueOnce([job({ reversedByJobId: 50 })])
		api.reverse.mockResolvedValue({ id: 50, total: 3 })
		api.commit.mockResolvedValue({})

		const wrapper = await page()
		await wrapper.find('tbody button').trigger('click')
		await flushPromises()

		expect(api.reverse).toHaveBeenCalledWith(1)
		const dialog = wrapper.findComponent({ name: 'BulkUndoDialog' })
		expect(dialog.attributes('data-dialog')).toBe('50')

		dialog.vm.$emit('confirm')
		await flushPromises()

		expect(api.commit).toHaveBeenCalledWith(50)
		expect(api.list).toHaveBeenCalledTimes(2)
		expect(wrapper.findComponent({ name: 'BulkUndoDialog' }).exists()).toBe(false)
		expect(wrapper.find('tbody td:last-child').text()).toBe('Undone')
	})

	it('keeps the dialog open with the reason when the commit fails, and cancel closes it', async () => {
		api.list.mockResolvedValue([job()])
		api.reverse.mockResolvedValue({ id: 50 })
		api.commit.mockRejectedValue(new Error('Window closed'))

		const wrapper = await page()
		await wrapper.find('tbody button').trigger('click')
		await flushPromises()
		wrapper.findComponent({ name: 'BulkUndoDialog' }).vm.$emit('confirm')
		await flushPromises()

		expect(wrapper.findComponent({ name: 'BulkUndoDialog' }).props()).toMatchObject({ error: 'Window closed', busy: false })
		expect(api.list).toHaveBeenCalledTimes(1)

		wrapper.findComponent({ name: 'BulkUndoDialog' }).vm.$emit('cancel')
		await flushPromises()
		expect(wrapper.findComponent({ name: 'BulkUndoDialog' }).exists()).toBe(false)
	})

	it('shows the reason and opens no dialog when the reversal cannot be previewed', async () => {
		api.list.mockResolvedValue([job()])
		api.reverse.mockRejectedValue(new Error('Not reversible'))

		const wrapper = await page()
		await wrapper.find('tbody button').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('Not reversible')
		expect(wrapper.findComponent({ name: 'BulkUndoDialog' }).exists()).toBe(false)
	})
})
