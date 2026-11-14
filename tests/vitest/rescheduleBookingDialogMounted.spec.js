// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The reschedule dialog, mounted: the picked time drives the New slot and
 * the Reschedule button, Reschedule confirms an ISO time, and Cancel or
 * closing the dialog cancels.
 *
 * @spec openspec/changes/archive/2026-06-14-appointment-booking-11-admin-ui/tasks.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['variant', 'disabled'], emits: ['click'], render() { return h('button', { 'data-variant': this.variant || 'default', disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', props: ['eyebrow'], emits: ['closing'], render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
	CnNoteCard: { render: () => null },
}))

const { default: Dialog } = await import('../../src/dialogs/RescheduleBookingDialog.vue')

const start = '2026-05-04T09:00:00Z'
const end = '2026-05-04T09:30:00Z'
const mountDialog = (props = {}) => mount(Dialog, { props: { currentStartAt: start, currentEndAt: end, subject: 'Haircut', ...props }, global: { mocks: { t: globalThis.t } } })

describe('RescheduleBookingDialog mounted', () => {
	it('names the booking and proposes a week later', () => {
		const w = mountDialog()
		expect(w.findComponent({ name: 'CnDialog' }).props('eyebrow')).toBe('Booking · Haircut')
		expect(w.find('#new-start-at').element.value).toBe(w.vm.formatForInput('2026-05-11T09:00:00Z'))
		expect(w.find('[data-variant="primary"]').attributes('disabled')).toBeUndefined()
	})

	it('disables Reschedule for the current time and for no time', async () => {
		const w = mountDialog()
		await w.find('#new-start-at').setValue(w.vm.formatForInput(start))
		expect(w.find('[data-variant="primary"]').attributes('disabled')).toBeDefined()
		await w.find('#new-start-at').setValue('')
		expect(w.find('[data-variant="primary"]').attributes('disabled')).toBeDefined()
		expect(w.find('[data-testid="reschedule-slots"]').findAll('dd')[1].text()).toBe('-')
	})

	it('confirms the picked time as an ISO timestamp', async () => {
		const w = mountDialog()
		await w.find('#new-start-at').setValue('2026-06-01T14:15')
		await w.find('[data-variant="primary"]').trigger('click')
		expect(w.emitted('confirm')).toEqual([[new Date('2026-06-01T14:15').toISOString()]])
		expect(w.find('[data-testid="reschedule-slots"]').findAll('dd')[1].text()).toMatch(/, same length$/)
	})

	it('cancels from the Cancel button and from closing the dialog', async () => {
		const w = mountDialog()
		await w.find('[data-variant="default"]').trigger('click')
		w.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(w.emitted('cancel')).toHaveLength(2)
		expect(w.emitted('confirm')).toBeUndefined()
	})
})
