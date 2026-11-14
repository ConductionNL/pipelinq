// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The working-hours and vacations editors of a bookable resource, mounted on
 * the real BookingRowsEditor: the columns and cells they draw, the row a new
 * entry starts as, how an edit or removal comes back out as a new rows array,
 * and the rule error (or the host form's error, which wins) shown beside the
 * add button.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/**
 * A model-bound input stub that renders its model value and aria-label.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function field(name) {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'type'],
		emits: ['update:modelValue'],
		render() {
			return h('input', {
				'data-stub': name,
				'data-type': this.type,
				'aria-label': this.$attrs['aria-label'] || this.$attrs['aria-label-combobox'],
				value: this.modelValue,
			})
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		inheritAttrs: false,
		emits: ['click'],
		render() {
			return h('button', { 'aria-label': this.$attrs['aria-label'], onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcSelect: field('NcSelect'),
	NcTextField: field('NcTextField'),
}))

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
const mountOptions = { global: { mocks: { t: globalThis.t } } }

const { default: ResourceHoursEditor } = await import('../../src/components/bookings/ResourceHoursEditor.vue')
const { default: ResourceVacationsEditor } = await import('../../src/components/bookings/ResourceVacationsEditor.vue')

const HOURS = [
	{ day: 'monday', openTime: '09:00', closeTime: '17:00' },
	{ day: 'friday', openTime: '08:00', closeTime: '12:00' },
]
const VACATIONS = [{ startDate: '2026-07-01', endDate: '2026-07-14', label: 'Summer' }]

/**
 * The add button of a rows editor.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper The editor.
 * @return {import('@vue/test-utils').DOMWrapper}
 */
function addButton(wrapper) {
	return wrapper.find('.booking-rows__footer button')
}

describe('ResourceHoursEditor', () => {
	it('draws a day, opening and closing time per row', () => {
		const wrapper = mount(ResourceHoursEditor, { ...mountOptions, props: { modelValue: HOURS } })

		expect(wrapper.find('.booking-rows__title').text()).toBe('Working hours')
		expect(wrapper.findAll('.booking-rows__columns span').map((s) => s.text()).filter(Boolean))
			.toEqual(['Day', 'Opening time', 'Closing time'])
		const row = wrapper.findAll('.booking-rows__row')[1]
		const inputs = row.findAll('input')
		expect(inputs.map((i) => i.attributes('aria-label')))
			.toEqual(['Row 2 day', 'Row 2 open time', 'Row 2 close time'])
		expect(inputs.map((i) => i.element.value)).toEqual(['friday', '08:00', '12:00'])
		expect(inputs[1].attributes('data-type')).toBe('time')
		expect(row.find('button').attributes('aria-label')).toBe('Remove working hours row 2')
		expect(wrapper.find('.booking-rows__message').exists()).toBe(false)
	})

	it('offers the seven weekdays by value', () => {
		const wrapper = mount(ResourceHoursEditor, { ...mountOptions, props: { modelValue: HOURS } })
		const select = wrapper.findComponent({ name: 'NcSelect' })

		expect(select.props('options').map((o) => o.value))
			.toEqual(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
		expect(select.props('options')[6].label).toBe('Sunday')
	})

	it('shows the empty text for no rows or a value that is not an array', () => {
		const wrapper = mount(ResourceHoursEditor, { ...mountOptions, props: { modelValue: null } })

		expect(wrapper.find('.booking-rows__empty').text())
			.toBe('No working hours yet. The resource is unavailable on days without a row.')
		expect(addButton(wrapper).text()).toBe('Add working hours')
	})

	it('adds a Monday 09:00 to 17:00 row', async () => {
		const wrapper = mount(ResourceHoursEditor, { ...mountOptions, props: { modelValue: [HOURS[1]] } })

		await addButton(wrapper).trigger('click')

		expect(wrapper.emitted('update:modelValue')[0][0])
			.toEqual([HOURS[1], { day: 'monday', openTime: '09:00', closeTime: '17:00' }])
	})

	it('emits the rows with one cell changed, and without a removed row', async () => {
		const wrapper = mount(ResourceHoursEditor, { ...mountOptions, props: { modelValue: HOURS } })

		wrapper.findAllComponents({ name: 'NcTextField' })[3].vm.$emit('update:modelValue', '18:00')
		wrapper.findAllComponents({ name: 'NcSelect' })[0].vm.$emit('update:modelValue', 'tuesday')
		await wrapper.findAll('.booking-rows__row')[0].find('button').trigger('click')

		const emitted = wrapper.emitted('update:modelValue').map(([rows]) => rows)
		expect(emitted[0]).toEqual([HOURS[0], { ...HOURS[1], closeTime: '18:00' }])
		expect(emitted[1]).toEqual([{ ...HOURS[0], day: 'tuesday' }, HOURS[1]])
		expect(emitted[2]).toEqual([HOURS[1]])
		expect(HOURS[1].closeTime).toBe('12:00')
	})

	it('shows the rule error for a row that closes before it opens', () => {
		const wrapper = mount(ResourceHoursEditor, {
			...mountOptions,
			props: { modelValue: [{ day: 'monday', openTime: '17:00', closeTime: '09:00' }] },
		})

		const message = wrapper.find('.booking-rows__message')
		expect(message.text()).toBe('Open time must be before close time.')
		expect(message.classes()).toContain('booking-rows__message--error')
	})

	it('shows the host form error instead of the rule error', () => {
		const wrapper = mount(ResourceHoursEditor, {
			...mountOptions,
			props: { modelValue: [{ day: 'monday', openTime: '17:00', closeTime: '09:00' }], error: 'Save failed' },
		})

		expect(wrapper.find('.booking-rows__message').text()).toBe('Save failed')
	})

	it('renders empty time fields for a row without times', () => {
		const wrapper = mount(ResourceHoursEditor, { ...mountOptions, props: { modelValue: [{ day: 'sunday' }] } })

		expect(wrapper.findAllComponents({ name: 'NcTextField' }).map((f) => f.props('modelValue'))).toEqual(['', ''])
		expect(wrapper.find('.booking-rows__message').exists()).toBe(false)
	})

	it('keeps the day select ids unique per editor', () => {
		const a = mount(ResourceHoursEditor, { ...mountOptions, props: { modelValue: HOURS } })
		const b = mount(ResourceHoursEditor, { ...mountOptions, props: { modelValue: HOURS } })
		const id = (w) => w.findComponent({ name: 'NcSelect' }).vm.$attrs.inputId

		expect(id(a)).toMatch(/^resource-hours-day-\d+-0$/)
		expect(id(a)).not.toBe(id(b))
	})

	it('has no reorder labels', () => {
		const labels = ResourceHoursEditor.computed.labels.call({})
		expect(labels.moveUp(1)).toBe('')
		expect(labels.moveDown(1)).toBe('')
	})
})

describe('ResourceVacationsEditor', () => {
	it('draws a start date, end date and label per row', () => {
		const wrapper = mount(ResourceVacationsEditor, { ...mountOptions, props: { modelValue: VACATIONS } })

		expect(wrapper.find('.booking-rows__title').text()).toBe('Vacations / unavailable windows')
		expect(wrapper.findAll('.booking-rows__columns span').map((s) => s.text()).filter(Boolean))
			.toEqual(['Start date', 'End date', 'Label'])
		const inputs = wrapper.findAll('.booking-rows__row input')
		expect(inputs.map((i) => i.element.value)).toEqual(['2026-07-01', '2026-07-14', 'Summer'])
		expect(inputs[0].attributes('aria-label')).toBe('Vacation 1 start date')
		expect(inputs[0].attributes('data-type')).toBe('date')
		expect(wrapper.find('.booking-rows__row button').attributes('aria-label')).toBe('Remove vacation 1')
	})

	it('shows the empty text and adds an empty row', async () => {
		const wrapper = mount(ResourceVacationsEditor, { ...mountOptions, props: { modelValue: 'x' } })

		expect(wrapper.find('.booking-rows__empty').text()).toBe('No vacations recorded.')
		expect(addButton(wrapper).text()).toBe('Add vacation')
		await addButton(wrapper).trigger('click')

		expect(wrapper.emitted('update:modelValue')[0][0]).toEqual([{ startDate: '', endDate: '', label: '' }])
	})

	it('emits the rows with an edited label, and without a removed row', async () => {
		const wrapper = mount(ResourceVacationsEditor, { ...mountOptions, props: { modelValue: VACATIONS } })

		wrapper.findAllComponents({ name: 'NcTextField' })[2].vm.$emit('update:modelValue', 'Holiday')
		await wrapper.find('.booking-rows__row button').trigger('click')

		const emitted = wrapper.emitted('update:modelValue').map(([rows]) => rows)
		expect(emitted[0]).toEqual([{ ...VACATIONS[0], label: 'Holiday' }])
		expect(emitted[1]).toEqual([])
	})

	it('shows the rule error for a window ending before it starts, or the host error', () => {
		const backwards = [{ startDate: '2026-07-14', endDate: '2026-07-01' }]
		const rule = mount(ResourceVacationsEditor, { ...mountOptions, props: { modelValue: backwards } })
		const host = mount(ResourceVacationsEditor, { ...mountOptions, props: { modelValue: backwards, error: 'Conflict' } })
		const sameDay = mount(ResourceVacationsEditor, {
			...mountOptions,
			props: { modelValue: [{ startDate: '2026-07-01', endDate: '2026-07-01' }] },
		})

		expect(rule.find('.booking-rows__message').text()).toBe('Vacation start date must be on or before the end date.')
		expect(host.find('.booking-rows__message').text()).toBe('Conflict')
		expect(sameDay.find('.booking-rows__message').exists()).toBe(false)
	})

	it('renders empty fields for a row without values', () => {
		const wrapper = mount(ResourceVacationsEditor, { ...mountOptions, props: { modelValue: [{}] } })

		expect(wrapper.findAllComponents({ name: 'NcTextField' }).map((f) => f.props('modelValue'))).toEqual(['', '', ''])
	})

	it('has no reorder labels', () => {
		const labels = ResourceVacationsEditor.computed.labels.call({})
		expect(labels.moveUp(1)).toBe('')
		expect(labels.moveDown(1)).toBe('')
	})
})
