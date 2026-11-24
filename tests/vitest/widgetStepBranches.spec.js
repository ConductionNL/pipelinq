// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Branches of five widgets pinned before they were split into named steps:
 * the till tender labels and load, the my-work meta line, the werkplek
 * client preselect, the competitor watches load and the campaign report
 * load.
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
const objects = vi.hoisted(() => ({ registerObjectType: vi.fn(), fetchCollectionForOptions: vi.fn() }))
const campaigns = vi.hoisted(() => ({ fetchCampaignReport: vi.fn(), fetchCampaigns: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, vars = {}) => path.replace(/\{(\w+)\}/g, (match, key) => vars[key]) }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcEmptyContent: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: {}, CnResourceSelect: {}, CnStatusBadge: {} }))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({ default: {} }))
vi.mock('../../src/modals/AddTenderDialog.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objects }))
vi.mock('../../src/services/campaignsApi.js', () => campaigns)

let tenders
let myWork
let clientSelect
let watches
let report

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
	tenders = (await import('../../src/components/pos/TenderEntryPanel.vue')).default
	myWork = (await import('../../src/views/dashboard/widgets/MyWorkWidget.vue')).default
	clientSelect = (await import('../../src/views/werkplek/widgets/WerkplekClientSelect.vue')).default
	watches = (await import('../../src/views/marketing/CompetitorWatches.vue')).default
	report = (await import('../../src/views/marketing/CampaignReport.vue')).default
}, 60000)

beforeEach(() => {
	http.get.mockReset()
	Object.values(objects).forEach((fn) => fn.mockReset())
	Object.values(campaigns).forEach((fn) => fn.mockReset())
})

afterEach(() => {
	vi.useRealTimers()
})

describe('TenderEntryPanel', () => {
	/**
	 * @param {object} state The panel's state.
	 * @return {object} A panel context.
	 */
	function ctx(state) {
		return {
			...tenders.methods,
			tenderTypes: [{ id: 'cash', name: 'Cash' }, { uuid: 'card', code: 'PIN' }, { id: 'blank' }],
			typeNames: { gift: 'Gift card', empty: '' },
			transactionId: 'tx1',
			loading: false,
			errorMessage: 'old',
			tenders: ['old'],
			validation: null,
			...state,
		}
	}

	it('labels a tender by its listed type, a looked-up name, or not yet', () => {
		const c = ctx({})
		const label = (tenderType) => tenders.methods.tenderTypeLabel.call(c, tenderType === undefined ? undefined : { tenderType })
		expect(label('cash')).toBe('Cash')
		expect(label('card')).toBe('PIN')
		expect(label('blank')).toBe('Unknown')
		expect(label('gift')).toBe('Gift card')
		expect(label('empty')).toBe('Unknown')
		expect(label('other')).toBe('…')
		expect(label('')).toBe('Unknown')
		expect(label(undefined)).toBe('Unknown')
	})

	it('loads the tenders and their validation, quietly when asked', async () => {
		http.get.mockResolvedValue({ data: { results: [{ id: 1 }], validation: { ok: true } } })
		const c = ctx({})
		const pending = tenders.methods.loadTenders.call(c)
		expect(c.loading).toBe(true)
		expect(c.errorMessage).toBe('')
		await pending
		expect(http.get).toHaveBeenCalledWith('/apps/pipelinq/api/pos-transactions/tx1/tenders')
		expect(c).toMatchObject({ tenders: [{ id: 1 }], validation: { ok: true }, loading: false })

		http.get.mockResolvedValue({ data: {} })
		const quiet = ctx({ validation: 'kept' })
		const quietly = tenders.methods.loadTenders.call(quiet, { silent: true })
		expect(quiet.loading).toBe(false)
		await quietly
		expect(quiet).toMatchObject({ tenders: [], validation: 'kept' })
		http.get.mockResolvedValue(null)
		await tenders.methods.loadTenders.call(quiet, {})
		expect(quiet.tenders).toEqual([])
	})

	it('says why the tenders did not load', async () => {
		http.get.mockRejectedValue({ response: { data: { error: 'Closed' } } })
		const c = ctx({})
		await tenders.methods.loadTenders.call(c)
		expect(c.errorMessage).toBe('Closed')
		expect(c.tenders).toEqual(['old'])
		http.get.mockRejectedValue(new Error('x'))
		await tenders.methods.loadTenders.call(c)
		expect(c.errorMessage).toBe('Failed to load tenders')
		expect(c.loading).toBe(false)
	})
})

