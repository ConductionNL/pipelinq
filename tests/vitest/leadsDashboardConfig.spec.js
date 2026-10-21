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

describe('the lead list', () => {
	const leads = page('Leads')

	it('is an index page whose manifest columns are the real columns', () => {
		expect(leads.type).toBe('index')
		expect(leads.component).toBeUndefined()
		expect(leads.config.columns.map((c) => c.key)).toEqual(['title', 'stage', 'value', 'qualificationScore', 'expectedCloseDate', 'priority', 'assignee'])
		expect(registrySource).not.toMatch(/LeadListView/)
	})

	it('never marks a closed lead overdue, in the cell or on the row', () => {
		const close = leads.config.columns.find((c) => c.key === 'expectedCloseDate')
		expect(close.widget).toBe('date')
		expect(close.widgetProps.due).toEqual({ soonWithin: 7, activeWhen: { field: 'status', equals: 'open' } })
		expect(leads.config.rowClass).toEqual([{
			when: [{ field: 'expectedCloseDate', op: 'lt', value: '@today' }, { field: 'status', value: 'open' }],
			class: 'lead-overdue',
		}])
		expect(fs.readFileSync(path.join(ROOT, 'src/assets/app.css'), 'utf8')).toMatch(/\.lead-overdue/)
	})

	it('lets the admin\'s stale threshold move the Out of date chip and its footer sentence', () => {
		const chip = leads.config.quickFilters.find((f) => f.label === 'Out of date')
		expect(chip.filter['@self[updated][lt]']).toBe('@today-{@config.lead_stale_threshold_days}d')
		expect(leads.config.footerNote).toContain('@config.lead_stale_threshold_days')
		const app = fs.readFileSync(path.join(ROOT, 'src/App.vue'), 'utf8')
		expect(app).toMatch(/cnAppConfig: computed\(\(\) => \(\{\s*lead_stale_threshold_days: getStaleThreshold/)
	})

	it('shows the win chance with a Low flag under 30 wherever a lead table lists it', () => {
		const tables = JSON.stringify(manifest)
		expect(tables).not.toMatch(/"lead-probability"|"lead-close-date"/)
		const flagged = []
		JSON.parse(tables, (key, value) => {
			if (value && value.key === 'qualificationScore' && value.widget === 'number') {
				flagged.push(value)
			}
			return value
		})
		expect(flagged.length).toBe(2)
		for (const column of flagged) {
			expect(column.widgetProps.flagWhen).toEqual({ field: 'qualificationScore', lt: 30, text: 'Low', variant: 'warning' })
		}
	})
})

describe('the store', () => {
	it('is the library store page with pipelinq\'s kinds and its shipped templates', () => {
		const store = page('Store')
		expect(store.type).toBe('store')
		expect(store.config.app).toBe('pipelinq')
		expect(store.config.kinds).toEqual(['pipeline', 'queue-routing', 'catalogue', 'pos-setup', 'loyalty-programme'])
		expect(store.config.builtIn.map((item) => item.slug)).toEqual(['b2b-sales-pipeline', 'service-desk-routing', 'retail-pos-setup'])
		// Install stays administrators only, the library default.
		expect(store.config.canInstall).toBeUndefined()
		expect(registrySource).not.toMatch(/StoreGallery/)
	})
})

describe('the lead forecast', () => {
	it('is a Forecast tab in the lead sidebar, locked once the deal is closed', () => {
		const tab = page('LeadDetail').config.sidebar.tabs.find((t) => t.id === 'forecast')
		const widget = tab.widgets[0]
		expect(widget.type).toBe('data')
		expect(widget.content.include).toEqual(['forecast_category', 'commit_justification'])
		const category = widget.content.overrides.forecast_category
		expect(category.readOnlyWhen).toEqual({ field: 'forecast_category', in: ['closed_won', 'closed_lost'] })
		expect(Object.keys(category.enumLabels)).toEqual(['commit', 'best_case', 'pipeline', 'omitted', 'closed_won', 'closed_lost'])
		expect(widget.content.overrides.commit_justification.visibleWhen).toEqual({ field: 'forecast_category', value: 'commit' })
		expect(registrySource).not.toMatch(/LeadForecastTab/)
	})
})

describe('the export lists', () => {
	it('are index pages whose row actions call the export endpoints', () => {
		const jobs = page('ExportJobs').config
		expect(page('ExportJobs').type).toBe('index')
		expect(jobs.actions.map((a) => a.id)).toEqual(['test-run', 'enable', 'disable'])
		expect(jobs.actions[0]).toMatchObject({ type: 'open-modal', target: 'ExportTestRunModal' })
		expect(jobs.actions[1]).toMatchObject({ type: 'api-call', url: '/apps/pipelinq/api/export/jobs/{objectId}/enable', visibleWhen: { field: 'enabled', value: false } })
		expect(jobs.actions[2]).toMatchObject({ type: 'api-call', url: '/apps/pipelinq/api/export/jobs/{objectId}/disable', visibleWhen: { field: 'enabled', value: true } })
		expect(jobs.headerButtons[0].action).toBe(jobs.headerActions[0].id)
		expect(jobs.headerActions[0]).toMatchObject({ type: 'open-page', target: 'ExportJobNew' })

		const runs = page('ExportRuns').config
		expect(runs.actions[0]).toMatchObject({ type: 'api-call', url: '/apps/pipelinq/api/export/runs/{objectId}/retry' })
		expect(runs.actions[0].visibleWhen.value).toEqual(['failed', 'partial', 'skipped_overlap'])
		expect(runs.showAdd).toBe(false)

		const dests = page('ExportDestinations').config
		expect(dests.actions[0]).toMatchObject({ type: 'api-call', url: '/apps/pipelinq/api/export/destinations/{objectId}/test' })
		expect(registrySource).not.toMatch(/ExportJobsView|ExportRunsView|ExportDestinationsView/)
		expect(registrySource).toMatch(/ExportTestRunModal: \{\s*kind: 'modal'/)
	})
})

describe('an export run', () => {
	it('is a detail page with its summary, files, snapshots and a Retry where it applies', () => {
		const run = page('ExportRunDetail')
		expect(run.type).toBe('detail')
		expect(run.config.widgets.map((w) => w.id)).toEqual(['run-summary', 'run-files', 'run-snapshots'])
		expect(run.config.widgets[1].content.endpointSource).toEqual({ url: '/apps/pipelinq/api/export/runs/@objectId', responsePath: 'run.fileManifestJson' })
		expect(run.config.headerActions[0].visibleWhen.value).toEqual(['failed', 'partial', 'skipped_overlap'])
		expect(registrySource).not.toMatch(/ExportRunDetailView/)
	})
})

describe('an export destination', () => {
	it('is made through the list\'s schema form and kept on a detail page with Test connection', () => {
		const list = page('ExportDestinations').config
		expect(list.headerButtons[0].action).toBe('add')
		expect(list.fieldOverrides.validationStatus.hidden).toBe(true)
		expect(page('ExportDestinationNew')).toBeUndefined()
		const detail = page('ExportDestinationDetail')
		expect(detail.type).toBe('detail')
		expect(detail.config.headerActions[0]).toMatchObject({ type: 'api-call', url: '/apps/pipelinq/api/export/destinations/@objectId/test' })
		expect(detail.config.widgets[0].content.overrides.validationStatus.editable).toBe(false)
		expect(registrySource).not.toMatch(/ExportDestinationFormView/)
	})
})

describe('the knowledge base on the operational dashboard', () => {
	it('is a kb-search widget scoped to the admin\'s default xWiki space', () => {
		const widget = page('OperationalDashboard').config.widgets.find((w) => w.id === 'xwiki-knowledge')
		expect(widget.type).toBe('kb-search')
		expect(widget.content).toEqual({ space: '@config.xwiki_default_space?', limit: 5 })
		expect(fs.readFileSync(path.join(ROOT, 'src/App.vue'), 'utf8')).toMatch(/xwiki_default_space: useSettingsStore\(\)\.config\?\.xwiki_default_space/)
		expect(registrySource).not.toMatch(/XWikiDashboardWidget/)
	})
})

describe('the pipeline board', () => {
	const pipeline = page('Pipeline')

	it('reads every record on the chosen pipeline, by its stages, and moves through the stage handler', () => {
		expect(pipeline.type).toBe('index')
		expect(pipeline.config.entitySource).toBe('endpoint')
		expect(pipeline.config.sourceConfig).toEqual({ url: '/apps/pipelinq/api/pipeline-items', rowsPath: 'results', searchFields: { pipeline: { single: true } } })
		const board = pipeline.config.board
		expect(board.narrow.field).toBe('pipeline')
		expect(board.columnFields[0].source).toMatchObject({ schema: 'pipeline', itemsPath: 'stages', keyField: 'name', closedField: 'isClosed', narrowBy: { field: '_parent', rowField: 'pipeline' } })
		expect(board.moveHandler).toBe('pipelineStageMove')
		expect(fs.readFileSync(path.join(ROOT, 'src/App.vue'), 'utf8')).toMatch(/pipelineStageMove: createPipelineStageMove/)
		expect(registrySource).not.toMatch(/PipelineBoardView/)
	})

	it('keeps the card actions, the live board and the pipeline settings', () => {
		const board = pipeline.config.board
		expect(board.cardActions).toEqual(['assign', 'priority'])
		expect(pipeline.config.actions.map((a) => [a.id, a.target, a.props.field])).toEqual([
			['assign', 'PipelineCardFieldModal', 'assignee'],
			['priority', 'PipelineCardFieldModal', 'priority'],
		])
		expect(pipeline.config.live).toEqual(['lead', 'ticket'])
		expect(pipeline.config.headerActions[0]).toMatchObject({ type: 'open-modal', target: 'PipelineSettingsModal' })
		expect(registrySource).toMatch(/PipelineCardFieldModal: \{\s*kind: 'modal'/)
		expect(registrySource).toMatch(/PipelineSettingsModal: \{\s*kind: 'modal'/)
	})
})
