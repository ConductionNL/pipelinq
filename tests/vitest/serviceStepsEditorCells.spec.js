// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The service steps editor's cells: what each cell shows for a step and the
 * value each one writes back, the product catalogue it loads, and the total
 * it checks against the service duration.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))

/** Every update a cell made, as [column, row index, value]. */
const updates = []

/**
 * An input stub whose model value the test can read and set.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function input(name) {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'reduce', 'loading'],
		emits: ['update:modelValue'],
		render() {
			return h('span', { 'data-input': name, 'data-label': this.$attrs['aria-label'] || this.$attrs['aria-label-combobox'] })
		},
	}
}

vi.mock('@nextcloud/vue', () => ({ NcCheckboxRadioSwitch: input('NcCheckboxRadioSwitch'), NcSelect: input('NcSelect'), NcTextField: input('NcTextField') }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('../../src/components/bookings/BookingRowsEditor.vue', () => ({
	default: {
		name: 'BookingRowsEditor',
		props: ['modelValue', 'columns', 'newRow', 'labels', 'message', 'emptyText', 'addLabel', 'title'],
		emits: ['update:modelValue'],
		render() {
			const rows = this.modelValue.map((row, index) => h('div', { 'data-row': index }, this.columns.map((column) => h('div', { 'data-cell': column.key }, this.$slots[`cell-${column.key}`]?.({ row, index, update: (value) => updates.push([column.key, index, value]) })))))
			return h('div', [h('p', { 'data-summary': true }, this.$slots.summary?.()), ...rows])
		},
	},
}))
const store = { fetchCollection: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

let ServiceStepsEditor

beforeAll(async () => {
	globalThis.t = t
	ServiceStepsEditor = (await import('../../src/components/bookings/ServiceStepsEditor.vue')).default
})

beforeEach(() => {
	updates.length = 0
	store.fetchCollection.mockReset()
})

/**
 * Mount the editor over the given steps after its catalogue has loaded.
 *
 * @param {Array} steps The steps.
 * @param {number|null} durationMinutes The service duration.
 * @return {Promise<object>} The wrapper.
 */
async function editor(steps, durationMinutes = null) {
	const wrapper = mount(ServiceStepsEditor, { props: { modelValue: steps, durationMinutes }, global: { mocks: { t } } })
	await flushPromises()
	return wrapper
}

/**
 * @param {object} wrapper The editor.
 * @param {number} index A row.
 * @param {string} key A column.
 * @return {object} The input component in that cell.
 */
function cell(wrapper, index, key) {
	const found = wrapper.find(`[data-row="${index}"] [data-cell="${key}"] [data-input]`)
	return wrapper.findAllComponents({ name: found.attributes('data-input') }).find((component) => component.element === found.element)
}

