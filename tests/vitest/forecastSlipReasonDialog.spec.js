// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The slip reason dialog on the lead detail (forecast-slip-reason 3.1): a
 * save that moves a commit or best-case deal's close date out of its quarter
 * opens SlipReasonModal and does not go through without a reason of at least
 * ten characters; a move inside the quarter, or a pipeline deal, saves
 * straight away.
 *
 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const setResult = vi.fn()

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'width'],
		emits: ['closing'],
		render() {
			return h('div', { role: 'dialog', 'aria-label': this.name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	},
	CnFormDialog: {
		name: 'CnFormDialog',
		props: ['schema', 'item', 'register'],
		emits: ['confirm', 'close'],
		methods: {
			setResult(result) {
				setResult(result)
			},
		},
		render() {
			return h('form', { 'data-testid': 'cn-form-dialog' })
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: { disabled: Boolean },
		emits: ['click'],
		render() {
			return h('button', { type: 'button', disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcTextArea: {
		name: 'NcTextArea',
		props: ['modelValue', 'label', 'helperText', 'error'],
		emits: ['update:modelValue', 'blur'],
		render() {
			return h('textarea', {
				'aria-label': this.label,
				value: this.modelValue,
				onInput: (e) => this.$emit('update:modelValue', e.target.value),
			})
		},
	},
}))

const {
	closeDay,
	isSlipReasonValid,
	needsSlipReason,
	quarterEnd,
	slipsOutOfPeriod,
} = await import('../../src/services/forecastSlipCheck.js')
const SlipReasonModal = (await import('../../src/modals/SlipReasonModal.vue')).default
const LeadEditDialog = (await import('../../src/dialogs/LeadEditDialog.vue')).default

beforeAll(() => {
	globalThis.t = (_app, text, vars) => String(text).replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
})

const GLOBAL = { mixins: [{ methods: { t: (...args) => globalThis.t(...args) } }] }

const commitLead = { id: 'lead-1', status: 'open', forecast_category: 'commit', expectedCloseDate: '2026-06-20' }

describe('forecastSlipCheck', () => {
	it('reads a date, a date-time and a Date, and refuses what is no date', () => {
		expect(closeDay('2026-06-20')).toEqual({ year: 2026, month: 6, day: 20 })
		expect(closeDay('2026-06-20T10:00:00+02:00')).toEqual({ year: 2026, month: 6, day: 20 })
		expect(closeDay(new Date(2026, 6, 15))).toEqual({ year: 2026, month: 7, day: 15 })
		expect(closeDay('2026-02-30')).toBeNull()
		expect(closeDay('')).toBeNull()
		expect(closeDay(null)).toBeNull()
		expect(closeDay(new Date('nope'))).toBeNull()
	})

	it('ends a quarter on its last day', () => {
		expect(quarterEnd({ year: 2026, month: 5, day: 10 })).toEqual({ year: 2026, month: 6, day: 30 })
		expect(quarterEnd({ year: 2026, month: 1, day: 1 })).toEqual({ year: 2026, month: 3, day: 31 })
		expect(quarterEnd({ year: 2026, month: 12, day: 31 })).toEqual({ year: 2026, month: 12, day: 31 })
	})

	it('a commit deal moved into the next quarter slips (REQ-FRC-012-01)', () => {
		expect(slipsOutOfPeriod(commitLead, { expectedCloseDate: '2026-07-15' })).toBe(true)
	})

	it('a best-case deal slips too', () => {
		expect(slipsOutOfPeriod({ ...commitLead, forecast_category: 'best_case' }, { expectedCloseDate: '2026-10-01' })).toBe(true)
	})

	it('a move inside the quarter does not slip (REQ-FRC-012-03)', () => {
		expect(slipsOutOfPeriod({ ...commitLead, expectedCloseDate: '2026-05-10' }, { expectedCloseDate: '2026-06-25' })).toBe(false)
		expect(slipsOutOfPeriod(commitLead, { expectedCloseDate: '2026-06-30' })).toBe(false)
	})

	it('a pipeline deal does not slip (REQ-FRC-012-04)', () => {
		expect(slipsOutOfPeriod({ ...commitLead, forecast_category: 'pipeline' }, { expectedCloseDate: '2026-07-15' })).toBe(false)
	})

	it('the category the form saves counts, not the stored one', () => {
		expect(slipsOutOfPeriod(commitLead, { forecast_category: 'pipeline', expectedCloseDate: '2026-07-15' })).toBe(false)
		expect(slipsOutOfPeriod({ ...commitLead, forecast_category: 'pipeline' }, { forecast_category: 'commit', expectedCloseDate: '2026-07-15' })).toBe(true)
	})

	it('a closed deal, or a missing date, does not slip', () => {
		expect(slipsOutOfPeriod({ ...commitLead, status: 'won' }, { expectedCloseDate: '2026-07-15' })).toBe(false)
		expect(slipsOutOfPeriod({ ...commitLead, expectedCloseDate: null }, { expectedCloseDate: '2026-07-15' })).toBe(false)
		expect(slipsOutOfPeriod(commitLead, { expectedCloseDate: '' })).toBe(false)
		expect(slipsOutOfPeriod(null, null)).toBe(false)
	})

	it('a reason needs ten characters, and a valid one ends the need', () => {
		expect(isSlipReasonValid('too short')).toBe(false)
		expect(isSlipReasonValid('  Board meets in July  ')).toBe(true)
		expect(needsSlipReason(commitLead, { expectedCloseDate: '2026-07-15' })).toBe(true)
		expect(needsSlipReason(commitLead, { expectedCloseDate: '2026-07-15', slip_reason: 'Customer moved the board decision to July' })).toBe(false)
	})
})

describe('SlipReasonModal', () => {
	it('keeps Save disabled until the reason has ten characters, then hands it back trimmed', async () => {
		const wrapper = mount(SlipReasonModal, { props: { fromDate: '2026-06-20', toDate: '2026-07-15' }, global: GLOBAL })
		expect(wrapper.text()).toContain('from 2026-06-20 to 2026-07-15')
		const save = wrapper.find('[data-testid="slip-reason-save"]')
		expect(save.attributes('disabled')).toBeDefined()

		await wrapper.find('textarea').setValue('short')
		expect(save.attributes('disabled')).toBeDefined()
		wrapper.vm.save()
		expect(wrapper.emitted('confirm')).toBeUndefined()

		await wrapper.find('textarea').setValue('  Customer moved the board decision to July  ')
		expect(save.attributes('disabled')).toBeUndefined()
		await save.trigger('click')
		expect(wrapper.emitted('confirm')[0]).toEqual(['Customer moved the board decision to July'])
	})

	it('Cancel closes without a reason', async () => {
		const wrapper = mount(SlipReasonModal, { global: GLOBAL })
		await wrapper.find('[data-testid="slip-reason-cancel"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})
})

describe('LeadEditDialog', () => {
	/**
	 * @param {object} overrides Props to change.
	 * @return {object} The mounted dialog and its spies.
	 */
	function mountDialog(overrides = {}) {
		setResult.mockClear()
		const confirm = vi.fn().mockResolvedValue({ success: true, data: {} })
		const close = vi.fn()
		const wrapper = mount(LeadEditDialog, {
			props: { show: true, item: commitLead, schema: { title: 'Lead' }, confirm, close, ...overrides },
			global: GLOBAL,
		})
		return { wrapper, confirm, close }
	}

	it('renders nothing while the page keeps it closed', () => {
		const { wrapper } = mountDialog({ show: false })
		expect(wrapper.find('[data-testid="cn-form-dialog"]').exists()).toBe(false)
	})

	it('saves a move inside the quarter straight through the page', async () => {
		const { wrapper, confirm } = mountDialog()
		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('confirm', { expectedCloseDate: '2026-06-25' })
		await flushPromises()
		expect(confirm).toHaveBeenCalledWith({ expectedCloseDate: '2026-06-25' })
		expect(setResult).toHaveBeenCalledWith({ success: true, data: {} })
		expect(wrapper.findComponent(SlipReasonModal).exists()).toBe(false)
	})

	it('stops a slip for a reason, then saves it with the reason', async () => {
		const { wrapper, confirm } = mountDialog()
		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('confirm', { title: 'Solar', expectedCloseDate: '2026-07-15' })
		await flushPromises()
		expect(confirm).not.toHaveBeenCalled()
		const modal = wrapper.findComponent(SlipReasonModal)
		expect(modal.exists()).toBe(true)
		expect(modal.props('fromDate')).toBe('2026-06-20')
		expect(modal.props('toDate')).toBe('2026-07-15')

		modal.vm.$emit('confirm', 'Customer moved the board decision to July')
		await flushPromises()
		expect(confirm).toHaveBeenCalledWith({
			title: 'Solar',
			expectedCloseDate: '2026-07-15',
			slip_reason: 'Customer moved the board decision to July',
		})
		expect(setResult).toHaveBeenCalledWith({ success: true, data: {} })
		expect(wrapper.findComponent(SlipReasonModal).exists()).toBe(false)
	})

	it('a cancelled reason saves nothing and tells the form why', async () => {
		const { wrapper, confirm } = mountDialog()
		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('confirm', { expectedCloseDate: '2026-07-15' })
		await flushPromises()
		wrapper.findComponent(SlipReasonModal).vm.$emit('close')
		await flushPromises()
		expect(confirm).not.toHaveBeenCalled()
		expect(setResult).toHaveBeenCalledWith({ error: 'Not saved: a deal that slips out of its forecast period needs a slip reason.' })
	})

	it('a refused save hands the server message to the form', async () => {
		const { wrapper, confirm } = mountDialog()
		confirm.mockRejectedValueOnce({ response: { data: { message: 'forecast.error.slip_reason_required' } } })
		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('confirm', { expectedCloseDate: '2026-06-25' })
		await flushPromises()
		expect(setResult).toHaveBeenCalledWith({ error: 'forecast.error.slip_reason_required' })
	})

	it('without the page confirm it refuses instead of pretending to save', async () => {
		const { wrapper } = mountDialog({ confirm: null })
		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('confirm', { expectedCloseDate: '2026-06-25' })
		await flushPromises()
		expect(setResult).toHaveBeenCalledWith({ error: 'The lead could not be saved.' })
	})

	it('Close goes through the page', async () => {
		const { wrapper, close } = mountDialog()
		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('close')
		expect(close).toHaveBeenCalledTimes(1)
	})
})

describe('wiring', () => {
	it('LeadDetail mounts LeadEditDialog in its form-dialog slot, and the registry knows it', () => {
		const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
		const page = manifest.pages.find((p) => p.id === 'LeadDetail')
		expect(page.slots['form-dialog']).toBe('LeadEditDialog')
		const registrySource = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')
		expect(registrySource).toMatch(/LeadEditDialog: \{\s*kind: 'modal',\s*component: LeadEditDialog,/)
	})
})
