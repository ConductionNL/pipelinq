// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The party warnings panel (loading, acknowledging and adding a warning),
 * the win/loss widget (figures and the date range it asks for) and the
 * forecast override dialog (the reason it needs and what it saves), each run
 * against a plain component context.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcEmptyContent: stub('NcEmptyContent'), NcLoadingIcon: stub('NcLoadingIcon'), NcSelect: stub('NcSelect'), NcTextArea: stub('NcTextArea'), NcTextField: stub('NcTextField') }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnChartWidget: stub('CnChartWidget'), CnDetailCard: stub('CnDetailCard'), CnDialog: stub('CnDialog'), CnStatsBlock: stub('CnStatsBlock') }))
const indicators = vi.hoisted(() => ({ acknowledgeIndicator: vi.fn(), addIndicatorValue: vi.fn(), fetchIndicatorVocabulary: vi.fn(), fetchPartyIndicators: vi.fn(), needsAcknowledgement: () => false }))
vi.mock('../../src/services/partyIndicators.js', () => indicators)
vi.mock('../../src/services/forecastApi.js', () => ({ createOverride: vi.fn() }))

const { default: PartyIndicatorPanel } = await import('../../src/components/PartyIndicatorPanel.vue')
const { default: WinLossWidget } = await import('../../src/views/rapportage/WinLossWidget.vue')
const { default: ForecastOverrideModal } = await import('../../src/modals/ForecastOverrideModal.vue')
const { createOverride } = await import('../../src/services/forecastApi.js')

/**
 * A component context: props and extras, methods bound, data, and computed
 * values as getters.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @return {object} The context.
 */
function context(comp, props = {}) {
	const ctx = { $emit: vi.fn(), ...props }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

beforeEach(() => {
	toasts.length = 0
	for (const fn of [indicators.acknowledgeIndicator, indicators.addIndicatorValue, indicators.fetchIndicatorVocabulary, indicators.fetchPartyIndicators, createOverride]) {
		fn.mockReset()
	}
})

afterEach(() => {
	vi.useRealTimers()
})

describe('PartyIndicatorPanel', () => {
	const panel = (props = {}) => context(PartyIndicatorPanel, { partyId: 'p1', compact: false, framed: false, partyKind: '', ...props })

	it('loads the warnings of the party, and says when it cannot', async () => {
		indicators.fetchPartyIndicators.mockResolvedValueOnce([{ id: 'i1' }])
		const p = panel()
		PartyIndicatorPanel.watch.partyId.handler.call(p, 'p1')
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect([indicators.fetchPartyIndicators.mock.calls[0], p.indicators, p.loading]).toEqual([['p1'], [{ id: 'i1' }], false])
		PartyIndicatorPanel.watch.partyId.handler.call(p, '')
		expect(indicators.fetchPartyIndicators).toHaveBeenCalledTimes(1)
		indicators.fetchPartyIndicators.mockRejectedValueOnce(new Error('x'))
		await p.load()
		expect([p.indicators, p.error]).toEqual([[], 'Warnings could not be loaded.'])
	})

	it('names the empty state after the kind of party', () => {
		expect(['person', 'organization', 'contact', ''].map((partyKind) => panel({ partyKind }).noneText)).toEqual(['No warnings on this resident.', 'No warnings on this organisation.', 'No warnings on this contact person.', 'No warnings on this record.'])
	})

	it('acknowledges a warning and reloads, or says it could not', async () => {
		const p = panel()
		indicators.fetchPartyIndicators.mockResolvedValue([])
		await p.acknowledge({ id: 'i1' })
		expect([indicators.acknowledgeIndicator.mock.calls[0], indicators.fetchPartyIndicators.mock.calls.length, p.busyId]).toEqual([['i1'], 1, ''])
		indicators.acknowledgeIndicator.mockRejectedValueOnce(new Error('x'))
		await p.acknowledge({ id: 'i1' })
		expect([toasts, p.busyId]).toEqual([[['error', 'The acknowledgement could not be saved.']], ''])
	})

	it('opens a fresh form with the vocabulary and saves a new warning', async () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		const p = panel()
		indicators.fetchIndicatorVocabulary.mockResolvedValueOnce([{ id: 'v1' }])
		p.form.source = 'old'
		await p.openForm()
		expect([p.adding, p.vocabulary, p.form]).toEqual([true, [{ id: 'v1' }], { indicator: null, validFrom: '2026-05-15', validUntil: '', source: '' }])
		indicators.fetchPartyIndicators.mockResolvedValue([{ id: 'new' }])
		await p.save()
		expect([indicators.addIndicatorValue.mock.calls[0], toasts[0], p.adding, p.indicators, p.saving]).toEqual([['p1', p.form], ['success', 'Warning saved'], false, [{ id: 'new' }], false])
		indicators.fetchIndicatorVocabulary.mockRejectedValueOnce(new Error('x'))
		await p.openForm()
		indicators.addIndicatorValue.mockRejectedValueOnce(new Error('x'))
		await p.save()
		expect([p.vocabulary, p.adding, toasts.slice(1).map((e) => e[1])]).toEqual([[], true, ['The list of warnings could not be loaded.', 'The warning could not be saved.']])
	})

	it('labels severity and the period of a warning', () => {
		const p = panel()
		expect([p.severityLabel('critical'), p.severityLabel('info'), p.severityLabel('odd')]).toEqual(['Critical', 'Information', 'Warning'])
		const day = (iso) => new Date(iso).toLocaleDateString()
		expect(p.periodText({ validFrom: '2026-01-01', validUntil: '2026-12-31' })).toBe(`From ${day('2026-01-01')} until ${day('2026-12-31')}`)
		expect([p.periodText({ validFrom: 'someday' }), p.formatDay('')]).toEqual(['Since someday', ''])
	})
})

