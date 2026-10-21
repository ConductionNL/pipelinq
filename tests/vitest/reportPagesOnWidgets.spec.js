/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The report pages are chart, stat and table widgets on the row arrays the
 * report endpoints answer, with no pipelinq component behind them.
 *
 * @spec openspec/changes/report-pages-on-widgets/specs/declarative-view-system/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file under the repository root.
 *
 * @param {...string} parts Path segments.
 * @return {object} The parsed file.
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const PAGES = [
	...readJson('src', 'manifest.json').pages,
	...fs
		.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
		.filter((name) => name.endsWith('.json'))
		.sort()
		.flatMap((name) => readJson('src', 'manifest.d', name).pages || []),
]

/**
 * A page by id.
 *
 * @param {string} id The page id.
 * @return {object} The page.
 */
function page(id) {
	const found = PAGES.find((p) => p.id === id)
	expect(found, `page ${id}`).toBeTruthy()
	return found
}

/**
 * A widget of a page by id.
 *
 * @param {object} p The page.
 * @param {string} id The widget id.
 * @return {object} The widget.
 */
function widget(p, id) {
	const found = (p.config.widgets || []).find((w) => w.id === id)
	expect(found, `widget ${id} on ${p.id}`).toBeTruthy()
	return found
}

/**
 * Every widget on a page is placed in its layout, and the page hosts no
 * section component.
 *
 * @param {object} p The page.
 */
function expectWidgetsOnly(p) {
	expect(p.config.bodyWidgets || []).toEqual([])
	const placed = new Set((p.config.layout || []).map((l) => l.widgetId))
	for (const w of p.config.widgets) {
		expect(placed.has(w.id), `${w.id} placed on ${p.id}`).toBe(true)
	}
}

describe('contact, channel and agent reports', () => {
	it('draws the channel distribution from the channel rows and exports per period', () => {
		const p = page('RapportageContactmomenten')
		expectWidgetsOnly(p)
		const chart = widget(p, 'rap-channels')
		expect(chart.type).toBe('chart')
		expect(chart.content.chartKind).toBe('bar-list')
		expect(chart.content.endpointSource).toMatchObject({
			url: '/apps/pipelinq/api/rapportage/channels',
			params: { period: '@workspace.period' },
			responsePath: 'rows',
			labelsPath: 'label',
		})
		const exportAction = p.config.headerActions.find((a) => a.id === 'export-contact-moments')
		expect(exportAction).toMatchObject({
			type: 'api-call',
			method: 'GET',
			download: true,
			url: '/apps/pipelinq/api/rapportage/export',
			params: { period: '@workspace.period' },
		})
	})

	it('compares channels on the figures the server counts', () => {
		const p = page('ChannelAnalyticsView')
		expectWidgetsOnly(p)
		const table = widget(p, 'channel-comparison')
		expect(table.type).toBe('object-table')
		expect(table.content.endpointSource.responsePath).toBe('rows')
		const keys = table.content.columns.map((c) => c.key)
		expect(keys).toEqual(['label', 'total', 'fcrRate', 'slaCompliance', 'slaStatus'])
		const status = table.content.columns.find((c) => c.key === 'slaStatus')
		expect(Object.keys(status.enumLabels)).toEqual(['green', 'orange', 'red'])
		expect(p.config.pageFilters.map((f) => f.key)).toEqual(['period'])
	})

	it('sorts agents by handling time as a number and names them', () => {
		const p = page('AgentPerformanceView')
		expectWidgetsOnly(p)
		const table = widget(p, 'agent-performance')
		const columns = Object.fromEntries(table.content.columns.map((c) => [c.key, c]))
		expect(columns.agentName.label).toBe('Agent')
		expect(columns.avgHandlingSeconds.format).toEqual({ style: 'duration', unit: 'seconds' })
		expect(widget(p, 'agents-contacts').content.source.path).toBe('totals.contacts')
		expect(widget(p, 'agents-fcr').content.source.path).toBe('totals.fcrRate')
	})
})

describe('lead analytics', () => {
	const p = page('Rapportage')

	it('reads every widget from one pipeline-stats request', () => {
		expectWidgetsOnly(p)
		const reads = p.config.widgets.map((w) => w.content.endpointSource || w.content.source)
		for (const read of reads) {
			expect(read.url).toBe('/apps/pipelinq/api/rapportage/pipeline-stats')
			expect(read.params).toEqual({
				pipelineId: '@workspace.pipeline?',
				dateFrom: '@workspace.dateFrom?',
				dateTo: '@workspace.dateTo?',
			})
		}
	})

	it('offers the win/loss windows as the page date range', () => {
		expect(p.config.dateRange.presets.map((r) => r.id)).toEqual(['30d', '90d', '12m', 'all'])
		expect(p.config.dateRange.default).toEqual({ preset: 'all' })
		expect(p.config.pageFilters[0]).toMatchObject({ key: 'pipeline', clearable: true })
	})

	it('hands the source table format specs and source labels', () => {
		const columns = Object.fromEntries(widget(p, 'lead-sources').content.columns.map((c) => [c.key, c]))
		expect(columns.conversionRate.format).toEqual({ style: 'percent', decimals: 1 })
		expect(columns.avgWonValue.format).toEqual({ style: 'currency', decimals: 0 })
		expect(columns.source.enumLabels['cold-call']).toBe('Cold call')
		expect(columns.source.enumLabels.unknown).toBe('No source')
	})

	it('takes the whole row for the funnel and the source table', () => {
		const wide = p.config.layout.filter((l) => ['lead-funnel', 'lead-sources'].includes(l.widgetId))
		expect(wide.map((l) => l.gridWidth)).toEqual([12, 12])
	})

	it('draws won against lost from the slices the server words', () => {
		expect(widget(p, 'lead-win-loss').content.endpointSource).toMatchObject({
			responsePath: 'winLossSlices',
			labelsPath: 'label',
		})
	})
})

