// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The export job and destination forms and the test run dialog, the search
 * queries page (window, sorting, the connection audit) and the social
 * accounts page (connecting, finishing a connection, revoking), each run
 * against a plain component context with their services doubled.
 *
 * @spec openspec/specs/data-export/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnBuildiqEditButton: stub('CnBuildiqEditButton'),
	CnDataTable: stub('CnDataTable'),
	CnDetailCard: stub('CnDetailCard'),
	CnDetailPage: stub('CnDetailPage'),
	CnDialog: stub('CnDialog'),
	CnPageHeader: stub('CnPageHeader'),
	CnSegmentedControl: stub('CnSegmentedControl'),
	useLook: () => ({ lookClass: '' }),
}))
vi.mock('../../src/views/marketing/CompetitorWatches.vue', () => ({ default: stub('CompetitorWatches') }))
vi.mock('../../src/views/marketing/KeywordIntelligence.vue', () => ({ default: stub('KeywordIntelligence') }))
const exportApi = vi.hoisted(() => ({ testRun: vi.fn(), testDestination: vi.fn() }))
vi.mock('../../src/services/exportApi.js', () => ({ exportApi }))
vi.mock('../../src/services/csvExport.js', () => ({ downloadCsv: vi.fn() }))
const socialApi = vi.hoisted(() => ({ attachCredential: vi.fn(), fetchAccounts: vi.fn(), fetchConnectParameters: vi.fn(), revokeAccount: vi.fn(), startBrokerConnection: vi.fn() }))
vi.mock('../../src/services/socialApi.js', () => socialApi)
const objectStore = { fetchCollection: vi.fn(), getCollection: vi.fn(), fetchObject: vi.fn(), saveObject: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))

const { default: ExportJobForm } = await import('../../src/views/export/ExportJobForm.vue')
const { default: ExportDestinationForm } = await import('../../src/views/export/ExportDestinationForm.vue')
const { default: ExportTestRunModal } = await import('../../src/modals/ExportTestRunModal.vue')
const { default: SearchQueries } = await import('../../src/views/marketing/SearchQueries.vue')
const { default: SocialAccountsView } = await import('../../src/views/social/SocialAccountsView.vue')
const { downloadCsv } = await import('../../src/services/csvExport.js')

