// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The working-hours and vacations editors of a resource: their v-model rows
 * (none when the value is not a list), the host form's error, the live rule
 * check, and the rows they hand back.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcSelect: {}, NcTextField: {} }))
vi.mock('../../src/components/bookings/BookingRowsEditor.vue', () => ({
	default: { name: 'BookingRowsEditor', props: ['modelValue', 'error'], emits: ['update:modelValue'], template: '<div class="rows" :data-error="error">{{ modelValue.length }}</div>' },
}))

globalThis.t = (_app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m)

const editors = {
	ResourceHoursEditor: (await import('../../src/components/bookings/ResourceHoursEditor.vue')).default,
	ResourceVacationsEditor: (await import('../../src/components/bookings/ResourceVacationsEditor.vue')).default,
}

describe.each(Object.keys(editors))('%s', (name) => {
	const editor = editors[name]
	const merged = (key) => Object.assign({}, ...(editor.mixins || []).map((m) => m[key] || {}), editor[key])

	it('takes a list of rows and the host form\'s error', () => {
		const props = merged('props')
		expect(props.modelValue.type).toBe(Array)
		expect(props.modelValue.default()).toEqual([])
		expect(props.error).toEqual({ type: String, default: '' })
		expect([...(editor.mixins || []).flatMap((m) => m.emits || []), ...(editor.emits || [])]).toEqual(['update:modelValue'])
	})

	it('reads no rows from a value that is not a list', () => {
		const rows = merged('computed').rows
		expect(rows.call({ modelValue: [{ a: 1 }] })).toEqual([{ a: 1 }])
		expect(rows.call({ modelValue: null })).toEqual([])
		expect(rows.call({ modelValue: 'x' })).toEqual([])
	})

	it('hands the edited rows back to its host', async () => {
		const wrapper = mount(editor, { props: { modelValue: [] }, global: { mocks: { t: globalThis.t } } })
		wrapper.findComponent({ name: 'BookingRowsEditor' }).vm.$emit('update:modelValue', [{ x: 1 }])
		expect(wrapper.emitted('update:modelValue')).toEqual([[[{ x: 1 }]]])
	})
})
