/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The marketing intelligence pages are manifest config over library
 * widgets: their stored values read in words from the same labels the
 * register declares, and every word the config prints has a Dutch text.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')

/**
 * Read a JSON file under the repository root.
 *
 * @param {...string} parts Path segments.
 * @return {object}
 */
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const NL = readJson('l10n', 'nl.json').translations
const doc = readJson('src', 'manifest.d', '79-marketing-search-intelligence.json')
const watchSchemas = readJson('lib', 'Settings', 'register.d', '97-marketing-search-intelligence.json').components.schemas

/**
 * A page of the fragment by id.
 *
 * @param {string} id The page id.
 * @return {object}
 */
function page(id) {
	return doc.pages.find((p) => p.id === id)
}

/**
 * A column of a page's widget.
 *
 * @param {object} p The page.
 * @param {string} widgetId The widget id.
 * @param {string} key The column key.
 * @return {object}
 */
function column(p, widgetId, key) {
	const widget = p.config.widgets.find((w) => w.id === widgetId)
	return widget.content.columns.find((c) => c.key === key)
}

/**
 * Every label, title, text and enum label in a config tree.
 *
 * @param {unknown} node The tree.
 * @param {string[]} out The strings found.
 * @return {string[]}
 */
function printed(node, out = []) {
	if (Array.isArray(node)) {
		node.forEach((n) => printed(n, out))
	} else if (node && typeof node === 'object') {
		for (const [key, value] of Object.entries(node)) {
			if (['label', 'title', 'text', 'emptyText', 'successMessage'].includes(key) && typeof value === 'string') {
				out.push(value)
			} else if (key === 'enumLabels') {
				out.push(...Object.values(value))
			} else {
				printed(value, out)
			}
		}
	}
	return out
}

describe('competitor watches', () => {
	const p = page('CompetitorWatches')

	it('names watch kinds and schedules as the register does', () => {
		expect(column(p, 'competitor-watches', 'kind').enumLabels).toEqual(watchSchemas.competitorWatch.properties.kind['x-enum-labels'])
		expect(column(p, 'competitor-watches', 'schedule').enumLabels).toEqual(watchSchemas.competitorWatch.properties.schedule['x-enum-labels'])
		expect(column(p, 'competitor-events', 'kind').enumLabels).toEqual(watchSchemas.watchEvent.properties.kind['x-enum-labels'])
	})

	it('runs one watch through its own endpoint', () => {
		const watches = p.config.widgets.find((w) => w.id === 'competitor-watches')
		expect(watches.content.actions).toEqual([
			expect.objectContaining({ type: 'api-call', method: 'POST', url: '/apps/pipelinq/api/marketing/competitor-watches/@row.id/run' }),
		])
	})

	it('says the watches are off only when no egress source is set', () => {
		const off = p.config.widgets.find((w) => w.id === 'competitors-unconfigured')
		expect(off.visibleWhen).toEqual({ endpoint: '/apps/pipelinq/api/marketing/watch-events', field: 'configured', op: 'eq', value: false })
	})
})

describe('connection audit', () => {
	it('shows the answers in words, and the reason beside them', () => {
		const p = page('ConnectionAudit')
		for (const key of ['weFollowThem', 'theyFollowUs']) {
			expect(column(p, 'connection-audit', key).enumLabels).toEqual({ yes: 'Yes', no: 'No', unknown: 'Cannot be checked' })
		}
		expect(column(p, 'connection-audit', 'reason')).toBeTruthy()
		expect(p.config.headerActions[0]).toMatchObject({ method: 'GET', params: { refresh: 'true' } })
	})
})

describe('Dutch', () => {
	it('has a Dutch text for every word the converted pages print', () => {
		const strings = new Set(['CompetitorWatches', 'ConnectionAudit'].flatMap((id) => printed(page(id).config)))
		for (const text of strings) {
			expect(NL[text], text).toBeTruthy()
		}
	})
})