describe('ServiceStepsEditor cells', () => {
	it('shows each step in its cells, with a catalogue product by name and an unknown one by id', async () => {
		store.fetchCollection.mockResolvedValue([{ id: 'p1', name: 'Implementation' }, { id: 'p2', title: 'Hosting' }, { id: 'p3' }, { name: 'no id' }, null])
		const wrapper = await editor([
			{ productId: 'p1', quantity: 8, unit: 'hour', durationMinutes: 30, resourceType: 'staff', skillRequired: 'php', allowGap: true },
			{ productId: 'gone', durationMinutes: 15, resourceType: 'room' },
			{},
		], 45)

		expect(store.fetchCollection).toHaveBeenCalledWith('product', { _limit: 500 })
		expect(wrapper.emitted('catalogue')[0][0]).toHaveLength(5)
		expect(cell(wrapper, 0, 'productId').props('options')).toEqual([{ value: 'p1', label: 'Implementation' }, { value: 'p2', label: 'Hosting' }, { value: 'p3', label: 'p3' }])
		expect(cell(wrapper, 0, 'productId').props('modelValue')).toEqual({ value: 'p1', label: 'Implementation' })
		expect(cell(wrapper, 1, 'productId').props('modelValue')).toEqual({ value: 'gone', label: 'gone' })
		expect(cell(wrapper, 2, 'productId').props('modelValue')).toBeNull()
		expect(cell(wrapper, 0, 'productId').props('loading')).toBe(false)
		expect([cell(wrapper, 0, 'quantity').props('modelValue'), cell(wrapper, 1, 'quantity').props('modelValue')]).toEqual(['8', ''])
		expect([cell(wrapper, 0, 'unit').props('modelValue'), cell(wrapper, 1, 'unit').props('modelValue')]).toEqual(['hour', null])
		expect([cell(wrapper, 0, 'durationMinutes').props('modelValue'), cell(wrapper, 2, 'durationMinutes').props('modelValue')]).toEqual(['30', '0'])
		expect(cell(wrapper, 1, 'resourceType').props('modelValue')).toBe('room')
		expect([cell(wrapper, 0, 'skillRequired').props('modelValue'), cell(wrapper, 1, 'skillRequired').props('modelValue')]).toEqual(['php', ''])
		expect([cell(wrapper, 0, 'allowGap').props('modelValue'), cell(wrapper, 1, 'allowGap').props('modelValue')]).toEqual([true, false])
		expect(cell(wrapper, 1, 'quantity').attributes('data-label')).toBe('Step 2 quantity')
		expect(wrapper.find('[data-summary]').text()).toBe('45 of 45 min')
	})

	it('writes back what each cell means, clearing to undefined where a value is removed', async () => {
		store.fetchCollection.mockResolvedValue([])
		const wrapper = await editor([{ durationMinutes: 10, resourceType: 'staff' }], 10)

		cell(wrapper, 0, 'productId').vm.$emit('update:modelValue', { value: 'p9', label: 'X' })
		cell(wrapper, 0, 'productId').vm.$emit('update:modelValue', null)
		cell(wrapper, 0, 'quantity').vm.$emit('update:modelValue', '2.5')
		cell(wrapper, 0, 'quantity').vm.$emit('update:modelValue', '')
		cell(wrapper, 0, 'unit').vm.$emit('update:modelValue', 'month')
		cell(wrapper, 0, 'unit').vm.$emit('update:modelValue', null)
		cell(wrapper, 0, 'durationMinutes').vm.$emit('update:modelValue', '25')
		cell(wrapper, 0, 'durationMinutes').vm.$emit('update:modelValue', 'abc')
		cell(wrapper, 0, 'resourceType').vm.$emit('update:modelValue', 'equipment')
		cell(wrapper, 0, 'skillRequired').vm.$emit('update:modelValue', 'nl')
		cell(wrapper, 0, 'allowGap').vm.$emit('update:modelValue', true)

		expect(updates).toEqual([
			['productId', 0, 'p9'],
			['productId', 0, undefined],
			['quantity', 0, 2.5],
			['quantity', 0, undefined],
			['unit', 0, 'month'],
			['unit', 0, undefined],
			['durationMinutes', 0, 25],
			['durationMinutes', 0, 0],
			['resourceType', 0, 'equipment'],
			['skillRequired', 0, 'nl'],
			['allowGap', 0, true],
		])
		expect(cell(wrapper, 0, 'unit').props('reduce')({ value: 'day' })).toBe('day')
		expect(cell(wrapper, 0, 'resourceType').props('reduce')({ value: 'room' })).toBe('room')
	})

	it('forwards the rows editor model, warns on a mismatched total and starts new steps as staff', async () => {
		store.fetchCollection.mockResolvedValue('not a list')
		const wrapper = await editor([{ durationMinutes: '20' }, { durationMinutes: 'x' }], 30)
		const rows = wrapper.findComponent({ name: 'BookingRowsEditor' })

		expect(wrapper.emitted('catalogue')).toEqual([[[]]])
		expect(rows.props('message')).toBe('Multi-step total (20 min) does not match Duration (30 min).')
		expect(wrapper.find('[data-summary]').text()).toBe('20 of 30 min')
		expect(rows.props('newRow')()).toEqual({ durationMinutes: 0, resourceType: 'staff', skillRequired: '', allowGap: false })
		expect(rows.props('labels').remove(2)).toBe('Remove step 2')
		expect(rows.props('labels').moveUp(1)).toBe('Move step 1 up')
		expect(rows.props('labels').moveDown(3)).toBe('Move step 3 down')
		rows.vm.$emit('update:modelValue', [{ durationMinutes: 5 }])
		expect(wrapper.emitted('update:modelValue')).toEqual([[[{ durationMinutes: 5 }]]])
	})

	it('has no warning without steps and an empty catalogue when loading fails', async () => {
		store.fetchCollection.mockRejectedValue(new Error('offline'))
		const wrapper = await editor(null)

		expect(wrapper.findComponent({ name: 'BookingRowsEditor' }).props('message')).toBe('')
		expect(wrapper.find('[data-summary]').text()).toBe('0 of 0 min')
		expect(wrapper.vm.products).toEqual([])
		expect(wrapper.vm.productsLoading).toBe(false)
		expect(wrapper.emitted('catalogue')).toBeUndefined()
	})
})
