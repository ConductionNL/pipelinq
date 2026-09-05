// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/campaignReport.js — the shaping the campaign
 * report page does over the single response the report endpoint returns.
 *
 * The load-bearing case is the distinction between "not recorded" and zero:
 * a channel with no reach figure and a campaign with no recorded spend both
 * come back null, and rendering either as 0 would make a claim the data
 * does not support.
 */
import { describe, expect, it } from 'vitest'
import {
	ATTRIBUTION_MODELS,
	channelRows,
	chipForBasis,
	labelForModel,
	leadRows,
	modelRows,
	summaryTiles,
} from '../../src/services/campaignReport.js'

/** One report response, as GET /api/campaigns/{id}/report returns it. */
const REPORT = {
	campaign: {
		id: 'camp-1',
		name: 'Webinar AI voor gemeenten',
		defaultModel: 'linear',
	},
	window: { from: '2026-10-01', to: '2026-11-14' },
	channels: [
		{
			channel: 'email',
			reach: 190,
			opened: 80,
			click: 1,
			visit: 0,
			submit: 0,
			reply: 0,
		},
		{
			channel: 'social',
			reach: null,
			opened: 0,
			click: 0,
			visit: 1,
			submit: 1,
			reply: 0,
		},
	],
	engagement: { click: 1, visit: 1, submit: 1, reply: 0 },
	leads: [
		{
			leadId: 'lead-1',
			title: 'Gemeente Voorbeeld',
			basis: 'paid_invoice',
			value: 4840,
			invoiceIds: ['inv-1'],
		},
		{
			leadId: 'lead-2',
			title: 'Meridiaan Advies',
			basis: 'won_lead',
			value: 3000,
			invoiceIds: [],
		},
		{
			leadId: 'lead-3',
			title: 'Nog open',
			basis: 'open',
			value: 0,
			invoiceIds: [],
		},
	],
	totals: { leads: 3, attributedValue: 7840, currency: 'EUR' },
	models: {
		first: { byChannel: { email: 7840 }, total: 7840 },
		last: { byChannel: { social: 7840 }, total: 7840 },
		linear: { byChannel: { email: 2000, social: 5840 }, total: 7840 },
	},
	cost: {
		budgetEur: 2500,
		recorded: [{ channel: 'linkedin', amountEur: 180.5 }],
		totalEur: 180.5,
		currency: 'EUR',
	},
}

describe('channelRows', () => {
	it('keeps a missing reach as null so it can render as not recorded', () => {
		const rows = channelRows(REPORT)

		expect(rows.map((row) => row.channel)).toEqual(['email', 'social'])
		expect(rows[0].reach).toBe(190)
		expect(rows[1].reach).toBeNull()
	})

	it('renames the touchpoint kinds to what the table column says', () => {
		const [email, social] = channelRows(REPORT)

		expect(email.clicks).toBe(1)
		expect(social.submissions).toBe(1)
		expect(social.replies).toBe(0)
	})

	it('answers an empty list for a report with no channels', () => {
		expect(channelRows({})).toEqual([])
		expect(channelRows(undefined)).toEqual([])
	})
})

describe('modelRows', () => {
	it('orders a model by attributed value, biggest first', () => {
		const rows = modelRows(REPORT, 'linear')

		expect(rows.map((row) => row.channel)).toEqual(['social', 'email'])
		expect(rows[0].value).toBe(5840)
	})

	it('reports each channel as a share of that model total', () => {
		const [social, email] = modelRows(REPORT, 'linear')

		expect(social.share).toBeCloseTo(5840 / 7840)
		expect(email.share).toBeCloseTo(2000 / 7840)
	})

	it('gives every model the same total and a different split', () => {
		const totals = ATTRIBUTION_MODELS.map((model) =>
			modelRows(REPORT, model).reduce((sum, row) => sum + row.value, 0),
		)

		expect(totals).toEqual([7840, 7840, 7840])
		expect(modelRows(REPORT, 'first')).not.toEqual(modelRows(REPORT, 'linear'))
	})

	it('does not divide by zero when a model attributed nothing', () => {
		const empty = { models: { first: { byChannel: { email: 0 }, total: 0 } } }

		expect(modelRows(empty, 'first')[0].share).toBe(0)
	})
})

describe('chipForBasis', () => {
	it('names each basis the report can report', () => {
		expect(chipForBasis('paid_invoice').label).toBe('Paid invoice')
		expect(chipForBasis('won_lead').label).toBe('Won lead')
		expect(chipForBasis('open').label).toBe('Open')
	})

	it('says a basis is unknown rather than rendering the raw value', () => {
		expect(chipForBasis('refunded').label).toBe('Unknown')
	})

	it('colours every chip with a theme variable, never a literal', () => {
		for (const basis of ['paid_invoice', 'won_lead', 'open', 'refunded']) {
			expect(chipForBasis(basis).color).toMatch(/^var\(--color-/)
		}
	})
})

describe('labelForModel', () => {
	it('names the three models', () => {
		expect(ATTRIBUTION_MODELS.map(labelForModel)).toEqual([
			'First touch',
			'Last touch',
			'Linear',
		])
	})

	it('falls back to the raw value for a model it has never seen', () => {
		expect(labelForModel('time-decay')).toBe('time-decay')
	})
})

describe('leadRows', () => {
	it('labels each lead with the basis it closed on', () => {
		const rows = leadRows(REPORT)

		expect(rows.map((row) => row.chip.label)).toEqual([
			'Paid invoice',
			'Won lead',
			'Open',
		])
		expect(rows[0].invoiceCount).toBe(1)
		expect(rows[1].invoiceCount).toBe(0)
	})

	it('falls back to the lead id when a lead has no title', () => {
		expect(
			leadRows({ leads: [{ leadId: 'lead-9', basis: 'open' }] })[0].title,
		).toBe('lead-9')
	})
})

describe('summaryTiles', () => {
	it('reads the headline numbers off the one response', () => {
		expect(summaryTiles(REPORT)).toEqual({
			leads: 3,
			submissions: 1,
			clicks: 1,
			attributedValue: 7840,
			cost: 180.5,
			budget: 2500,
		})
	})

	it('keeps an unrecorded cost null, because zero reads as free', () => {
		const tiles = summaryTiles({
			...REPORT,
			cost: { recorded: [], totalEur: null, budgetEur: null },
		})

		expect(tiles.cost).toBeNull()
		expect(tiles.budget).toBeNull()
	})

	it('answers zeroes for a campaign with nothing in it yet', () => {
		expect(summaryTiles({})).toEqual({
			leads: 0,
			submissions: 0,
			clicks: 0,
			attributedValue: 0,
			cost: null,
			budget: null,
		})
	})
})