describe('MyWorkWidget.metaLine', () => {
	it('names the kind, then the stage or how the due date stands', () => {
		vi.useFakeTimers()
		vi.setSystemTime(new Date(2026, 5, 10, 12, 0, 0))
		const c = {
			...myWork.methods,
			t: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match)),
			n: (app, one, many, count) => (count === 1 ? one : many).replace('%n', count),
		}
		const line = (item) => myWork.methods.metaLine.call(c, item)
		expect(line({ entityType: 'lead' })).toBe('Lead')
		expect(line({ entityType: 'request', stageOrStatus: 'new' })).toBe('Request · new')
		expect(line({ entityType: 'lead', dueDate: new Date(2026, 5, 9) })).toBe('Lead · 1 day late')
		expect(line({ entityType: 'task', dueDate: new Date(2026, 5, 7) })).toBe('Request · 3 days late')
		expect(line({ entityType: 'lead', dueDate: new Date(2026, 5, 10, 18) })).toBe('Lead · today')
		expect(line({ entityType: 'lead', dueDate: new Date(2026, 5, 12) })).toMatch(/^Lead · closes /)
		expect(line({ entityType: 'request', dueDate: new Date(2026, 6, 1) })).toMatch(/^Request · due /)
		expect(line({ entityType: 'request', dueDate: new Date(2026, 6, 1) })).not.toContain('{date}')
	})
})

describe('WerkplekClientSelect.selectFirstClient', () => {
	/**
	 * @param {object} state The select's state.
	 * @return {object} A select context.
	 */
	function ctx(state) {
		return { ...clientSelect.methods, selectedClient: null, onSelect: vi.fn(), ...state }
	}

	it('registers the client type and picks the first client', async () => {
		objects.fetchCollectionForOptions.mockResolvedValue([{ id: 7 }, { id: 8 }])
		const c = ctx({})
		await clientSelect.methods.selectFirstClient.call(c)
		expect(objects.registerObjectType).toHaveBeenCalledWith('pipelinq-client', 'client', 'pipelinq')
		expect(objects.fetchCollectionForOptions).toHaveBeenCalledWith('pipelinq-client', { _limit: 1 })
		expect(c.onSelect).toHaveBeenCalledWith('7')

		objects.registerObjectType.mockImplementation(() => { throw new Error('registered') })
		objects.fetchCollectionForOptions.mockResolvedValue([{ '@self': { id: 'u9' } }])
		const nested = ctx({})
		await clientSelect.methods.selectFirstClient.call(nested)
		expect(nested.onSelect).toHaveBeenCalledWith('u9')
	})

	it('picks nothing without a client, or when one was picked meanwhile', async () => {
		for (const items of [[], null, [{}], [{ '@self': {} }], 'x']) {
			objects.fetchCollectionForOptions.mockResolvedValue(items)
			const c = ctx({})
			await clientSelect.methods.selectFirstClient.call(c)
			expect(c.onSelect).not.toHaveBeenCalled()
		}
		objects.fetchCollectionForOptions.mockResolvedValue([{ id: 1 }])
		const picked = ctx({ selectedClient: { id: 2 } })
		await clientSelect.methods.selectFirstClient.call(picked)
		expect(picked.onSelect).not.toHaveBeenCalled()
	})
})

