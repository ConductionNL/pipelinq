// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The campaign report as a section of a campaign's page: the channel table
 * names each channel, right-aligns its figures, says "not recorded" where a
 * channel has no measurement, and ends on the leads each channel brought in.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { CAMPAIGN_CHANNEL_LABELS } from '../../src/utils/enumLabels.js'

const ROOT = path.resolve(__dirname, '../..')
const NL = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8'),
).translations

const axiosGet = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet, post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))

/**
 * A stub that renders its default slot.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function stub(name) {
	return {
		name,
		inheritAttrs: false,
		render() {
			return h('div', { 'data-stub': name }, [this.$slots.default?.()])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcSelect: stub('NcSelect'),
}))

/**
 * Translate through the Dutch catalogue.
 *
 * @param {string} app The app id.
 * @param {string} text The English source string.
 * @param {object} vars Placeholder values.
 * @return {string} The Dutch text, or the source when it has none.
 */
function translate(app, text, vars = {}) {
	return String(NL[text] || text).replace(
		/\{(\w+)\}/g,
		(whole, key) => vars[key] ?? whole,
	)
}
globalThis.t = translate

const { default: CampaignReport } =
	await import('../../src/views/marketing/CampaignReport.vue')

const REPORT = {
	campaign: { id: 'camp-1', name: 'Afvalvrije week 2026', defaultModel: 'last' },
	window: { from: '2026-10-01', to: '2026-10-31' },
	channels: [
		{ channel: 'email', reach: 8400, opened: 3920, click: 902, visit: 640, submit: 12, leads: 151 },
		{ channel: 'mastodon', reach: null, opened: 0, click: 214, visit: 190, submit: 3, leads: 38 },
	],
	engagement: { click: 1116, visit: 830, submit: 15, reply: 0 },
	leads: [],
	totals: { leads: 189, attributedValue: 0 },
	models: { last: { byChannel: {}, total: 0 } },
	cost: { totalEur: 1140 },
}

/**
 * Mount the report as the campaign page's section.
 *
 * @return {Promise<object>} The wrapper, after the report arrived.
 */
async function mountEmbedded() {
	axiosGet.mockResolvedValue({ data: REPORT })
	const wrapper = mount(CampaignReport, {
		props: { campaignId: 'camp-1' },
		global: { mocks: { t: translate } },
	})
	await flushPromises()
	return wrapper
}

describe('the campaign page channel table', () => {
	it('names the channels in Dutch', async () => {
		const wrapper = await mountEmbedded()
		const firstCells = wrapper
			.findAll('[data-testid="campaign-report-channels"] tbody tr td:first-child')
			.map((cell) => cell.text())

		expect(firstCells).toEqual(['Nieuwsbrief', 'Mastodon'])
	})

	it('ends on the leads per channel instead of the submissions', async () => {
		const wrapper = await mountEmbedded()
		const heads = wrapper
			.findAll('[data-testid="campaign-report-channels"] thead th')
			.map((cell) => cell.text())

		expect(heads[heads.length - 1]).toBe('Leads')
		expect(heads).not.toContain('Inzendingen')
		const lastCells = wrapper
			.findAll('[data-testid="campaign-report-channels"] tbody tr td:last-child')
			.map((cell) => cell.text())
		expect(lastCells).toEqual(['151', '38'])
	})

	it('says not recorded where a channel has no opens', async () => {
		const wrapper = await mountEmbedded()
		const mastodon = wrapper
			.findAll('[data-testid="campaign-report-channels"] tbody tr')[1]
			.findAll('td')
			.map((cell) => cell.text())

		expect(mastodon[1]).toBe('Niet vastgelegd')
		expect(mastodon[2]).toBe('Niet vastgelegd')
	})

	it('right-aligns every figure column by a class the table rule cannot override', () => {
		const source = fs.readFileSync(
			path.join(ROOT, 'src', 'views', 'marketing', 'CampaignReport.vue'),
			'utf8',
		)

		expect(source).toMatch(/&\.campaign-report__num\s*\{\s*text-align:\s*end/)
	})

	it('has a Dutch label for every channel it names', () => {
		for (const label of Object.values(CAMPAIGN_CHANNEL_LABELS)) {
			expect(NL[label], label).toBeTruthy()
		}
	})
})
