// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Creating a service keeps the product of its composition steps
 * (round3-review-points, review point 3). The New service form rebuilt each
 * step from duration, resource type, skill and gap, so a product picked in the
 * first step was not saved and the service page showed "-".
 *
 * @spec openspec/changes/round3-review-points/specs/appointment-booking/spec.md
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ({ currency: 'EUR' }),
}))
vi.mock('../../src/components/bookings/ServiceStepsEditor.vue', () => ({
	default: {
		name: 'ServiceStepsEditor',
		props: ['modelValue'],
		render: () => null,
	},
}))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({
		name,
		props: ['label', 'modelValue'],
		render() {
			return h('div', this.$slots.default?.())
		},
	})
	return {
		NcButton: stub('NcButton'),
		NcSelect: stub('NcSelect'),
		NcTextField: stub('NcTextField'),
	}
})

// The component reads the global t() Nextcloud injects.
globalThis.t = (_app, text) => text

const { default: ServiceForm } =
	await import('../../src/views/bookings/ServiceForm.vue')
const { serializeStep } = await import('../../src/services/serviceSteps.js')

describe('the New service form', () => {
	it('saves the product, quantity and unit of a step', () => {
		const wrapper = mount(ServiceForm, {
			global: { mocks: { t: (_app, text) => text } },
		})
		wrapper.vm.form.name = 'Implementatie'
		wrapper.vm.form.durationMinutes = 480
		wrapper.vm.form.multiStep = [
			{
				durationMinutes: 480,
				resourceType: 'staff',
				skillRequired: '',
				allowGap: false,
				productId: 'prod-1',
				quantity: 8,
				unit: 'hour',
			},
		]

		wrapper.vm.onSave()

		const [payload] = wrapper.emitted('save')[0]
		expect(payload.multiStep).toEqual([
			{
				durationMinutes: 480,
				resourceType: 'staff',
				skillRequired: '',
				allowGap: false,
				productId: 'prod-1',
				quantity: 8,
				unit: 'hour',
			},
		])
	})
})

describe('serializeStep', () => {
	it('leaves out an empty product, quantity and an unknown unit', () => {
		expect(
			serializeStep({
				durationMinutes: '15',
				productId: '',
				quantity: '',
				unit: 'weird',
			}),
		).toEqual({
			durationMinutes: 15,
			resourceType: 'staff',
			skillRequired: '',
			allowGap: false,
		})
		expect(serializeStep(null).durationMinutes).toBe(0)
	})
})
