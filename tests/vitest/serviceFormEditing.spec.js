// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The service form: an existing service filled in, the skills field, the field checks,
 * and what is saved.
 *
 * @spec openspec/changes/round3-review-points/specs/appointment-booking/spec.md
 */

import { mount } from '@vue/test-utils'
import { beforeAll, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/initial-state', () => ({ loadState: () => ({ currency: 'usd' }) }))
vi.mock('../../src/components/bookings/ServiceStepsEditor.vue', () => ({ default: { name: 'ServiceStepsEditor', render: () => null } }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({ name, render() {
		return h('div', this.$slots.default?.())
	} })
	return { NcButton: stub('NcButton'), NcSelect: stub('NcSelect'), NcTextField: stub('NcTextField') }
})

let ServiceForm

beforeAll(async () => {
	globalThis.t = (app, text) => text
	ServiceForm = (await import('../../src/views/bookings/ServiceForm.vue')).default
}, 60000)

/**
 * Mount the form.
 *
 * @param {object} service The service prop.
 * @return {object} The wrapper.
 */
function form(service) {
	return mount(ServiceForm, { props: service === undefined ? {} : { service }, global: { mocks: { t: globalThis.t } } })
}

describe('ServiceForm editing', () => {
	it('starts a new service from the defaults in the reporting currency', () => {
		const w = form()
		expect(w.vm.form).toMatchObject({ name: '', durationMinutes: 30, currency: 'USD', status: 'active', cancellationPolicy: 'free', requiredSkills: [], multiStep: [] })
		expect(w.vm.isValid).toBe(false)
	})

	it('fills in an existing service over the defaults, copying its lists', () => {
		const steps = [{ durationMinutes: 15 }]
		const skills = ['knippen']
		const w = form({ id: 's-1', name: 'Knippen', durationMinutes: 45, multiStep: steps, requiredSkills: skills })

		expect(w.vm.form).toMatchObject({ id: 's-1', name: 'Knippen', durationMinutes: 45, bufferAfterMinutes: 0, status: 'active' })
		expect(w.vm.form.multiStep).toEqual(steps)
		expect(w.vm.form.multiStep[0]).not.toBe(steps[0])
		expect(w.vm.form.requiredSkills).toEqual(skills)
		expect(w.vm.form.requiredSkills).not.toBe(skills)
		expect(w.vm.isValid).toBe(true)
	})

	it('reads lists that are not arrays as empty, and clears earlier errors', async () => {
		const w = form()
		w.vm.errors.name = 'Name is required'
		await w.setProps({ service: { name: 'Föhnen', multiStep: 'none', requiredSkills: 'all' } })

		expect(w.vm.form.multiStep).toEqual([])
		expect(w.vm.form.requiredSkills).toEqual([])
		expect(w.vm.errors).toEqual({ name: '', durationMinutes: '' })
	})

	it('reads the skills field as a trimmed list without blanks, and shows it joined', () => {
		const w = form()
		w.vm.onSkillsInput(' knippen , ,verven,')
		expect(w.vm.form.requiredSkills).toEqual(['knippen', 'verven'])
		expect(w.vm.skillsCsv).toBe('knippen, verven')
		w.vm.onSkillsInput(null)
		expect(w.vm.form.requiredSkills).toEqual([])
	})

	it('checks the name and the duration, up to a day', () => {
		const w = form()
		w.vm.validateField('name')
		expect(w.vm.errors.name).toBe('Name is required')
		w.vm.form.name = 'Knippen'
		w.vm.validateField('name')
		expect(w.vm.errors.name).toBe('')

		for (const [minutes, error] of [[0, 'Duration must be a positive whole number'], [12.5, 'Duration must be a positive whole number'], [1441, 'Duration cannot exceed 24 hours'], [1440, '']]) {
			w.vm.form.durationMinutes = minutes
			w.vm.validateField('durationMinutes')
			expect(w.vm.errors.durationMinutes).toBe(error)
		}
	})

	it('saves nothing that does not pass, and keeps the id of an edited service', () => {
		const fresh = form()
		fresh.vm.onSave()
		expect(fresh.emitted('save')).toBeUndefined()

		const w = form({ id: 's-1', name: 'Knippen', durationMinutes: 45, requiredSkills: ['knippen'], multiStep: [{ durationMinutes: '10', resourceType: 'room' }] })
		w.vm.onSave()

		const [saved] = w.emitted('save')[0]
		expect(saved.id).toBe('s-1')
		expect(saved.requiredSkills).toEqual(['knippen'])
		expect(saved.multiStep[0]).toMatchObject({ durationMinutes: 10, resourceType: 'room' })
	})

	it('offers the three statuses', () => {
		expect(form().vm.statusOptions.map((o) => o.value)).toEqual(['draft', 'active', 'archived'])
	})
})