describe('CompetitorWatches.fetchAll', () => {
	/**
	 * @return {object} A page context.
	 */
	function ctx() {
		return {
			...watches.methods,
			t: (app, text) => text,
			loading: false,
			error: 'old',
			configured: null,
			reason: null,
			competitors: null,
			watches: null,
			events: null,
		}
	}

	it('reads the watch events, with empty fallbacks', async () => {
		http.get.mockResolvedValue({ data: { configured: 1, reason: 'r', competitors: [1], watches: [2], events: [3] } })
		const c = ctx()
		const pending = watches.methods.fetchAll.call(c)
		expect(c.loading).toBe(true)
		expect(c.error).toBe('')
		await pending
		expect(http.get).toHaveBeenCalledWith('/apps/pipelinq/api/marketing/watch-events')
		expect(c).toMatchObject({ configured: true, reason: 'r', competitors: [1], watches: [2], events: [3], loading: false })
		http.get.mockResolvedValue({ data: null })
		await watches.methods.fetchAll.call(c)
		expect(c).toMatchObject({ configured: false, reason: '', competitors: [], watches: [], events: [] })
	})

	it('says why the watches did not load', async () => {
		http.get.mockRejectedValue({ response: { data: { error: 'No key' } } })
		const c = ctx()
		await watches.methods.fetchAll.call(c)
		expect(c.error).toBe('No key')
		http.get.mockRejectedValue(new Error('x'))
		await watches.methods.fetchAll.call(c)
		expect(c.error).toBe('Could not load the competitor watches.')
		expect(c.loading).toBe(false)
	})
})

describe('CampaignReport.load', () => {
	/**
	 * @param {object} state The report's state.
	 * @return {object} A report context.
	 */
	function ctx(state) {
		const c = {
			...report.methods,
			t: (app, text) => text,
			loading: false,
			error: 'old',
			model: null,
			modelOptions: ['first', 'second'],
			embedded: false,
			campaignId: 'c0',
			campaign: 'old',
			campaigns: [],
			$route: { query: {} },
			fetchReport: vi.fn(async () => {}),
			...state,
		}
		Object.defineProperty(c, 'campaignOptions', { get: () => c.campaigns.map((campaign) => ({ id: campaign.id, label: campaign.name })) })
		return c
	}

	it('reports on the embedded campaign without listing campaigns', async () => {
		const c = ctx({ embedded: true })
		await report.methods.load.call(c)
		expect(c.model).toBe('second')
		expect(c.campaign).toEqual({ id: 'c0', label: '' })
		expect(campaigns.fetchCampaigns).not.toHaveBeenCalled()
		expect(c.fetchReport).toHaveBeenCalledTimes(1)
	})

	it('picks the campaign the address names, else the first', async () => {
		campaigns.fetchCampaigns.mockResolvedValue([{ id: 'a', name: 'A' }, { id: 'b', name: 'B' }])
		const named = ctx({ $route: { query: { campaign: 'b' } } })
		await report.methods.load.call(named)
		expect(campaigns.fetchCampaigns).toHaveBeenCalledWith(100)
		expect(named.campaign).toEqual({ id: 'b', label: 'B' })
		expect(named.fetchReport).toHaveBeenCalledTimes(1)
		const first = ctx({ $route: undefined })
		await report.methods.load.call(first)
		expect(first.campaign).toEqual({ id: 'a', label: 'A' })
	})

	it('stops without a campaign, and says why the list did not load', async () => {
		campaigns.fetchCampaigns.mockResolvedValue([])
		const none = ctx({ loading: true })
		await report.methods.load.call(none)
		expect(none.campaign).toBeNull()
		expect(none.loading).toBe(false)
		expect(none.fetchReport).not.toHaveBeenCalled()
		campaigns.fetchCampaigns.mockRejectedValue({ response: { data: { error: 'Denied' } } })
		const failing = ctx({ campaigns: [{ id: 'x' }] })
		await report.methods.load.call(failing)
		expect(failing.campaigns).toEqual([])
		expect(failing.error).toBe('Denied')
		campaigns.fetchCampaigns.mockRejectedValue(new Error('x'))
		await report.methods.load.call(failing)
		expect(failing.error).toBe('Could not load the campaigns.')
	})
})
