/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The leads, pipeline and dashboard surfaces that became manifest config over
 * nextcloud-vue widgets (PL-ABSTRACT lane PL4). Each case pins what the old
 * custom component did, so the config cannot quietly lose it.
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const page = (id) => manifest.pages.find((p) => p.id === id)
const registrySource = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')

describe('operational dashboard', () => {
	const ops = page('OperationalDashboard')

	it('exports from a header action that names the records it downloads', () => {
		const action = ops.config.headerActions.find((a) => a.type === 'export')
		expect(action.handler).toBeUndefined()
		expect(action.entities.map((e) => e.id)).toEqual(['leads', 'requests', 'contact-moments', 'satisfaction'])
		for (const entity of action.entities) {
			expect(entity.register).toBe('pipelinq')
			expect(typeof entity.schema).toBe('string')
		}
		expect(action.entities.find((e) => e.id === 'requests').filter).toEqual({ ticketType: 'request' })
		expect(action.entities.find((e) => e.id === 'contact-moments').filter).toEqual({ ticketType: 'interaction' })
		expect(ops.config.widgets.some((w) => w.id === 'report-export')).toBe(false)
		expect(registrySource).not.toMatch(/ReportExportPanel/)
	})

	it('lists my work from the worklist, each item opening its own page, never 0 on a failure', () => {
		const myWork = ops.config.widgets.find((w) => w.id === 'my-work')
		expect(myWork.type).toBe('work-queue')
		expect(myWork.content.source.url).toBe('/apps/pipelinq/api/worklist/mine')
		expect(myWork.content.itemsPath).toBe('items')
		expect(myWork.content.lateField).toBe('isOverdue')
		expect(myWork.content.kind.labels).toEqual({ lead: 'Lead', request: 'Request' })
		expect(myWork.content.viewAll.route).toEqual({ name: 'MyWork' })
		expect(ops.slots['widget-my-work']).toBeUndefined()
		expect(registrySource).not.toMatch(/MyWorkWidget/)
	})
})

describe('features and roadmap, flows', () => {
	it('links to the comparison on the public site in the reader\'s language, from the library page', () => {
		const roadmap = page('FeaturesRoadmap')
		expect(roadmap.type).toBe('roadmap')
		expect(roadmap.component).toBeUndefined()
		expect(roadmap.config.compareUrl).toEqual({
			default: 'https://pipelinq.conduction.nl/compare',
			nl: 'https://pipelinq.conduction.nl/nl/compare',
		})
		expect(roadmap.config.compareLabel).toBe('How pipelinq compares to other help desks')
	})

	it('opens a flow with the library flow sidebar, not an app wrapper', () => {
		const flow = page('FlowDetail')
		expect(flow.type).toBe('flow')
		expect(flow.sidebarComponent).toBeUndefined()
		expect(registrySource).not.toMatch(/FlowDetailSidebar|FeaturesRoadmapView/)
	})
})

describe('Nextcloud Dashboard widgets', () => {
	const FROZEN = [
		'pipelinq_my_leads_widget',
		'pipelinq_deals_overview_widget',
		'pipelinq_recent_activities_widget',
		'pipelinq_find_client_widget',
		'pipelinq_create_lead_widget',
		'pipelinq_start_request_widget',
	]

	/**
	 * Load the registration file and capture what it registers.
	 *
	 * @return {Promise<{widgets: Array<object>, options: object}>}
	 */
	async function registered() {
		vi.resetModules()
		const calls = []
		vi.doMock('@conduction/nextcloud-vue', () => ({
			registerNcDashboardWidgets: (widgets, options) => calls.push({ widgets, options }),
		}))
		vi.doMock('@conduction/nextcloud-vue/css/index.css', () => ({}))
		await import('../../src/ncDashboardWidgets.js')
		return calls[0]
	}

	it('registers the six frozen ids, the ids the PHP widgets and user layouts carry', async () => {
		const { widgets, options } = await registered()
		expect(widgets.map((w) => w.id)).toEqual(FROZEN)
		expect(options.appId).toBe('pipelinq')
		for (const file of fs.readdirSync(path.join(ROOT, 'lib/Dashboard'))) {
			const php = fs.readFileSync(path.join(ROOT, 'lib/Dashboard', file), 'utf8')
			const id = (php.match(/return '(pipelinq_[a-z_]+)'/) || [])[1]
			if (id) {
				expect(FROZEN).toContain(id)
				expect(php).toContain("'-ncDashboardWidgets'")
			}
		}
	})

	it('stores a request category as its value, never its translated label', async () => {
		const { widgets } = await registered()
		const request = widgets.find((w) => w.id === 'pipelinq_start_request_widget')
		expect(request.type).toBe('quick-create')
		const category = request.config.fields.find((f) => f.key === 'category')
		expect(category.options.map((o) => o.value)).toEqual(['general', 'technical', 'billing', 'support'])
		expect(request.config.defaults).toMatchObject({ ticketType: 'request', status: 'new', occurredAt: '@now' })
		expect(request.config.list.source.params.ticketType).toBe('request')
	})

	it('creates a lead in the first stage of the chosen pipeline', async () => {
		const { widgets } = await registered()
		const lead = widgets.find((w) => w.id === 'pipelinq_create_lead_widget')
		expect(lead.config.fields.find((f) => f.key === 'pipeline')).toMatchObject({ type: 'select', default: 'first' })
		expect(lead.config.derive.stage).toEqual({ from: 'pipeline', path: 'stages', sortBy: 'order', field: 'name' })
		expect(lead.config.fields.find((f) => f.key === 'client').type).toBe('object')
	})

	it('finds a client on the server, with its actions per row', async () => {
		const { widgets } = await registered()
		const find = widgets.find((w) => w.id === 'pipelinq_find_client_widget')
		expect(find.config.search).toBeTruthy()
		expect(find.config.source.params).toBeUndefined()
		expect(find.config.rowActions.map((a) => a.id)).toEqual(['view', 'request', 'lead', 'copy-email'])
		expect(find.config.rowActions.find((a) => a.id === 'request').create.defaults).toMatchObject({ client: '{id}', ticketType: 'request' })
		expect(find.config.rowActions.find((a) => a.id === 'copy-email').copy).toBe('email')
		expect(find.config.create.schema).toBe('client')
	})

	it('shows the deal\'s client name and merges recent leads and requests', async () => {
		const { widgets } = await registered()
		const deals = widgets.find((w) => w.id === 'pipelinq_deals_overview_widget')
		expect(deals.config.source.params._extend).toEqual(['client'])
		expect(deals.config.subtitle).toContain('{client.name}')
		const recent = widgets.find((w) => w.id === 'pipelinq_recent_activities_widget')
		expect(recent.config.sources.map((s) => s.kind)).toEqual(['Lead', 'Request'])
		expect(recent.config.sortBy).toBe('@self.updated')
	})

	it('keeps every user-facing string free of em-dashes', () => {
		const source = fs.readFileSync(path.join(ROOT, 'src/ncDashboardWidgets.js'), 'utf8')
		expect(source).not.toMatch(/—/)
	})
})
