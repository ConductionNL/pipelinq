// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rendered resource form: what each field writes back, and what the save
 * and cancel buttons do.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

/**
 * A text field stub that renders an input carrying its id and value.
 */
const TextField = defineComponent({
	name: 'NcTextField',
	props: ['id', 'modelValue', 'error', 'helperText'],
	emits: ['update:modelValue'],
	setup(props, { emit }) {
		return () => h('input', {
			id: props.id,
			value: props.modelValue,
			'data-error': props.error ? 'yes' : 'no',
			onInput: (event) => emit('update:modelValue', event.target.value),
		})
	},
})

vi.mock('@nextcloud/vue', () => ({
	NcTextField: TextField,
	NcSelect: defineComponent({ name: 'NcSelect', props: ['modelValue'], setup: () => () => h('div') }),
	NcButton: defineComponent({
		name: 'NcButton',
		props: ['disabled'],
		emits: ['click'],
		setup(props, { emit, slots, attrs }) {
			return () => h('button', { disabled: props.disabled, 'data-testid': attrs['data-testid'], onClick: () => emit('click') }, slots.default?.())
		},
	}),
}))
vi.mock('../../src/components/bookings/ResourceHoursEditor.vue', () => ({ default: { name: 'ResourceHoursEditor', render: () => null } }))
vi.mock('../../src/components/bookings/ResourceVacationsEditor.vue', () => ({ default: { name: 'ResourceVacationsEditor', render: () => null } }))

globalThis.t = (_app, text) => text

const { default: ResourceForm } = await import('../../src/views/bookings/ResourceForm.vue')

/**
 * Mount the form.
 *
 * @param {object} resource The resource prop.
 * @return {object} The wrapper.
 */
function render(resource = {}) {
	return mount(ResourceForm, {
		props: { resource },
		global: { mocks: { t: (_app, text) => text } },
	})
}

describe('ResourceForm rendered', () => {
	it('shows the resource in its fields', () => {
		const wrapper = render({ id: 'r1', name: 'Room A', type: 'room', skills: ['wifi', 'beamer'], maxConcurrent: 4, userId: 'ann', calendarSyncId: 'cal-1', bookable: false })

		expect(wrapper.find('#resource-name').element.value).toBe('Room A')
		expect(wrapper.find('#resource-max-concurrent').element.value).toBe('4')
		expect(wrapper.find('#resource-skills').element.value).toBe('wifi, beamer')
		expect(wrapper.find('#resource-user-id').element.value).toBe('ann')
		expect(wrapper.find('#resource-calendar-sync').element.value).toBe('cal-1')
		expect(wrapper.find('#resource-bookable').element.checked).toBe(false)
	})

	it('validates the name as it is typed and disables save while invalid', async () => {
		const wrapper = render({ name: 'Ann' })
		const save = () => wrapper.find('[data-testid="resource-form-save"]')
		expect(save().element.disabled).toBe(false)

		await wrapper.find('#resource-name').setValue('  ')
		expect(wrapper.vm.errors.name).toBe('Name is required')
		expect(wrapper.find('#resource-name').attributes('data-error')).toBe('yes')
		expect(save().element.disabled).toBe(true)

		await wrapper.find('#resource-name').setValue('Bob')
		expect(wrapper.vm.form.name).toBe('Bob')
		expect(wrapper.vm.errors.name).toBe('')
		expect(save().element.disabled).toBe(false)
	})

	it('keeps max concurrent at one or more', async () => {
		const wrapper = render({ name: 'Ann' })
		const field = wrapper.find('#resource-max-concurrent')

		await field.setValue('3')
		expect(wrapper.vm.form.maxConcurrent).toBe(3)
		await field.setValue('0')
		expect(wrapper.vm.form.maxConcurrent).toBe(1)
		await field.setValue('')
		expect(wrapper.vm.form.maxConcurrent).toBe(1)
	})

	it('writes skills, user, calendar link and bookable back to the form', async () => {
		const wrapper = render({ name: 'Ann' })

		await wrapper.find('#resource-skills').setValue('Dutch, first aid')
		await wrapper.find('#resource-user-id').setValue('ann')
		await wrapper.find('#resource-calendar-sync').setValue('cal-9')
		await wrapper.find('#resource-bookable').setValue(false)

		expect(wrapper.vm.form).toMatchObject({ skills: ['Dutch', 'first aid'], userId: 'ann', calendarSyncId: 'cal-9', bookable: false })
	})

	it('saves the edited resource from the button, and cancels', async () => {
		const wrapper = render({ id: 'r1', name: 'Ann' })
		await wrapper.find('#resource-name').setValue('Ann B')

		await wrapper.find('[data-testid="resource-form-save"]').trigger('click')
		await wrapper.find('[data-testid="resource-form-cancel"]').trigger('click')

		expect(wrapper.emitted('save')[0][0]).toMatchObject({ id: 'r1', name: 'Ann B', type: 'staff', maxConcurrent: 1, bookable: true })
		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})
})
