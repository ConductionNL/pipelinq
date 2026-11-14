// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The forecast configuration form as an administrator uses it: what is typed
 * into each field is what the save sends, numbers as numbers, and the rate and
 * fixed-rate rows are added and removed with their buttons.
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
			return h(tag, { 'data-stub': name, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()])
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

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: ForecastSettings } = await import('../../src/components/admin/ForecastSettings.vue')

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

/**
 * Press the button with this text, the nth one when several carry it.
 *
 * @param {object} wrapper The mounted section.
 * @param {string} text The button text.
 * @param {number} nth Which match.
 * @return {Promise<void>}
 */
async function press(wrapper, text, nth = 0) {
	const buttons = wrapper.findAll('button').filter((button) => button.text() === text)
	await buttons[nth].trigger('click')
	await flushPromises()
}

beforeEach(() => {
	axiosMock.get.mockReset()
	axiosMock.put.mockReset()
	axiosMock.put.mockResolvedValue({ data: {} })
})

describe('the forecast configuration form', () => {
	it('saves what is typed into each field, numbers as numbers', async () => {
		const wrapper = await loaded({})
		const typed = {
			'#forecast-commit-threshold': '75000',
			'#forecast-pipeline-target': '250000',
			'#forecast-currency': 'USD',
			'#forecast-timezone': 'Europe/Amsterdam',
			'#forecast-day': '3',
			'#forecast-hour': '7',
			'#forecast-green': '0.95',
			'#forecast-amber': '0.8',
			'#forecast-at-risk-percent': '85',
			'#forecast-at-risk-days': '14',
			'#forecast-manager-group': 'sales-managers',
			'#forecast-team-groups': 'team-north,team-south',
			'#forecast-max-rate-age': '3',
		}
		for (const [selector, value] of Object.entries(typed)) {
			await wrapper.get(selector).setValue(value)
		}

		await press(wrapper, 'Save')

		const [, body] = axiosMock.put.mock.calls[0]
		expect(body).toMatchObject({
			commit_threshold: 75000,
			pipeline_target: 250000,
			reporting_currency: 'USD',
			generation_timezone: 'Europe/Amsterdam',
			generation_day: 3,
			generation_hour: 7,
			// Number inputs: Vue's v-model hands their value over as a number.
			accuracy_green: 0.95,
			accuracy_amber: 0.8,
			at_risk_percent: 85,
			at_risk_days: 14,
			manager_group: 'sales-managers',
			team_groups: 'team-north,team-south',
			max_rate_age_days: 3,
		})
	})

	it('adds a rate row with its button, takes what is typed, and removes the row it belongs to', async () => {
		const wrapper = await loaded({ exchange_rates: { GBP: 1.15 } })

		await press(wrapper, 'Add a rate')
		await wrapper.get('#forecast-rate-currency-1').setValue('usd')
		await wrapper.get('#forecast-rate-value-1').setValue('0.9')
		expect(wrapper.vm.rateRows).toEqual([{ currency: 'GBP', rate: '1.15' }, { currency: 'usd', rate: 0.9 }])

		// The first Remove belongs to the GBP row.
		await press(wrapper, 'Remove', 0)
		expect(wrapper.vm.rateRows).toEqual([{ currency: 'usd', rate: 0.9 }])

		await press(wrapper, 'Save')
		expect(axiosMock.put.mock.calls[0][1].exchange_rates).toEqual({ USD: 0.9 })
	})

	it('adds a fixed rate with its reason, and removes it again', async () => {
		const wrapper = await loaded({})

		await press(wrapper, 'Add a fixed rate')
		await wrapper.get('#forecast-override-currency-0').setValue('chf')
		await wrapper.get('#forecast-override-rate-0').setValue('1.05')
		await wrapper.get('#forecast-override-reason-0').setValue('contract')
		await press(wrapper, 'Save')
		expect(axiosMock.put.mock.calls[0][1].rate_overrides).toEqual({ CHF: { rate: 1.05, reason: 'contract' } })

		await press(wrapper, 'Remove', 0)
		expect(wrapper.vm.overrideRows).toEqual([])
		await press(wrapper, 'Save')
		expect(axiosMock.put.mock.calls[1][1].rate_overrides).toEqual({})
	})

	it('shows the loading icon in the save button while saving', async () => {
		let finish
		axiosMock.put.mockReturnValueOnce(new Promise((resolve) => {
			finish = resolve
		}))
		const wrapper = await loaded({})

		await press(wrapper, 'Save')
		expect(wrapper.text()).toContain('Saving…')
		expect(wrapper.find('[data-stub="NcLoadingIcon"]').exists()).toBe(true)

		finish({ data: {} })
		await flushPromises()
		expect(wrapper.text()).not.toContain('Saving…')
	})
})
