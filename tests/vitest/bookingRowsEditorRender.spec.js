// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The booking rows editor as rendered: the empty state, a row per value with
 * a cell slot per column, the row number, the row buttons and what they hand
 * back, the summary and the message beside the add button.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { mount } from '@vue/test-utils'
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'ariaLabel'],
		emits: ['click'],
		template: '<button :disabled="disabled" :aria-label="ariaLabel" :data-variant="variant" @click="$emit(\'click\')"><slot /></button>',
	},
}))

let BookingRowsEditor

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m)
	BookingRowsEditor = (await import('../../src/components/bookings/BookingRowsEditor.vue')).default
}, 60000)

const columns = [{ key: 'name', label: 'Name' }, { key: 'minutes', label: 'Minutes', width: '6rem' }]

/**
 * @param {object} props Props over the defaults.
 * @param {object} [slots] Slots.
 * @return {object} The mounted editor.
 */
function editor(props = {}, slots = {}) {
	return mount(BookingRowsEditor, {
		props: { title: 'Steps', columns, newRow: () => ({ name: '', minutes: 0 }), addLabel: 'Add step', emptyText: 'No steps yet', ...props },
		slots: {
			'cell-name': '<template #cell-name="{ row, index, update }"><input class="name" :data-index="index" :value="row.name" @input="update($event.target.value)"></template>',
			...slots,
		},
		global: { mocks: { t: globalThis.t } },
	})
}

describe('the booking rows editor as rendered', () => {
	it('shows the empty text and the add button without rows', async () => {
		const wrapper = editor({ modelValue: [] })
		expect(wrapper.find('.booking-rows__empty').text()).toBe('No steps yet')
		expect(wrapper.find('.booking-rows__list').exists()).toBe(false)
		await wrapper.find('.booking-rows__footer button').trigger('click')
		expect(wrapper.emitted('update:modelValue')).toEqual([[[{ name: '', minutes: 0 }]]])
	})

	it('renders a row per value with the column labels, its cells and a numbered heading link', () => {
		const wrapper = editor({ modelValue: [{ name: 'Wash' }, { name: 'Cut' }], numbered: true })
		const headingId = wrapper.find('.booking-rows__title').attributes('id')
		expect(wrapper.find('.booking-rows__title').text()).toBe('Steps')
		expect(wrapper.find('.booking-rows__list').attributes('aria-labelledby')).toBe(headingId)
		expect(wrapper.findAll('.booking-rows__columns span').map((s) => s.text())).toEqual(['', 'Name', 'Minutes', ''])
		expect(wrapper.findAll('.booking-rows__index').map((s) => s.text())).toEqual(['1', '2'])
		expect(wrapper.findAll('input.name').map((i) => [i.element.value, i.attributes('data-index')])).toEqual([['Wash', '0'], ['Cut', '1']])
		expect(wrapper.find('.booking-rows__empty').exists()).toBe(false)
		expect(wrapper.attributes('style')).toContain('24px minmax(0, 1fr) 6rem calc(var(--default-clickable-area) * 1)')
	})

	it('gives each instance its own heading id', () => {
		const a = editor({ modelValue: [] }).find('.booking-rows__title').attributes('id')
		const b = editor({ modelValue: [] }).find('.booking-rows__title').attributes('id')
		expect(a).toMatch(/^booking-rows-heading-\d+$/)
		expect(b).not.toBe(a)
	})

	it('hands a cell edit back as the row with that field set', async () => {
		const wrapper = editor({ modelValue: [{ name: 'Wash', minutes: 10 }, { name: 'Cut', minutes: 20 }] })
		await wrapper.findAll('input.name')[1].setValue('Dry')
		expect(wrapper.emitted('update:modelValue')).toEqual([[[{ name: 'Wash', minutes: 10 }, { name: 'Dry', minutes: 20 }]]])
	})

	it('offers only remove without reordering, labelled by row number', async () => {
		const wrapper = editor({ modelValue: [{ name: 'Wash' }, { name: 'Cut' }] })
		const buttons = wrapper.findAll('.booking-rows__actions button')
		expect(buttons.map((b) => b.attributes('aria-label'))).toEqual(['Remove row 1', 'Remove row 2'])
		await buttons[0].trigger('click')
		expect(wrapper.emitted('update:modelValue')).toEqual([[[{ name: 'Cut' }]]])
	})

	it('moves rows with the arrows, the first unable to go up and the last unable to go down', async () => {
		const rows = [{ name: 'A' }, { name: 'B' }, { name: 'C' }]
		const wrapper = editor({ modelValue: rows, reorderable: true, labels: { moveUp: (n) => `up ${n}`, moveDown: (n) => `down ${n}`, remove: (n) => `rm ${n}` } })
		const buttons = wrapper.findAll('.booking-rows__actions button')
		expect(buttons.map((b) => [b.attributes('aria-label'), b.attributes('disabled') !== undefined])).toEqual([
			['up 1', true], ['down 1', false], ['rm 1', false],
			['up 2', false], ['down 2', false], ['rm 2', false],
			['up 3', false], ['down 3', true], ['rm 3', false],
		])
		expect(wrapper.attributes('style')).toContain('calc(var(--default-clickable-area) * 3)')
		await buttons[3].trigger('click')
		await buttons[4].trigger('click')
		expect(wrapper.emitted('update:modelValue')).toEqual([
			[[{ name: 'B' }, { name: 'A' }, { name: 'C' }]],
			[[{ name: 'A' }, { name: 'C' }, { name: 'B' }]],
		])
		expect(rows.map((r) => r.name)).toEqual(['A', 'B', 'C'])
	})

	it('shows the summary only with rows', () => {
		const summary = { summary: '<b class="total">30 min</b>' }
		expect(editor({ modelValue: [{ name: 'A' }] }, summary).find('.booking-rows__summary .total').text()).toBe('30 min')
		expect(editor({ modelValue: [] }, summary).find('.booking-rows__summary').exists()).toBe(false)
	})

	it('shows the message as a status of its type, and none without one', () => {
		const warning = editor({ modelValue: [], message: 'Steps overlap' }).find('.booking-rows__message')
		expect([warning.text(), warning.classes(), warning.attributes('role')]).toEqual(['Steps overlap', ['booking-rows__message', 'booking-rows__message--warning'], 'status'])
		expect(editor({ modelValue: [], message: 'No time left', messageType: 'error' }).find('.booking-rows__message').classes()).toContain('booking-rows__message--error')
		expect(editor({ modelValue: [] }).find('.booking-rows__message').exists()).toBe(false)
	})

	it('treats a value that is not a list as no rows', () => {
		const wrapper = editor({ modelValue: null })
		expect(wrapper.find('.booking-rows__empty').exists()).toBe(true)
	})
})
