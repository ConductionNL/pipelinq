// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A converted deal says at which rate, from where and of which day, and an
 * administrator can fix a rate with a reason (pipeline-live-exchange-rates).
 * Pins the rate helpers, the Forecast settings section and the LeadDetail
 * currency widget.
 *
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-a-stale-rate-is-shown-as-stale-req-dxr-002
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-an-administrator-can-override-a-rate-on-purpose-req-dxr-003
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole),
}))
vi.mock('../../src/services/reportingCurrency.js', () => ({ reportingCurrency: () => 'EUR' }))

function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['name', 'description', 'type', 'disabled'],
		emits: ['click'],
		render() {
			return h(tag, { 'data-stub': name, onClick: () => this.$emit('click') }, [this.$slots.default?.()])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSettingsSection: stub('NcSettingsSection', 'section'),
}))

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const mountOptions = { global: { mocks: { t: globalThis.t } } }

const {
	overridesFromRows,
	rateLine,
	rateSourceLabel,
	rowsFromOverrides,
} = await import('../../src/services/leadCurrency.js')
const { default: ForecastSettings } = await import('../../src/components/admin/ForecastSettings.vue')
const { default: LeadExchangeRate } = await import('../../src/components/leads/LeadExchangeRate.vue')

describe('rate helpers', () => {
	it('names where a rate came from', () => {
		expect(rateSourceLabel({ source: 'shillinq', origin: 'ecb' })).toBe('ECB via Shillinq')
		expect(rateSourceLabel({ source: 'ecb', origin: 'ecb' })).toBe('ECB daily rate')
		expect(rateSourceLabel({ source: 'manual' })).toBe('Typed in Forecast settings')
		expect(rateSourceLabel({ source: 'override' })).toBe('Set by an administrator')
		expect(rateSourceLabel({ source: 'none' })).toBe('No rate, counted at face value')
	})

	it('writes the rate line with the pair and the date', () => {
		const line = rateLine({ currency: 'USD', reportingCurrency: 'EUR', rate: 0.9132, rateDate: '2026-10-06', source: 'shillinq' })
		expect(line).toMatch(/^1 USD = 0[.,]9132 EUR, rate of /)
		expect(rateLine({ currency: 'USD', reportingCurrency: 'EUR', rate: 0.9, rateDate: null, source: 'manual' })).toMatch(/date unknown$/)
		expect(rateLine({ currency: 'USD', rate: 1, source: 'none' })).toBe('')
	})

	it('turns fixed-rate rows into overrides and needs a reason', () => {
		expect(overridesFromRows([{ currency: 'usd', rate: '0.92', reason: ' contract ' }, { currency: '', rate: '', reason: '' }]))
			.toEqual({ overrides: { USD: { rate: 0.92, reason: 'contract' } }, error: '' })
		expect(overridesFromRows([{ currency: 'USD', rate: '0.92', reason: '' }]).error).toBe('reason')
		expect(overridesFromRows([{ currency: 'DOLLAR', rate: '1', reason: 'x' }]).error).toBe('code')
		expect(overridesFromRows([{ currency: 'USD', rate: '-1', reason: 'x' }]).error).toBe('rate')
		expect(rowsFromOverrides({ USD: { rate: 0.92, reason: 'contract', date: '2026-10-01' } }))
			.toEqual([{ currency: 'USD', rate: '0.92', reason: 'contract' }])
	})
})

describe('Forecast settings rates', () => {
	const settings = {
		exchange_rates: { USD: 0.9 },
		rate_overrides: { GBP: { rate: 1.2, reason: 'contract rate 2026', date: '2026-10-01' } },
		rate_sources: { USD: { currency: 'USD', reportingCurrency: 'EUR', rate: 0.9132, source: 'shillinq', origin: 'ecb', rateDate: '2026-10-06', stale: true } },
		max_rate_age_days: 7,
	}

	it('shows which rate a currency converts at now', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: settings })
		const wrapper = mount(ForecastSettings, mountOptions)
		await flushPromises()

		const source = wrapper.get('[data-testid="forecast-rate-source"]').text()
		expect(source).toContain('Used now: ECB via Shillinq')
		expect(source).toContain('out of date')
		expect(wrapper.get('#forecast-override-reason-0').element.value).toBe('contract rate 2026')
	})

	it('saves a fixed rate with its reason and refuses one without', async () => {
		axiosMock.get.mockResolvedValueOnce({ data: settings })
		axiosMock.put.mockResolvedValueOnce({ data: { status: 'saved' } })
		const wrapper = mount(ForecastSettings, mountOptions)
		await flushPromises()

		await wrapper.get('#forecast-override-reason-0').setValue('')
		await wrapper.vm.save()
		expect(axiosMock.put).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Give a reason for every fixed rate.')

		await wrapper.get('#forecast-override-reason-0').setValue('contract rate 2027')
		await wrapper.vm.save()
		const body = axiosMock.put.mock.calls[0][1]
		expect(body.rate_overrides).toEqual({ GBP: { rate: 1.2, reason: 'contract rate 2027' } })
		expect(body.max_rate_age_days).toBe(7)
		expect(body).not.toHaveProperty('rate_sources')
	})
})

describe('LeadDetail currency widget', () => {
	it('shows the converted value, the rate line and the stale flag', async () => {
		axiosMock.get.mockResolvedValueOnce({
			data: { amount: 120000, currency: 'USD', reportingCurrency: 'EUR', rate: 0.9132, source: 'manual', origin: null, rateDate: '2026-10-06', stale: true, maxRateAgeDays: 7 },
		})
		const wrapper = mount(LeadExchangeRate, { ...mountOptions, props: { value: 131400, currency: 'usd' } })
		await flushPromises()

		expect(axiosMock.get).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/exchange-rates/convert', { params: { currency: 'USD', amount: 131400 } })
		expect(wrapper.text()).toContain('Typed in Forecast settings')
		expect(wrapper.text()).toMatch(/1 USD = 0[.,]9132 EUR, rate of/)
		expect(wrapper.get('[data-testid="lead-exchange-rate-stale"]').text()).toBe('Older than 7 days')
	})

	it('converts nothing for a deal in the reporting currency', async () => {
		axiosMock.get.mockClear()
		const wrapper = mount(LeadExchangeRate, { ...mountOptions, props: { value: 1000, currency: 'EUR' } })
		await flushPromises()

		expect(axiosMock.get).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('This deal is in EUR, so nothing is converted.')
	})
})
