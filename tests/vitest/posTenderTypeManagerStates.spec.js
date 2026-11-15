// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The till payment-methods section on the admin page, mounted over its real
 * tender-type list: the title-row button opens an empty form, each row draws
 * its active badge and its Edit and Delete actions, and the list says when it
 * is empty or could not load (axios doubled).
 *
 * @spec openspec/changes/archive/2026-06-14-pos-split-tender/tasks.md#8.1
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), delete: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))

const named = vi.hoisted(() => (name) => async () => {
	const { h } = await import('vue')
	return {
		default: {
			name,
			props: ['tenderType', 'name', 'message'],
			emits: ['close', 'saved', 'confirm', 'cancel'],
			render() {
				return h('div', { 'data-stub': name, 'data-type': this.tenderType ? this.tenderType.code : 'none', 'data-message': this.message })
			},
		},
	}
})
vi.mock('../../src/modals/PosTenderTypeFormDialog.vue', named('PosTenderTypeFormDialog'))
vi.mock('../../src/dialogs/ConfirmDialog.vue', named('ConfirmDialog'))
vi.mock('vue-material-design-icons/Plus.vue', named('Plus'))

vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcButton: { name: 'NcButton', emits: ['click'], render() { return h('button', { onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()]) } },
		NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span', { 'data-loading': '' }) },
	}
})
vi.mock('@conduction/nextcloud-vue', async () => {
	const { h } = await import('vue')
	return {
		CnSettingsSection: {
			name: 'CnSettingsSection',
			props: ['name'],
			render() {
				return h('section', { 'data-name': this.name }, [h('header', this.$slots.actions?.()), this.$slots.default?.()])
			},
		},
		CnDataTable: {
			name: 'CnDataTable',
			props: ['rows', 'columns'],
			render() {
				return h('table', [
					h('thead', h('tr', h('th', this.$slots['actions-header']?.()))),
					h('tbody', this.rows.map((row) => h('tr', { 'data-row': row.id }, [
						h('td', { 'data-col': 'flags' }, row.flags),
						h('td', { 'data-col': 'isActive' }, this.$slots['column-isActive']?.({ row })),
						h('td', { 'data-col': 'actions' }, this.$slots['row-actions']?.({ row })),
					]))),
				])
			},
		},
	}
})

const translate = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (w, k) => vars[k] ?? w)
globalThis.t = translate
const { default: PosTenderTypeManager } = await import('../../src/views/settings/PosTenderTypeManager.vue')

/**
 * Mount the section and let the list load.
 *
 * @return {Promise<object>} The wrapper.
 */
async function section() {
	const wrapper = mount(PosTenderTypeManager, { global: { mocks: { t: translate } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	http.get.mockReset()
	http.delete.mockReset()
})

describe('the till payment-methods section', () => {
	it('opens an empty tender-type form from the title-row button', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })

		const wrapper = await section()
		expect(wrapper.find('section').attributes('data-name')).toBe('Payment methods at the till')
		expect(wrapper.find('[data-stub="PosTenderTypeFormDialog"]').exists()).toBe(false)

		const button = wrapper.find('header button')
		expect(button.text()).toBe('New tender type')
		await button.trigger('click')

		expect(wrapper.find('[data-stub="PosTenderTypeFormDialog"]').attributes('data-type')).toBe('none')
	})

	it('says there are no tender types yet', async () => {
		http.get.mockResolvedValue({ data: { results: [] } })

		const wrapper = await section()

		expect(wrapper.find('.pos-tender-type-list__empty').text()).toBe('No tender types defined yet.')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('says why the tender types could not be loaded', async () => {
		http.get.mockRejectedValue({ response: { data: { error: 'No access' } } })

		const wrapper = await section()

		expect(wrapper.find('[role="alert"]').text()).toBe('No access')
	})

	it('draws a row per tender type, sorted, with its badge, flags and actions', async () => {
		http.get.mockResolvedValue({
			data: {
				results: [
					{ id: 't2', code: 'PIN', name: 'Pin', sortOrder: 2, isActive: false, requiresPin: true, requiresReference: true },
					{ id: 't1', code: 'CASH', name: 'Cash', sortOrder: 1, isActive: true, allowsChange: true },
					{ id: 't3', code: 'GIFT', name: 'Gift card', sortOrder: 3, isActive: true },
				],
			},
		})

		const wrapper = await section()
		const rows = wrapper.findAll('tbody tr')

		expect(rows.map((row) => row.attributes('data-row'))).toEqual(['t1', 't2', 't3'])
		expect(rows.map((row) => row.find('[data-col="flags"]').text())).toEqual(['Change', 'Ref, PIN', '—'])
		expect(rows.map((row) => row.find('.cn-status-badge').text())).toEqual(['Active', 'Inactive', 'Active'])
		expect(rows[1].find('.cn-status-badge').classes()).toContain('cn-status-badge--default')
		expect(wrapper.find('thead .hidden-visually').text()).toBe('Actions')

		const [edit, remove] = rows[1].findAll('[data-col="actions"] button')
		await edit.trigger('click')
		expect(wrapper.find('[data-stub="PosTenderTypeFormDialog"]').attributes('data-type')).toBe('PIN')

		await remove.trigger('click')
		expect(wrapper.find('[data-stub="ConfirmDialog"]').attributes('data-message')).toBe('Delete tender type "Pin"? Active tenders referencing this type block deletion.')
	})
})
