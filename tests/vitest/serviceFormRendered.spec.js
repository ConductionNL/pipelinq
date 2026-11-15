// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The rendered service form: what each numeric and text field writes back
 * (blank resets to its default, the currency upper-cases), the name and
 * duration checks as they are typed, and the save and cancel buttons.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

/**
 * A text field stub that renders an input carrying its id and value.
 */
const TextField = defineComponent({
	name: 'NcTextField',
	props: ['id', 'modelValue', 'error', 'helperText', 'disabled'],
	emits: ['update:modelValue'],
	setup(props, { emit }) {
		return () => h('input', {
			id: props.id,
			value: props.modelValue,
			disabled: props.disabled,
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
vi.mock('../../src/components/bookings/ServiceStepsEditor.vue', () => ({ default: { name: 'ServiceStepsEditor', render: () => null } }))
vi.mock('../../src/services/reportingCurrency.js', () => ({ reportingCurrency: () => 'EUR' }))

globalThis.t = (_app, text) => text

const { default: ServiceForm } = await import('../../src/views/bookings/ServiceForm.vue')

/**
 * Mount the form.
 *
 * @param {object} service The service prop.
 * @return {object} The wrapper.
 */
function render(service = {}) {
	return mount(ServiceForm, {
		props: { service },
		global: { mocks: { t: (_app, text) => text } },
	})
}

describe('ServiceForm rendered', () => {
	it('validates the name and the duration as they are typed', async () => {
		const wrapper = render({ name: 'Intake', durationMinutes: 30 })
		const save = () => wrapper.find('[data-testid="service-form-save"]')
		expect(save().element.disabled).toBe(false)

		await wrapper.find('#service-name').setValue(' ')
		expect(wrapper.vm.errors.name).toBe('Name is required')
		expect(wrapper.find('#service-name').attributes('data-error')).toBe('yes')
		expect(save().element.disabled).toBe(true)
		await wrapper.find('#service-name').setValue('Intake B')

		await wrapper.find('#service-duration').setValue('')
		expect(wrapper.vm.form.durationMinutes).toBeNull()
		expect(wrapper.vm.errors.durationMinutes).toBe('Duration must be a positive whole number')
		await wrapper.find('#service-duration').setValue('1500')
		expect(wrapper.vm.errors.durationMinutes).toBe('Duration cannot exceed 24 hours')
		await wrapper.find('#service-duration').setValue('45')
		expect([wrapper.vm.form.durationMinutes, wrapper.vm.errors.durationMinutes]).toEqual([45, ''])
		expect(save().element.disabled).toBe(false)
	})

	it('reads numbers from the money and buffer fields, and a blank one as its default', async () => {
		const wrapper = render({ name: 'Intake', durationMinutes: 30, requiresDeposit: true })

		for (const [id, value] of [['buffer-before', '5'], ['buffer-after', '10'], ['price', '12.5'], ['deposit-amount', '3'], ['no-show-fee', '7'], ['cancellation-hours', '48']]) {
			await wrapper.find(`#service-${id}`).setValue(value)
		}
		expect(wrapper.vm.form).toMatchObject({
			bufferBeforeMinutes: 5, bufferAfterMinutes: 10, price: 12.5, depositAmount: 3, noShowFee: 7, cancellationHoursBefore: 48,
		})

		for (const id of ['buffer-before', 'buffer-after', 'price', 'deposit-amount', 'no-show-fee']) {
			await wrapper.find(`#service-${id}`).setValue('')
		}
		expect(wrapper.vm.form).toMatchObject({ bufferBeforeMinutes: 0, bufferAfterMinutes: 0, price: 0, depositAmount: 0, noShowFee: 0 })
	})

	it('upper-cases the currency and splits the required skills', async () => {
		const wrapper = render({ name: 'Intake', durationMinutes: 30 })
		expect(wrapper.find('#service-currency').element.value).toBe('EUR')

		await wrapper.find('#service-currency').setValue('usd')
		await wrapper.find('#service-skills').setValue('Dutch, , first aid ')

		expect(wrapper.vm.form.currency).toBe('USD')
		expect(wrapper.vm.form.requiredSkills).toEqual(['Dutch', 'first aid'])
	})

	it('saves the edited service with its id from the button, and cancels', async () => {
		const wrapper = render({ id: 's1', name: 'Intake', durationMinutes: 30 })
		await wrapper.find('#service-name').setValue('Intake B')

		await wrapper.find('[data-testid="service-form-save"]').trigger('click')
		await wrapper.find('[data-testid="service-form-cancel"]').trigger('click')

		expect(wrapper.emitted('save')[0][0]).toMatchObject({ id: 's1', name: 'Intake B', durationMinutes: 30 })
		expect(wrapper.emitted('cancel')).toHaveLength(1)
	})
})