/**
 * A component context: props and extras, data, computed values as getters
 * and methods bound.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), $router: { push: vi.fn() }, t: globalThis.t, ...props, ...extra }
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	for (const fn of [...Object.values(objectStore), http.get, ...Object.values(exportApi), ...Object.values(socialApi)]) {
		fn.mockReset()
	}
})

afterEach(() => {
	vi.useRealTimers()
})

describe('ExportJobForm', () => {
	const form = (props = {}, extra = {}) => context(ExportJobForm, { exportJobId: null, id: null, ...props }, { objectStore, ...extra })

	it('loads the destinations, marking the unverified ones', async () => {
		objectStore.getCollection.mockReturnValue({ results: [{ id: 'd1', name: 'Lake', type: 's3', validationStatus: 'valid' }, { id: 'd2', name: 'Warehouse', type: 'bigquery' }] })
		const f = form()
		await ExportJobForm.mounted.call(f)
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('exportDestination', { _limit: 200 })
		expect(objectStore.fetchObject).not.toHaveBeenCalled()
		expect(f.destinationOptions.map((o) => o.label)).toEqual(['Lake (s3)', 'Warehouse (bigquery — unverified)'])
		f.model.destinationId = 'd2'
		expect(f.selectedDestination.id).toBe('d2')
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		await f.loadDestinations()
		expect([f.destinations, f.selectedDestination]).toEqual([[], null])
	})

	it('reads the job from the prop, the id or the route, and loads it', async () => {
		expect([form({ exportJobId: 'a' }).jobId, form({ id: 'b' }).jobId, form({}, { $route: { params: { id: 'c' } } }).jobId, form().isEdit]).toEqual(['a', 'b', 'c', false])
		objectStore.getCollection.mockReturnValue([])
		objectStore.fetchObject.mockResolvedValueOnce({ name: 'Nightly', columnAllowlist: ['id', 'name'], mode: 'incremental' })
		const f = form({ id: 'j1' })
		await ExportJobForm.mounted.call(f)
		expect(objectStore.fetchObject).toHaveBeenCalledWith('exportJob', 'j1')
		expect([f.model.name, f.model.format, f.allowlistText, f.selectedMode.label, f.selectedFormat.id]).toEqual(['Nightly', 'csv', 'id, name', 'Incremental', 'csv'])
		objectStore.fetchObject.mockRejectedValueOnce(new Error('x'))
		await f.load()
		expect([toasts, f.loading]).toEqual([[['error', 'Could not load the job']], false])
	})

	it('picks schemas and saves the job with a clean column list', async () => {
		const f = form({ id: 'j1' })
		f.onSchemasSelect([{ id: 'lead' }, { id: 'client' }])
		expect(f.selectedSchemas.map((o) => o.id)).toEqual(['client', 'lead'])
		f.onSchemasSelect(null)
		expect(f.model.sourceSchemas).toEqual([])
		f.allowlistText = ' id, ,name ,'
		await f.save()
		expect(objectStore.saveObject.mock.calls[0]).toEqual(['exportJob', expect.objectContaining({ id: 'j1', columnAllowlist: ['id', 'name'] })])
		expect([toasts, f.$router.push.mock.calls[0][0], f.busy]).toEqual([[['success', 'Export job saved']], { name: 'ExportJobs' }, false])
		const fresh = form()
		objectStore.saveObject.mockRejectedValueOnce(new Error('x'))
		await fresh.save()
		expect('id' in objectStore.saveObject.mock.calls[1][1]).toBe(false)
		expect(toasts.at(-1)).toEqual(['error', 'Could not save the job'])
	})

	it('runs a test only on a saved job', () => {
		const fresh = form()
		fresh.openTestRunModal()
		expect([fresh.testRunOpen, toasts]).toEqual([false, [['error', 'Save the job before running a test']]])
		const saved = form({ id: 'j1' })
		saved.openTestRunModal()
		saved.goBack()
		expect([saved.testRunOpen, saved.$router.push.mock.calls[0][0]]).toEqual([true, { name: 'ExportJobs' }])
	})
})

describe('ExportDestinationForm', () => {
	const form = (props = {}) => context(ExportDestinationForm, { exportDestinationId: null, id: null, ...props }, { objectStore })

	it('loads an existing destination and keeps the defaults for a new one', async () => {
		const fresh = form()
		await ExportDestinationForm.mounted.call(fresh)
		expect([objectStore.fetchObject.mock.calls.length, fresh.selectedType.id, fresh.selectedCompression.id]).toEqual([0, 's3', 'none'])
		objectStore.fetchObject.mockResolvedValueOnce({ name: 'Lake', compression: 'zstd' })
		const f = form({ exportDestinationId: 'd1' })
		await ExportDestinationForm.mounted.call(f)
		expect([f.model.name, f.model.type, f.selectedCompression.id]).toEqual(['Lake', 's3', 'zstd'])
		f.model.type = 'ftp'
		expect(f.selectedType).toBeNull()
		objectStore.fetchObject.mockRejectedValueOnce(new Error('x'))
		await f.load()
		expect(toasts).toEqual([['error', 'Could not load the destination']])
	})

	it('saves with the id when editing and goes back to the list', async () => {
		const f = form({ id: 'd1' })
		await f.save()
		expect(objectStore.saveObject.mock.calls[0][1].id).toBe('d1')
		expect([toasts[0], f.$router.push.mock.calls[0][0]]).toEqual([['success', 'Destination saved'], { name: 'ExportDestinations' }])
		objectStore.saveObject.mockRejectedValueOnce(new Error('x'))
		await form().save()
		expect([toasts.at(-1), 'id' in objectStore.saveObject.mock.calls[1][1]]).toEqual([['error', 'Could not save the destination'], false])
	})

	it('reports the connection test', async () => {
		const f = form({ id: 'd1' })
		exportApi.testDestination.mockResolvedValueOnce({ valid: true }).mockResolvedValueOnce({ valid: false }).mockRejectedValueOnce(new Error('x'))
		await f.testConnection()
		await f.testConnection()
		await f.testConnection()
		expect(exportApi.testDestination).toHaveBeenCalledWith('d1')
		expect(toasts).toEqual([['success', 'Connection valid'], ['error', 'Connection failed'], ['error', 'Connection test failed']])
		f.goBack()
		expect(f.busy).toBe(false)
	})
})

describe('ExportTestRunModal', () => {
	const modal = (props = {}) => context(ExportTestRunModal, { jobId: 'j1', initialResult: null, autoRun: true, ...props })

	it('shows nothing before a result', () => {
		const m = modal({ autoRun: false })
		ExportTestRunModal.mounted.call(m)
		expect(exportApi.testRun).not.toHaveBeenCalled()
		expect([m.sampleRows, m.errors, m.downloadUrl, m.downloadName, m.format, m.destination]).toEqual([0, [], null, 'export-test-sample', null, null])
	})

	it('reads either spelling of the result fields', () => {
		const snake = modal({ initialResult: { sample_rows: 5, download_url: '/a', filename: 'a.csv', format: 'csv', destination: 's3', errors: 'one' } })
		expect([snake.sampleRows, snake.downloadUrl, snake.downloadName, snake.format, snake.destination, snake.errors]).toEqual([5, '/a', 'a.csv', 'csv', 's3', ['one']])
		const camel = modal({ initialResult: { sampleRows: 3, downloadUrl: '/b', fileName: 'b.csv', destinationType: 'gcs' } })
		expect([camel.sampleRows, camel.downloadUrl, camel.downloadName, camel.destination, camel.errors, camel.format]).toEqual([3, '/b', 'b.csv', 'gcs', [], null])
		expect(modal({ initialResult: { sampleRows: '3' } }).sampleRows).toBe(0)
	})

	it('runs on open and reports the result, a missing answer or a failure', async () => {
		const m = modal()
		exportApi.testRun.mockResolvedValueOnce({ success: true, sampleRows: 10 })
		ExportTestRunModal.mounted.call(m)
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect([m.sampleRows, m.busy, m.$emit.mock.calls[0]]).toEqual([10, false, ['completed', { success: true, sampleRows: 10 }]])
		exportApi.testRun.mockResolvedValueOnce(null)
		await m.rerun()
		expect(m.errors).toEqual(['No response'])
		exportApi.testRun.mockRejectedValueOnce(new Error('Timeout'))
		await m.rerun()
		exportApi.testRun.mockRejectedValueOnce({})
		await m.rerun()
		expect([m.errors, m.$emit.mock.calls.length]).toEqual([['Test run failed'], 4])
		const given = modal({ initialResult: { success: true } })
		ExportTestRunModal.mounted.call(given)
		expect(exportApi.testRun).toHaveBeenCalledTimes(4)
	})
})

describe('SearchQueries', () => {
	const page = () => context(SearchQueries)

	it('loads the queries for the chosen window', async () => {
		vi.useFakeTimers({ now: new Date('2026-05-29T12:00:00Z'), toFake: ['Date'] })
		http.get.mockResolvedValue({ data: { rows: [{ query: 'b' }], totalQueries: 7, from: 'f', to: 't', configured: 1, lastImportAt: 'l' } })
		const p = page()
		await p.fetchRows()
		expect(http.get.mock.calls[0]).toEqual(['/index.php/apps/pipelinq/api/marketing/search-queries', { params: { from: '2026-05-01', to: '2026-05-29', limit: 100 } }])
		expect([p.totalQueries, p.configured, p.lastImportAt, p.loading]).toEqual([7, true, 'l', false])
		p.setDays('7')
		expect(http.get.mock.calls[1][1].params.from).toBe('2026-05-22')
		p.setDays('nope')
		expect(p.days).toBe(28)
		http.get.mockResolvedValueOnce({ data: {} })
		await p.fetchRows()
		expect([p.rows, p.from, p.configured]).toEqual([[], '', false])
	})

	it('explains a failed load and an empty list', async () => {
		const p = page()
		http.get.mockRejectedValueOnce({ response: { data: { error: 'No property' } } })
		await p.fetchRows()
		http.get.mockRejectedValueOnce({})
		const q = page()
		await q.fetchRows()
		expect([p.error, q.error, q.rows]).toEqual(['No property', 'Could not load search queries.', []])
		expect(q.emptyDescription).toMatch(/^Connect a Search Console property/)
		q.configured = true
		expect(q.emptyDescription).toMatch(/^The import has not brought in rows/)
	})

	it('sorts queries by text and numbers by value, either way', () => {
		const p = page()
		p.rows = [{ query: 'b', clicks: 2 }, { query: 'a', clicks: 10 }, { query: 'c' }]
		expect(p.sortedRows.map((r) => r.query)).toEqual(['a', 'b', 'c'])
		p.onSort({ key: 'clicks' })
		expect([p.sortOrder, p.sortedRows.map((r) => r.query)]).toEqual(['asc', ['c', 'b', 'a']])
		p.onSort({ key: 'query', order: 'desc' })
		expect(p.sortedRows.map((r) => r.query)).toEqual(['c', 'b', 'a'])
		p.onSort(null)
		expect(p.sortedRows).toBe(p.rows)
		p.download()
		expect(downloadCsv).toHaveBeenCalledWith('search-queries.csv', p.rows, ['query', 'clicks', 'impressions', 'ctr', 'position', 'pages'])
	})

	it('shows the first five audit rows and reruns the audit on request', async () => {
		const p = page()
		http.get.mockResolvedValueOnce({ data: { rows: Array.from({ length: 7 }, (_, i) => ({ network: 'mastodon', counterpartHandle: `h${i}`, ...(i === 0 ? { id: 'x' } : {}) })) } })
		expect(await p.fetchAudit()).toBe(true)
		expect([p.auditRows.length, p.auditRows[0].rowKey, p.auditRows[1].rowKey, http.get.mock.calls[0][1]]).toEqual([5, 'x', 'mastodon:h1', { params: {} }])
		http.get.mockResolvedValueOnce({ data: {} })
		await p.rerunAudit()
		expect([http.get.mock.calls[1][1], p.auditRunning, toasts[0]]).toEqual([{ params: { refresh: 'true' } }, false, ['success', 'The connection audit ran again.']])
		http.get.mockRejectedValueOnce(new Error('x'))
		await p.rerunAudit()
		expect([p.auditRows, toasts[1]]).toEqual([[], ['error', 'Could not load the connection audit.']])
	})

	it('labels networks and percentages, and offers three windows', () => {
		const p = page()
		expect([p.networkLabel('LinkedIn'), p.networkLabel('myspace'), p.networkLabel('')]).toEqual(['LinkedIn', 'myspace', '—'])
		expect([p.percent(0.1234), p.percent(null)]).toEqual(['12.3%', '0.0%'])
		expect(p.windowOptions.map((o) => o.value)).toEqual([7, 28, 90])
		expect(p.columns.filter((c) => c.type === 'number').map((c) => c.key)).toEqual(['clicks', 'impressions', 'ctr', 'position', 'pages'])
		expect(p.auditColumns).toHaveLength(5)
	})
})

describe('SocialAccountsView', () => {
	const view = (route) => context(SocialAccountsView, {}, { $route: route })

	beforeEach(() => {
		socialApi.fetchAccounts.mockResolvedValue({ data: [{ id: 'a1', network: 'mastodon' }], readiness: { bluesky: { state: 'not_configured', reason: 'No app key' } } })
	})

	it('loads the accounts and describes each one', async () => {
		const v = view()
		await v.load()
		expect([v.accounts, v.loading, v.error]).toEqual([[{ id: 'a1', network: 'mastodon' }], false, ''])
		expect([v.accountId({ uuid: 'u' }), v.accountId(null), v.networkLabel('mastodon'), v.networkLabel('odd')]).toEqual(['u', '', 'Mastodon', 'odd'])
		expect([v.showHandle({ displayName: 'Gem', handle: '@gem' }), v.showHandle({ displayName: 'x', handle: 'x' }), v.showHandle({ handle: 'x' })]).toEqual([true, false, false])
		expect([v.reasonFor({ statusReason: 'Expired' }), v.reasonFor({ network: 'bluesky' }), v.reasonFor({ network: 'mastodon' })]).toEqual(['Expired', 'No app key', ''])
		expect([v.canConnect({ network: 'bluesky' }), v.canConnect({ network: 'mastodon', publishMode: 'share' }), v.canConnect({ network: 'mastodon' })]).toEqual([false, false, true])
		expect([v.connectLabel({ credentialRef: 'c' }), v.connectLabel({})]).toEqual(['Reconnect', 'Connect'])
		socialApi.fetchAccounts.mockRejectedValueOnce(new Error('x'))
		await v.load()
		expect(v.error).toBe('The accounts could not be loaded.')
	})

	it('records a connection the broker sends back, or says it was not completed', async () => {
		const ok = view({ params: { id: 'a1' }, query: { connected: 'ok' } })
		await ok.load()
		expect([socialApi.attachCredential.mock.calls[0], ok.notice, socialApi.fetchAccounts.mock.calls.length]).toEqual([['a1'], 'The account is connected.', 2])
		const failed = view({ params: { id: 'a1' }, query: { connected: 'denied' } })
		await failed.load()
		expect(failed.error).toBe('The connection was not completed. Try connecting the account again.')
		socialApi.attachCredential.mockRejectedValueOnce({ response: { data: { error: 'Token expired' } } })
		await ok.recordConnection('a1')
		socialApi.attachCredential.mockRejectedValueOnce({})
		const other = view()
		await other.recordConnection('a1')
		expect([ok.error, other.error]).toEqual(['Token expired', 'The connection could not be recorded.'])
		await view({ params: {} }).finishConnection()
		expect(socialApi.attachCredential).toHaveBeenCalledTimes(3)
	})

	it('starts a connection at the broker, or explains why it could not', async () => {
		const v = view()
		socialApi.fetchConnectParameters.mockResolvedValue({ state: 's' })
		socialApi.startBrokerConnection.mockResolvedValueOnce('')
		await v.connect({ id: 'a1' })
		expect([socialApi.startBrokerConnection.mock.calls[0], v.error, v.busy]).toEqual([[{ state: 's' }], 'The connection could not be started.', ''])
		socialApi.fetchConnectParameters.mockRejectedValueOnce({ response: { data: { error: 'Network not set up' } } })
		await v.connect({ id: 'a1' })
		expect(v.error).toBe('Network not set up')
		socialApi.fetchConnectParameters.mockRejectedValueOnce({})
		await v.connect({ id: 'a1' })
		expect(v.error).toBe('The connection could not be started.')
	})

	it('revokes an account and reloads the list', async () => {
		const v = view()
		await v.revoke({ id: 'a1' })
		expect([socialApi.revokeAccount.mock.calls[0], v.accounts.length, v.busy]).toEqual([['a1'], 1, ''])
		socialApi.revokeAccount.mockRejectedValueOnce({ response: { data: { error: 'Gone' } } })
		await v.revoke({ id: 'a1' })
		socialApi.revokeAccount.mockRejectedValueOnce({})
		const w = view()
		await w.revoke({ id: 'a1' })
		expect([v.error, w.error]).toEqual(['Gone', 'The connection could not be revoked.'])
	})
})
