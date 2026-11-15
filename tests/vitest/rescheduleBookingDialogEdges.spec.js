// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The reschedule dialog at its edges: a booking without a known start shows
 * a dash and proposes nothing, a booking without a subject names only the
 * booking, a slot whose end is not after its start is moved without an end,
 * and confirming without a usable time says why instead of emitting.
 *
 * @spec openspec/changes/archive/2026-06-14-appointment-booking-11-admin-ui/tasks.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})
vi.mock('@nextcloud/l10n', () => ({ translate: (...args) => globalThis.t(...args) }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['variant', 'disabled'], emits: ['click'], render() { return h('button', { 'data-variant': this.variant || 'default', disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', props: ['eyebrow'], emits: ['closing'], render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
	CnNoteCard: { render: () => null },
}))

const { default: Dialog } = await import('../../src/dialogs/RescheduleBookingDialog.vue')

const mountDialog = (props = {}) => mount(Dialog, { props, global: { mocks: { t: globalThis.t } } })
const slots = (w) => w.find('[data-testid="reschedule-slots"]').findAll('dd').map((dd) => dd.text())

describe('RescheduleBookingDialog edges', () => {
	it('shows a dash and proposes no time for a booking without a start', () => {
		const w = mountDialog()
		expect(w.findComponent({ name: 'CnDialog' }).props('eyebrow')).toBe('Booking')
		expect(w.find('#new-start-at').element.value).toBe('')
		expect(slots(w)).toEqual(['-', '-'])
		expect(w.find('[data-variant="primary"]').attributes('disabled')).toBeDefined()
		expect(w.find('.error-text').exists()).toBe(false)
	})

	it('proposes nothing for an unreadable start', () => {
		const w = mountDialog({ currentStartAt: 'not a date' })
		expect(w.find('#new-start-at').element.value).toBe('')
		expect(w.vm.formatForInput('not a date')).toBe('')
		expect(slots(w)[0]).toBe('-')
	})

	it('moves a slot whose end is not after its start without an end time', async () => {
		const w = mountDialog({ currentStartAt: '2026-05-04T09:00:00Z', currentEndAt: '2026-05-04T08:00:00Z' })
		await w.find('#new-start-at').setValue('2026-06-01T14:15')
		const newSlot = slots(w)[1]
		expect(newSlot).toMatch(/, 14:15, same length$/)
		expect(newSlot).not.toContain(' to ')
	})

	it('keeps the slot length when the end is after the start', async () => {
		const w = mountDialog({ currentStartAt: '2026-05-04T09:00:00Z', currentEndAt: '2026-05-04T09:45:00Z' })
		await w.find('#new-start-at').setValue('2026-06-01T14:15')
		expect(slots(w)[1]).toMatch(/, 14:15 to 15:00, same length$/)
	})

	it('asks for a start time instead of confirming an empty one', async () => {
		const w = mountDialog({ currentStartAt: '2026-05-04T09:00:00Z' })
		await w.find('#new-start-at').setValue('')
		w.vm.onConfirm()
		await w.vm.$nextTick()
		expect(w.find('.error-text').text()).toBe('Pick a new start time.')
		expect(w.emitted('confirm')).toBeUndefined()
	})

	it('refuses a time that does not parse', async () => {
		const w = mountDialog({ currentStartAt: '2026-05-04T09:00:00Z' })
		w.vm.newStartAt = 'tomorrow-ish'
		w.vm.onConfirm()
		await w.vm.$nextTick()
		expect(w.find('.error-text').text()).toBe('Invalid date / time.')
		expect(slots(w)[1]).toBe('-')
		expect(w.emitted('confirm')).toBeUndefined()
	})
})