describe('SLA attainment', () => {
	const p = page('SlaAttainment')

	it('draws the breakdown as a table headed by the grouping the server names', () => {
		expectWidgetsOnly(p)
		const table = widget(p, 'sla-breakdown')
		expect(table.content.endpointSource).toEqual({
			url: '/apps/pipelinq/api/sla/attainment',
			params: { bucket: '@workspace.bucket', groupBy: '@workspace.groupBy' },
			responsePath: 'details.byGroup',
		})
		const group = table.content.columns.find((c) => c.key === 'groupName')
		expect(group.labelPath).toBe('labels.group')
		const attainment = table.content.columns.find((c) => c.key === 'attainmentPercent')
		expect(attainment.format).toEqual({ style: 'percent', decimals: 1 })
	})

	it('colours its figures with tokens, not hex values', () => {
		for (const w of p.config.widgets) {
			expect(JSON.stringify(w)).not.toMatch(/#[0-9a-f]{6}/i)
		}
	})
})

describe('satisfaction', () => {
	it('shows the response rate on the Operational dashboard as a tile and a channel table', () => {
		const p = page('OperationalDashboard')
		const rate = widget(p, 'survey-response-rate')
		expect(rate.type).toBe('stat')
		expect(rate.content.endpointSource).toEqual({
			url: '/apps/pipelinq/api/satisfaction/response-rate',
			params: { from: '@workspace.dateFrom?', to: '@workspace.dateTo?' },
		})
		expect(rate.content.valueField).toBe('rate')
		expect(rate.content.caption).toBe('{responded} of {delivered} delivered invitations answered')
		expect(rate.content.note).toBe('Not delivered: {suppressed} held back, {failed} failed')
		const channels = widget(p, 'survey-response-channels')
		expect(channels.content.endpointSource.responsePath).toBe('channels')
		expect(channels.content.columns.find((c) => c.key === 'channel').enumLabels.sms).toBe('SMS')
		const placed = p.config.layout.map((l) => l.widgetId)
		expect(placed).toEqual(expect.arrayContaining(['survey-response-rate', 'survey-response-channels']))
		expect(p.slots['widget-survey-response-rate']).toBeUndefined()
	})

	it("shows a client's satisfaction as facts and its latest three comments", () => {
		const p = page('ClientDetail')
		expect((p.config.bodyWidgets || []).some((b) => b.component === 'ClientSatisfactionSection')).toBe(false)
		const facts = widget(p, 'client-satisfaction')
		expect(facts.type).toBe('facts')
		expect(facts.content.endpointSource.url).toBe('/apps/pipelinq/api/satisfaction/client/@objectId')
		expect(facts.content.emptyWhen).toBe('empty')
		expect(facts.content.emptyText).toMatch(/^No satisfaction data has been collected/)
		const trend = facts.content.fields.find((f) => f.path === 'trend')
		expect(trend.map.up).toBe('Better than the 90 days before')
		expect(trend.map.unknown).toBe('Not enough responses to compare')
		const comments = widget(p, 'client-satisfaction-comments')
		expect(comments.content).toMatchObject({ limit: 3, title: '{text}' })
		expect(comments.content.endpointSource.responsePath).toBe('verbatims')
	})
})

describe('billing categories', () => {
	it('charts approved hours per billing category, aggregated on the server', () => {
		const p = page('OperationalDashboard')
		const chart = widget(p, 'billing-categories')
		expect(chart.type).toBe('chart')
		expect(chart.content.chartKind).toBe('donut')
		expect(chart.content.dataSource).toEqual({
			register: 'pipelinq',
			schema: 'billingTimeEntry',
			filter: { status: 'approved' },
			aggregate: {
				groupBy: 'billingCategory',
				metric: 'sum',
				sumField: 'hours',
				labelResolve: { schema: 'billingCategory', labelField: 'name', colorField: 'color' },
			},
		})
		expect(p.slots['widget-billing-categories']).toBeUndefined()
	})
})

describe('loyalty reporting', () => {
	const p = page('LoyaltyReporting')

	it('is a dashboard on the programme the page filter picks', () => {
		expect(p.type).toBe('dashboard')
		expectWidgetsOnly(p)
		expect(p.config.pageFilters[0]).toMatchObject({
			key: 'programme',
			optionsFrom: { register: 'pipelinq', schema: 'loyaltyProgramme', labelField: 'name' },
		})
		for (const w of p.config.widgets) {
			expect(w.content.endpointSource.url).toMatch(/^\/apps\/pipelinq\/api\/loyalty\/reporting\/@workspace\.programme\//)
		}
		expect(JSON.stringify(p)).not.toMatch(/objects\/pipelinq\//)
	})

	it('downloads the figures and enrols a customer through the server', () => {
		const actions = Object.fromEntries(p.config.headerActions.map((a) => [a.id, a]))
		expect(actions['loyalty-download']).toMatchObject({ type: 'api-call', method: 'GET', download: true })
		expect(actions['loyalty-enrol']).toMatchObject({
			type: 'open-form',
			endpoint: { url: '/apps/pipelinq/api/loyalty/accounts', method: 'POST' },
		})
		expect(Object.keys(actions['loyalty-enrol'].formProperties)).toEqual(['customerId', 'programmeId', 'termsVersion', 'optIn'])
	})
})
