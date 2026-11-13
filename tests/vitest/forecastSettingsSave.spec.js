// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Loading and saving the forecast configuration on the admin page: the stored
 * values merge over the defaults and become rate rows, a failed load says so,
 * a save sends the form without the rate sources it only shows, refuses rate
 * rows that do not make a rate table with the matching message, and reports a
 * failed request.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-004-05
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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

const { default: ForecastSettings } = await import('../../src/components/admin/ForecastSettings.vue')

const URL = '/index.php/apps/pipelinq/api/settings/forecast'

/**
 * Mount the section once the given configuration has loaded.
 *
 * @param {object} stored What the server returns.
 * @return {Promise<object>} The wrapper.
 */
async function loaded(stored) {
	axiosMock.get.mockResolvedValueOnce({ data: stored })
	const wrapper = mount(ForecastSettings, { global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axiosMock.get.mockReset()
	axiosMock.put.mockReset()
})

describe('loading', () => {
	it('merges the stored values over the defaults and turns the rates into sorted rows', async () => {
		const wrapper = await loaded({
			commit_threshold: 1000,
			exchange_rates: { USD: 0.9, GBP: 1.15 },
			rate_overrides: { CHF: { rate: 1.05, reason: 'contract' } },
		})

		expect(axiosMock.get).toHaveBeenCalledWith(URL)
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.form.commit_threshold).toBe(1000)
		expect(wrapper.vm.form.reporting_currency).toBe('EUR')
		expect(wrapper.vm.form.generation_hour).toBe(6)
		expect(wrapper.vm.rateRows).toEqual([{ currency: 'GBP', rate: '1.15' }, { currency: 'USD', rate: '0.9' }])
		expect(wrapper.vm.overrideRows).toEqual([{ currency: 'CHF', rate: '1.05', reason: 'contract' }])
		expect(wrapper.vm.message).toBe('')
	})

	it('says it could not load, and stops loading, when the request fails', async () => {
		axiosMock.get.mockRejectedValueOnce(new Error('500'))
		const wrapper = mount(ForecastSettings, { global: { mocks: { t: globalThis.t } } })
		await flushPromises()

		expect(wrapper.vm.loading).toBe(false)
		expect([wrapper.vm.message, wrapper.vm.messageType]).toEqual(['Could not load the forecast configuration.', 'error'])
		expect(wrapper.vm.form.commit_threshold).toBe(50000)
	})
})

describe('the rate source line', () => {
	it('is empty for a currency the server has not resolved, and matches the code case-insensitively', async () => {
		const wrapper = await loaded({ rate_sources: { USD: { source: 'same', currency: 'USD' } } })

		expect(wrapper.vm.sourceLine('GBP')).toBe('')
		expect(wrapper.vm.sourceLine('')).toBe('')
		expect(wrapper.vm.sourceLine(' usd ')).toMatch(/^Used now: /)
	})
})

describe('saving', () => {
	it('sends the form with the edited rates and fixed rates, without the rate sources, and confirms', async () => {
		axiosMock.put.mockResolvedValueOnce({ data: {} })
		const wrapper = await loaded({ commit_threshold: 1000, rate_sources: { USD: { source: 'same' } } })
		wrapper.vm.rateRows = [{ currency: 'usd', rate: '0.9' }, { currency: '', rate: '' }]
		wrapper.vm.overrideRows = [{ currency: 'chf', rate: '1.05', reason: ' contract ' }]

		await wrapper.vm.save()

		expect(axiosMock.put).toHaveBeenCalledTimes(1)
		const [url, body] = axiosMock.put.mock.calls[0]
		expect(url).toBe(URL)
		expect(body).not.toHaveProperty('rate_sources')
		expect(body.commit_threshold).toBe(1000)
		expect(body.exchange_rates).toEqual({ USD: 0.9 })
		expect(body.rate_overrides).toEqual({ CHF: { rate: 1.05, reason: 'contract' } })
		expect(wrapper.vm.form.rate_sources).toEqual({ USD: { source: 'same' } })
		expect([wrapper.vm.message, wrapper.vm.messageType, wrapper.vm.saving]).toEqual(['Forecast configuration saved.', 'success', false])
	})

	it('reports a failed save and stops saving', async () => {
		axiosMock.put.mockRejectedValueOnce(new Error('403'))
		const wrapper = await loaded({})

		await wrapper.vm.save()

		expect([wrapper.vm.message, wrapper.vm.messageType, wrapper.vm.saving]).toEqual(['Could not save the forecast configuration.', 'error', false])
	})

	it.each([
		['an exchange rate with a bad code', [{ currency: 'EURO', rate: '1' }], [], 'Use a three-letter currency code for every rate.'],
		['an exchange rate that is not above zero', [{ currency: 'USD', rate: '0' }], [], 'Every rate must be a number above zero.'],
		['a fixed rate with a bad code', [], [{ currency: 'X', rate: '1', reason: 'r' }], 'Use a three-letter currency code for every rate.'],
		['a fixed rate that is not a number', [], [{ currency: 'USD', rate: 'abc', reason: 'r' }], 'Every rate must be a number above zero.'],
		['a fixed rate without a reason', [], [{ currency: 'USD', rate: '1.1', reason: ' ' }], 'Give a reason for every fixed rate.'],
		['a missing reason, even next to a bad exchange rate', [{ currency: 'US', rate: '1' }], [{ currency: 'USD', rate: '1.1', reason: '' }], 'Give a reason for every fixed rate.'],
	])('refuses %s without sending', async (_case, rates, fixed, message) => {
		const wrapper = await loaded({})
		wrapper.vm.rateRows = rates
		wrapper.vm.overrideRows = fixed

		await wrapper.vm.save()

		expect(axiosMock.put).not.toHaveBeenCalled()
		expect([wrapper.vm.message, wrapper.vm.messageType, wrapper.vm.saving]).toEqual([message, 'error', false])
	})
})