describe('WinLossWidget', () => {
	it('sums won and lost deals into the figures', () => {
		const w = context(WinLossWidget, { data: { wonCount: 3, lostCount: 1, winRate: 75, avgWonValue: 12000, avgDaysToClose: 21 } })
		expect([w.hasData, w.pieSeries]).toEqual([true, [3, 1]])
		expect(w.statsCards.map((c) => c.value)).toEqual(['75%', 3, 1, `EUR ${(12000).toLocaleString('nl-NL')}`, '21d'])
		const empty = context(WinLossWidget, { data: null })
		expect([empty.hasData, empty.pieSeries, empty.statsCards.map((c) => c.value)]).toEqual([false, [0, 0], ['0%', 0, 0, '—', '—']])
	})

	it('starts on all time and asks for the chosen date range', () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		const w = context(WinLossWidget, { data: {} })
		WinLossWidget.mounted.call(w)
		expect(w.selectedRange).toEqual({ value: 'all', label: 'All time' })
		for (const value of ['30d', '90d', '12m', 'all']) {
			w.onRangeChange({ value })
		}
		w.onRangeChange(null)
		expect(w.$emit.mock.calls.map((c) => c[1])).toEqual([{ from: '2026-04-15', to: '2026-05-15' }, { from: '2026-02-14', to: '2026-05-15' }, { from: '2025-05-15', to: '2026-05-15' }, null, null])
	})
})

describe('ForecastOverrideModal', () => {
	const modal = (props = {}) => context(ForecastOverrideModal, { periodId: 'q2', ownerId: 'ann', level: 'owner', category: 'commit', currentAmount: 1000, ...props })

	it('starts from the current amount and needs a reason of five characters', async () => {
		const m = modal({ category: 'best_case' })
		expect([m.amount, m.categoryLabel, m.valid, m.showError]).toEqual(['1000', 'Best-case', false, false])
		await m.save()
		expect([m.touched, m.showError, createOverride.mock.calls.length]).toEqual([true, true, 0])
		m.reason = 'Big '
		expect(m.valid).toBe(false)
		m.reason = 'Big deal'
		m.amount = '-1'
		expect(m.valid).toBe(false)
		expect(modal().categoryLabel).toBe('Commit')
	})

	it('saves the override and closes, or shows why not', async () => {
		const m = modal()
		Object.assign(m, { amount: '2500', reason: '  Signed LOI  ' })
		createOverride.mockResolvedValueOnce({ id: 'o1' })
		await m.save()
		expect(createOverride).toHaveBeenCalledWith({ period_id: 'q2', override_owner_id: 'ann', level: 'owner', category: 'commit', override_amount: 2500, reason: 'Signed LOI' })
		expect([m.$emit.mock.calls, m.saving]).toEqual([[['saved', { id: 'o1' }], ['close']], false])
		createOverride.mockRejectedValueOnce({ response: { data: { error: 'Period closed' } } })
		await m.save()
		expect(m.errorMessage).toBe('Period closed')
		createOverride.mockRejectedValueOnce({})
		await m.save()
		expect([m.errorMessage, m.$emit.mock.calls.length]).toEqual(['Could not save the override.', 2])
	})
})