describe('subscriptions', () => {
	const lists = readJson('src', 'manifest.d', '76-marketing-lists.json')
	const subscription = readJson('lib', 'Settings', 'register.d', '96-marketing-lists-and-double-opt-in.json').components.schemas.subscription.properties

	it('lists a mailing list\'s subscribers from the subscription objects, state in words', () => {
		const detail = lists.pages.find((p) => p.id === 'MailingListDetail')
		expect(detail.config.bodyWidgets).toBeUndefined()
		const table = detail.config.widgets.find((w) => w.id === 'list-subscriptions')
		expect(table.content.source).toMatchObject({ register: 'pipelinq', schema: 'subscription', filter: { listId: '@objectId' } })
		expect(table.content.columns.find((c) => c.key === 'contactId').widget).toBe('fkResolve')
		expect(subscription.state['x-enum-labels'].bounced).toBe('Undeliverable')
		expect(subscription.state['x-color-map']).toEqual({ pending: 'warning', confirmed: 'success', unsubscribed: 'default', bounced: 'error' })
		expect(subscription.lawfulBasis['x-enum-labels']['soft-opt-in']).toBe('soft opt-in')
	})

	it('shows a contact\'s lists by name and manages them from the header', () => {
		const contact = readJson('src', 'manifest.json').pages.find((p) => p.id === 'ContactDetail')
		const table = contact.config.widgets.find((w) => w.id === 'contact-subscriptions')
		expect(table.type).toBe('object-table')
		expect(table.content.columns.find((c) => c.key === 'listId').widgetProps).toEqual({ register: 'pipelinq', schema: 'mailingList', labelField: 'name' })
		expect(contact.config.headerActions.find((a) => a.id === 'manage-subscriptions')).toMatchObject({ type: 'open-modal', target: 'SubscriptionManageModal', props: { contactId: '@objectId' } })
	})
})

describe('journey runs', () => {
	const journeys = readJson('src', 'manifest.d', '79-marketing-journeys.json')
	const runSchema = readJson('lib', 'Settings', 'register.d', '99-marketing-integrated-campaigns.json').components.schemas.journeyRun.properties

	it('lists what a journey did from its runs, with the counts above and the reasons in words', () => {
		const detail = journeys.pages.find((p) => p.id === 'JourneyDetail')
		const runs = detail.config.widgets.find((w) => w.id === 'journey-runs')
		expect(runs.type).toBe('object-table')
		expect(runs.content.endpointSource).toEqual({ url: '/apps/pipelinq/api/journeys/@objectId/runs', responsePath: 'results' })
		expect(runs.content.note).toBe('{counts.sent} sent, {counts.refused} refused, {counts.failed} failed')
		const columns = Object.fromEntries(runs.content.columns.map((c) => [c.key, c]))
		expect(columns.contactId.widget).toBe('fkResolve')
		expect(columns.state.enumLabels).toEqual(runSchema.state['x-enum-labels'])
		expect(columns.reason.enumLabels.suppressed_dunning).toBe('Skipped: this customer is being chased for an unpaid invoice')
		expect(columns.reason.enumLabels.no_consent).not.toBe(columns.reason.enumLabels.no_transport)
	})
})

describe('weekly review', () => {
	const page = readJson('src', 'manifest.d', '79-marketing-journeys.json').pages.find((p) => p.id === 'WeeklyReviewPage')

	it('reads the review in one response and names the sources that held nothing', () => {
		expect(page.type).toBe('dashboard')
		const summary = page.config.widgets.find((w) => w.id === 'weekly-review-summary')
		expect(summary.content.endpointSource).toEqual({ url: '/apps/pipelinq/api/weekly-review' })
		const degraded = summary.content.fields.find((f) => f.path === 'degraded')
		expect(degraded).toMatchObject({ format: 'list' })
		expect(degraded.label).toMatch(/missing data, not a zero/)
		expect(summary.content.fields.some((f) => f.path === 'agentAuthoredBy')).toBe(true)
		for (const [id, path] of [['weekly-review-highlights', 'highlights'], ['weekly-review-suggestions', 'suggestions'], ['weekly-review-ideas', 'topicIdeas']]) {
			expect(page.config.widgets.find((w) => w.id === id).content.endpointSource.responsePath).toBe(path)
		}
		expect(page.config.headerActions[0]).toMatchObject({ type: 'api-call', method: 'POST', url: '/apps/pipelinq/api/weekly-review/generate' })
	})
})
