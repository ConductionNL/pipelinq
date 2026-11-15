/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The loyalty reporting page as board PqLoyaliteit draws it: a dashboard with
 * a programme filter read from the programmes, a period filter, eight KPI
 * tiles in rows of four, the tier card with its CSV download and the
 * enrolment card, whose create waits for the customer's opt-in.
 *
 * @spec exclude This app has no canonical loyalty spec: the loyalty-program
 *  change is archived and no spec inherited it (`ls openspec/specs/ | grep -i loyal`).
 */

import { describe, expect, it } from 'vitest'
import fragment from '../../src/manifest.d/70-loyalty-program.json'

const page = fragment.pages.find((p) => p.id === 'LoyaltyReporting')
const config = page.config
const widget = (id) => config.widgets.find((w) => w.id === id)

describe('the loyalty reporting page', () => {
	it('is a dashboard with a programme filter from the programmes and a period filter', () => {
		expect(page.type).toBe('dashboard')
		expect(config.pageFilters.map((f) => f.key)).toEqual(['programme', 'period'])
		expect(config.pageFilters[0].optionsFrom).toEqual({ register: 'pipelinq', schema: 'loyaltyProgramme', labelField: 'name' })
		expect(config.pageFilters[1].control).toBe('segmented')
		expect(config.pageFilters[1].options.map((o) => o.value)).toEqual(['30', '90', '365'])
		expect(config.pageFilters[1].default).toBe('90')
	})

	it('draws the eight board KPIs in rows of four from the kpis endpoint over the chosen period', () => {
		expect(config.kpiColumns).toBe(4)
		expect(config.kpiRow.map((id) => widget(id).content.valueField)).toEqual([
			'activeAccounts', 'pointsIssued', 'pointsRedeemed', 'pointsExpired',
			'breakagePercent', 'redemptionRate', 'outstandingPoints', 'estimatedLiability',
		])
		for (const id of config.kpiRow) {
			expect(widget(id).content.endpointSource).toEqual({
				url: '/apps/pipelinq/api/loyalty/reporting/@workspace.programme/kpis',
				params: { days: '@workspace.period' },
			})
		}
	})

	it('downloads the KPIs and the tiers as CSV', () => {
		expect(config.headerActions[0]).toMatchObject({ type: 'api-call', download: true, params: { format: 'csv' } })
		const tierAction = widget('loyalty-tiers').headerActions[0]
		expect(tierAction.variant).toBe('tonal')
		expect(tierAction.action).toMatchObject({ type: 'api-call', url: '/apps/pipelinq/api/loyalty/reporting/@workspace.programme/tiers', params: { format: 'csv' }, download: true })
	})

	it('puts the tier bars and the tier table in one card beside the enrolment card', () => {
		expect(widget('loyalty-tiers').content.sections.map((s) => s.widgetId)).toEqual(['loyalty-tier-bars', 'loyalty-tier-table'])
		expect(widget('loyalty-tier-bars').content.chartKind).toBe('bar-list')
		expect(config.layout.map((l) => [l.widgetId, l.gridX, l.gridWidth])).toEqual([['loyalty-tiers', 0, 8], ['loyalty-enrollment', 8, 4]])
	})
})

describe('the enrolment card', () => {
	const content = widget('loyalty-enrollment').content
	const field = (key) => content.fields.find((f) => f.key === key)

	it('creates a loyalty account from the board fields', () => {
		expect(widget('loyalty-enrollment').type).toBe('create-form')
		expect(content.schema).toBe('customerLoyaltyAccount')
		expect(content.fields.map((f) => f.key)).toEqual(['customerId', 'programmeId', 'optInTermsVersion', 'optInAccepted'])
	})

	it('starts on the page programme and links its terms', () => {
		expect(field('programmeId').default).toBe('@workspace.programme')
		expect(field('programmeId').optionsFrom.keepFields).toEqual(['termsUrl'])
		expect(field('optInAccepted').link.from).toEqual({ field: 'programmeId', path: 'termsUrl' })
	})

	it('creates nothing without the customer, the programme and the opt-in the AVG requires', () => {
		expect(content.fields.filter((f) => f.required).map((f) => f.key)).toEqual(['customerId', 'programmeId', 'optInAccepted'])
		expect(field('optInAccepted').type).toBe('checkbox')
		expect(content.values).toMatchObject({ status: 'active', optInTimestamp: '@now' })
	})
})
