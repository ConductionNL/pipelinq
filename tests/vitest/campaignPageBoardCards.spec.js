// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * PqCampagne cards as the board draws them (pl6): the details card writes
 * the status as a pill, the dates long and the budget in whole euros; the
 * landing page is a titled card in every state; the report's five tiles sit
 * in one row, its period reads in short dates, the attribution model is
 * named beside its picker, and the channel table has the board's 8px frame.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import nl from '../../l10n/nl.json'
import fragment from '../../src/manifest.d/78-marketing-campaigns.json'
import { CELL_FORMATTERS } from '../../src/services/cellFormatters.js'

const ROOT = resolve(__dirname, '../..')
const read = (...p) => readFileSync(resolve(ROOT, ...p), 'utf8')
const widgets = fragment.pages.find((page) => page.id === 'CampaignDetail').config.widgets
const data = widgets.find((w) => w.id === 'campaign-data')

const axiosGet = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet, post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		props: ['ariaLabelCombobox', 'inputLabel'],
		render() {
			return h('div', { 'data-stub': name }, [this.$slots.default?.()])
		},
	})
	return { NcEmptyContent: stub('NcEmptyContent'), NcLoadingIcon: stub('NcLoadingIcon'), NcSelect: stub('NcSelect') }
})

const translate = (app, text, vars = {}) => String(nl.translations[text] || text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)
globalThis.t = translate

const { default: CampaignReport } = await import('../../src/views/marketing/CampaignReport.vue')

const REPORT = {
	campaign: { id: 'camp-1', name: 'Afvalvrije week 2026', defaultModel: 'last' },
	window: { from: '2026-09-28', to: '2026-10-07' },
	channels: [{ channel: 'email', reach: 0, opened: 0, click: 0, visit: 0, submit: 0, leads: 0 }],
	engagement: { click: 0, visit: 0, submit: 0, reply: 0 },
	leads: [],
	totals: { leads: 0, attributedValue: 0 },
	models: { last: { byChannel: {}, total: 0 } },
	cost: { totalEur: null },
}

/**
 * Mount the report as the campaign page's card.
 *
 * @return {Promise<object>} The wrapper, after the report arrived.
 */
async function mountEmbedded() {
	axiosGet.mockResolvedValue({ data: REPORT })
	const wrapper = mount(CampaignReport, { props: { campaignId: 'camp-1' }, global: { mocks: { t: translate } } })
	await flushPromises()
	return wrapper
}

describe('PqCampagne details card', () => {
	it('writes the status as a pill in the campaigns list tones', () => {
		expect(data.content.overrides.status.pill.variantMap).toEqual({
			running: 'success',
			planned: 'info',
			finished: 'default',
			cancelled: 'error',
		})
	})

	it('writes the start and end dates long, with the year', () => {
		expect(data.content.overrides.startsAt.format).toBe('date-long')
		expect(data.content.overrides.endsAt.format).toBe('date-long')
	})

	it('writes the budget in whole euros', () => {
		expect(data.content.overrides.budgetEur.formatter).toBe('wholeCurrency')
		expect(CELL_FORMATTERS.wholeCurrency(2500, {}, {}, { locale: 'nl-NL' }).replace(/\s/g, ' ')).toBe('€ 2.500')
		expect(CELL_FORMATTERS.wholeCurrency('', {})).toBe('')
	})
})

describe('PqCampagne landing page card', () => {
	it('is a titled card, also while the campaign has no landing page', () => {
		expect(read('src', 'registry.js')).toMatch(/sectionWidget\('CampaignLandingPageWidget', CampaignLandingPageSection, \([^)]*\) => \([^)]*\), \{ card: true \}\)/)
	})
})

describe('PqCampagne report card', () => {
	it('names the period in short dates, the year once', async () => {
		const wrapper = await mountEmbedded()
		expect(wrapper.find('.campaign-report__window').text()).toBe('28 sep tot 7 okt 2026')
	})

	it('names the attribution model beside its picker', async () => {
		const wrapper = await mountEmbedded()
		const model = wrapper.findComponent({ name: 'NcSelect' })
		expect(wrapper.find('.campaign-report__model-label').text()).toBe('Toeschrijvingsmodel')
		expect(model.props('ariaLabelCombobox')).toBe('Toeschrijvingsmodel')
		expect(model.props('inputLabel')).toBeNull()
	})

	it('keeps the five tiles in one row, each as wide as its longest word, and frames the table at 8px', () => {
		const source = read('src', 'views', 'marketing', 'CampaignReport.vue')
		expect(source).toMatch(/\.campaign-report__tiles li \{\s*flex: 1 1 90px;\s*min-width: auto;\s*\}/)
		expect(source).toMatch(/\.campaign-report__scroll \{\s*border: 1px solid var\(--color-border\);\s*border-radius: 8px;/)
	})
})
