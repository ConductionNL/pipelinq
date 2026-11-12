// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The thin API services (which endpoint each call reaches and what it makes
 * of the answer), the POS staff permissions, letters, the dashboard data
 * cache and the CRUD stores for billing categories, skills and agent
 * profiles, with axios, fetch and the object store doubled.
 *
 * @spec openspec/specs/pipeline/spec.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() }))
const bus = vi.hoisted(() => ({ handlers: {} }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => (k in params ? params[k] : m)),
}))
vi.mock('@nextcloud/event-bus', () => ({ subscribe: (name, fn) => { bus.handlers[name] = fn }, emit: () => {} }))
const objectStore = {
	objectTypeRegistry: { ticket: { register: 'r', schema: 'ticket' }, lead: { register: 'r', schema: 'lead' } },
	fetchCollection: vi.fn(),
	getCollection: vi.fn(),
	saveObject: vi.fn(),
	deleteObject: vi.fn(),
}
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))
const session = { isSessionActive: true, permissions: {} }
vi.mock('../../src/store/modules/posSessionStore.js', () => ({ usePosSessionStore: () => session }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: async () => ({ objectStore }) }))
vi.mock('../../src/services/objectFetch.js', () => ({ fetchRegisteredObjects: vi.fn(async (registry, { type }) => [{ type }]) }))

const { useStaffPermissions } = await import('../../src/composables/useStaffPermissions.js')
const articles = await import('../../src/services/articlesApi.js')
const cti = await import('../../src/services/ctiApi.js')
const forecast = await import('../../src/services/forecastApi.js')
const journeys = await import('../../src/services/journeysApi.js')
const letters = await import('../../src/services/letters.js')
const mailing = await import('../../src/services/mailingListApi.js')
const pay = await import('../../src/services/posPaymentApi.js')
const social = await import('../../src/services/socialApi.js')
const views = await import('../../src/services/viewService.js')
const { loadActiveReceiptTemplates } = await import('../../src/services/receiptTemplates.js')
const dash = await import('../../src/services/dashboardData.js')
const { fetchRegisteredObjects } = await import('../../src/services/objectFetch.js')
const { useBillingCategoryStore } = await import('../../src/store/modules/billingCategory.js')
const { useSkillsStore } = await import('../../src/store/modules/skills.js')
const { useAgentProfilesStore } = await import('../../src/store/modules/agentProfiles.js')

const P = '/index.php/apps/pipelinq'

beforeEach(() => {
	setActivePinia(createPinia())
	for (const fn of [...Object.values(http), objectStore.fetchCollection, objectStore.getCollection, objectStore.saveObject, objectStore.deleteObject]) {
		fn.mockReset()
	}
	for (const verb of Object.keys(http)) {
		http[verb].mockResolvedValue({ data: {} })
	}
	fetchRegisteredObjects.mockClear()
	globalThis.OC = { requestToken: 'tok', getCurrentUser: () => ({ uid: 'ann' }) }
	vi.spyOn(console, 'error').mockImplementation(() => {})
	dash.invalidateDashboardData()
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.unstubAllGlobals()
	vi.useRealTimers()
})

describe('POS staff permissions', () => {
	it('grants nothing without an active session', () => {
		session.isSessionActive = false
		session.permissions = { canVoid: true, canRefund: true, canNoSale: true, maxDiscountPercent: 50 }
		const p = useStaffPermissions()
		expect([p.isActive(), p.canVoid(), p.canRefund(), p.canNoSale(), p.canApplyDiscount(5), p.maxDiscountPercent()]).toEqual([false, false, false, false, false, 0])
	})

	it('grants what the session allows, and a discount up to its maximum', () => {
		session.isSessionActive = true
		session.permissions = { canVoid: true, maxDiscountPercent: '10' }
		const p = useStaffPermissions()
		expect([p.canVoid(), p.canRefund(), p.canNoSale(), p.maxDiscountPercent()]).toEqual([true, false, false, 10])
		expect([p.canApplyDiscount(10), p.canApplyDiscount(10.5), p.canApplyDiscount(-1), p.canApplyDiscount('x'), p.canApplyDiscount(null)]).toEqual([true, false, false, false, true])
		session.permissions = null
		expect([p.maxDiscountPercent(), p.canApplyDiscount(1)]).toEqual([0, false])
	})
})

describe('API services', () => {
	it('reaches the article endpoints and unwraps the article', async () => {
		http.get.mockResolvedValueOnce({ data: { data: [{ id: 1 }] } })
		expect(await articles.fetchArticles(5)).toEqual([{ id: 1 }])
		expect(http.get).toHaveBeenLastCalledWith(`${P}/api/articles?limit=5`)
		expect(await articles.fetchArticles()).toEqual([])
		http.post.mockResolvedValueOnce({ data: { article: { id: 2 } } })
		expect(await articles.createArticle({ t: 1 })).toEqual({ id: 2 })
		expect([await articles.updateArticle(3, {}), await articles.publishArticle(3), await articles.archiveArticle(3), await articles.transitionArticle(3, 'review')]).toEqual([null, null, null, null])
		expect(http.post).toHaveBeenLastCalledWith(`${P}/api/articles/3/transition`, { transition: 'review' })
		await articles.fetchArticleUsages(4)
		await articles.previewTemplate(5)
		expect(http.get.mock.calls.slice(-2).map((c) => c[0])).toEqual([`${P}/api/articles/4/usages`, `${P}/api/templates/5/preview`])
	})

	it('sends a queue with a disposition only when the call is escalated', async () => {
		await cti.submitDisposition('cm 1', { subject: 's', outcome: 'escalated', notes: 'n', queue: 'q1' })
		expect(http.post).toHaveBeenLastCalledWith(`${P}/api/cti/contactmoment/cm%201/disposition`, { subject: 's', outcome: 'escalated', notes: 'n', queue: 'q1' })
		await cti.submitDisposition('cm1', { subject: 's', outcome: 'resolved', notes: 'n', queue: 'q1' })
		expect(http.post.mock.calls.at(-1)[1]).toEqual({ subject: 's', outcome: 'resolved', notes: 'n' })
		await cti.submitDisposition('cm1', { outcome: 'escalated' })
		expect('queue' in http.post.mock.calls.at(-1)[1]).toBe(false)
	})

	it('reaches the CTI endpoints and reads the escalation queues', async () => {
		http.get.mockResolvedValueOnce({ data: { queues: ['a'] } })
		expect(await cti.escalationQueues()).toEqual(['a'])
		http.get.mockResolvedValueOnce({ data: { queues: 'x' } })
		expect(await cti.escalationQueues()).toEqual([])
		await cti.screenPop('+31')
		await cti.clickToDial('+31', '200')
		await cti.attachRecording('c1', 'http://r', '2026-06-01')
		expect(http.post.mock.calls.map((c) => c[1])).toEqual([{ fromNumber: '+31' }, { targetNumber: '+31', extension: '200' }, { recordingUrl: 'http://r', expiresAt: '2026-06-01' }])
		await cti.incomingCall()
		await cti.getConfig()
		await cti.testConnection()
		await cti.getEventLog({ day: 1 })
		await cti.updateConfig({ on: true })
		expect(http.get.mock.calls.at(-1)).toEqual([`${P}/api/cti/event-log`, { params: { day: 1 } }])
		expect(http.put).toHaveBeenCalledWith(`${P}/api/cti/config`, { on: true })
		expect(cti.default.screenPop).toBe(cti.screenPop)
	})

	it('asks for forecast snapshots per owner only when one is given', async () => {
		await forecast.fetchSnapshots({ periodId: 'q2', level: 'team' })
		expect(http.get.mock.calls[0][1].params).toEqual({ periodId: 'q2', level: 'team', format: 'json', limit: 50, offset: 0 })
		await forecast.fetchSnapshots({ periodId: 'q2', level: 'owner', ownerId: 'ann', limit: 5, offset: 10 })
		expect(http.get.mock.calls[1][1].params).toEqual({ periodId: 'q2', level: 'owner', format: 'json', limit: 5, offset: 10, ownerId: 'ann' })
		expect(forecast.csvExportUrl({ periodId: 'q 2', level: 'team' })).toBe(`${P}/api/forecast/snapshots?periodId=q%202&level=team&format=csv`)
		expect(forecast.csvExportUrl({ periodId: 'q2', level: 'owner', ownerId: 'a b' })).toMatch(/&ownerId=a%20b$/)
		await forecast.createOverride({ v: 1 })
		await forecast.deleteOverride('o 1')
		expect(http.delete).toHaveBeenCalledWith(`${P}/api/forecast/overrides/o%201`)
	})

	it('creates a journey without an id and patches one with', async () => {
		await journeys.saveJourney({ a: 1 })
		await journeys.saveJourney({ a: 2 }, 'j1')
		expect(http.post).toHaveBeenCalledWith(`${P}/api/journeys`, { a: 1 })
		expect(http.patch).toHaveBeenCalledWith(`${P}/api/journeys/j1`, { a: 2 })
		http.get.mockResolvedValueOnce({ data: { results: [1] } })
		expect([await journeys.fetchJourneys(), await journeys.fetchJourneyRuns('j1')]).toEqual([[1], []])
		await journeys.fetchJourney('j1')
		await journeys.fetchWeeklyReview()
		await journeys.fetchSegmentSignals()
		await journeys.generateWeeklyReview('2026-05-11')
		expect(http.post).toHaveBeenLastCalledWith(`${P}/api/weekly-review/generate`, { weekStarting: '2026-05-11' })
	})

	it('reaches the mailing list endpoints', async () => {
		http.get.mockResolvedValueOnce({ data: { subscriptions: [1] } })
		expect(await mailing.fetchContactSubscriptions('c1')).toEqual([1])
		http.get.mockResolvedValueOnce({ data: {} })
		expect(await mailing.fetchPreferenceLink('c1')).toBe('')
		http.get.mockResolvedValueOnce({ data: { data: [2] } })
		expect(await mailing.fetchMailingLists()).toEqual([2])
		await mailing.fetchListSubscriptions('l1')
		await mailing.unsubscribeContact('c1')
		expect(http.post).toHaveBeenLastCalledWith(`${P}/api/subscriptions/unsubscribe`, { contactId: 'c1', listId: '', reason: '' })
		await mailing.importSoftOptIn({ x: 1 })
		await mailing.inviteToList('l1', 'a@b.nl')
		expect(http.post).toHaveBeenLastCalledWith(`${P}/api/lists/l1/subscribe`, { email: 'a@b.nl' })
	})

	it('reads payment providers and falls back on empty answers', async () => {
		expect([await pay.listProviders(), await pay.listPaymentMethods(), await pay.getProvider('mollie'), await pay.updateProvider('mollie', {})]).toEqual([[], [], null, null])
		expect(await pay.testConnection('a b')).toEqual({ status: 'error', message: 'No result' })
		expect(http.post).toHaveBeenLastCalledWith(`${P}/api/payment-providers/a%20b/test`)
		http.get.mockResolvedValueOnce({ data: { providers: ['p'] } }).mockResolvedValueOnce({ data: { methods: ['ideal'] } }).mockResolvedValueOnce({ data: { provider: { n: 1 } } })
		expect([await pay.listProviders(), await pay.listPaymentMethods(), await pay.getProvider('x')]).toEqual([['p'], ['ideal'], { n: 1 }])
		http.post.mockResolvedValueOnce({ data: { result: { status: 'ok' } } })
		expect(await pay.testConnection('x')).toEqual({ status: 'ok' })
		await pay.initiatePayment('t1', 'mollie', 'ideal')
		await pay.capturePayment('t1')
		await pay.refundPayment('t1', 'broken')
		expect(http.post.mock.calls.slice(-3).map((c) => [c[0], c[1]])).toEqual([[`${P}/api/pos-payments/t1/initiate`, { providerName: 'mollie', paymentMethod: 'ideal' }], [`${P}/api/pos-payments/t1/capture`, undefined], [`${P}/api/pos-payments/t1/refund`, { reason: 'broken' }]])
	})

	it('reaches the social endpoints and unwraps each answer', async () => {
		expect(await social.fetchAccounts()).toEqual({ data: [], readiness: {} })
		expect([await social.fetchConnectParameters('a'), await social.startBrokerConnection({}), await social.attachCredential('a'), await social.revokeAccount('a')]).toEqual([null, '', null, null])
		expect(http.post.mock.calls[1][0]).toBe('/index.php/apps/openregister/api/credentials/oauth2/start')
		await social.fetchPosts('draft')
		await social.fetchPosts()
		await social.fetchPerformance('x y')
		await social.fetchPerformance()
		expect(http.get.mock.calls.slice(-4).map((c) => c[0])).toEqual([`${P}/api/social-posts?status=draft`, `${P}/api/social-posts`, `${P}/api/social-performance?network=x%20y`, `${P}/api/social-performance`])
		http.get.mockResolvedValueOnce({ data: { post: { id: 'p' } } })
		expect(await social.fetchPost('p')).toEqual({ id: 'p' })
		expect([await social.createPost({}), await social.updatePost('p', {}), await social.movePost('p', 'approve'), await social.fetchPublications('p'), await social.retryPublication('u'), await social.fetchShare('u'), await social.confirmShare('u')]).toEqual([null, null, null, [], null, null, null])
		expect(http.post.mock.calls.find((c) => c[0].endsWith('/approve'))[1]).toEqual({ note: '' })
	})

	it('reads views and schemas through fetch, failing loudly on an error status', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ results: ['v'] }) }))
		vi.stubGlobal('fetch', fetchSpy)
		expect(await views.getViews()).toEqual(['v'])
		expect(fetchSpy.mock.calls[0][1].headers.requesttoken).toBe('tok')
		fetchSpy.mockResolvedValueOnce({ ok: true, json: async () => ['bare'] })
		expect(await views.getViews()).toEqual(['bare'])
		expect(await views.getView('v1')).toEqual({ results: ['v'] })
		expect(await views.getSchemaProperties('s1')).toEqual({ results: ['v'] })
		fetchSpy.mockResolvedValue({ ok: false, status: 403 })
		await expect(views.getViews()).rejects.toThrow('Failed to fetch views: 403')
		await expect(views.getView('v1')).rejects.toThrow('Failed to fetch view v1: 403')
		await expect(views.getSchemaProperties('s1')).rejects.toThrow('Failed to fetch schema s1: 403')
	})

	it('keeps only the active receipt templates, and none when loading fails', async () => {
		objectStore.getCollection.mockReturnValueOnce({ results: [{ status: 'active' }, { status: 'draft' }, {}] })
		expect(await loadActiveReceiptTemplates()).toEqual([{ status: 'active' }, {}])
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		expect(await loadActiveReceiptTemplates()).toEqual([])
	})
})

describe('letters', () => {
	it('reads the templates, defaulting what is missing', async () => {
		expect(await letters.fetchLetterTemplates()).toEqual({ available: false, reason: '', templates: [] })
		http.get.mockResolvedValueOnce({ data: { available: true, reason: 'r', templates: [{ id: 't' }] } })
		expect(await letters.fetchLetterTemplates()).toEqual({ available: true, reason: 'r', templates: [{ id: 't' }] })
	})

	it('sends only the parts of the letter request that are set', async () => {
		await letters.makeLetter('c 1', { templateId: 't' })
		expect(http.post).toHaveBeenLastCalledWith(`${P}/api/clients/c%201/letters`, { templateId: 't' })
		await letters.makeLetter('c1', { templateId: 't', contactId: 'p', ticketId: 'k', downloadOnly: true })
		expect(http.post.mock.calls.at(-1)[1]).toEqual({ templateId: 't', contactId: 'p', ticketId: 'k', downloadOnly: true })
		http.post.mockResolvedValueOnce({ data: { html: '<p>x</p>', fields: ['a'], complete: true, warnings: 'no' } })
		expect(await letters.previewLetter('c1', { templateId: 't', contactId: 'p', ticketId: 'k' })).toEqual({ html: '<p>x</p>', fields: ['a'], complete: true, warnings: [] })
		expect(http.post.mock.calls.at(-1)).toEqual([`${P}/api/clients/c1/letters/preview`, { templateId: 't', contactId: 'p', ticketId: 'k' }])
		expect(await letters.previewLetter('c1', { templateId: 't' })).toEqual({ html: '', fields: [], complete: false, warnings: [] })
	})

	it('turns the letter into a PDF blob and downloads it', async () => {
		const blob = letters.letterBlob({ content: btoa('PDF') })
		expect([blob.type, blob.size]).toEqual(['application/pdf', 3])
		expect(letters.letterBlob({ mimeType: 'text/plain' }).type).toBe('text/plain')
		vi.useFakeTimers()
		URL.createObjectURL = vi.fn(() => 'blob:1')
		URL.revokeObjectURL = vi.fn()
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		letters.downloadLetter({ content: '', filename: 'brief.pdf' })
		expect([click.mock.contexts[0].download, click.mock.contexts[0].href, document.querySelectorAll('a').length]).toEqual(['brief.pdf', 'blob:1', 0])
		letters.downloadLetter({})
		expect(click.mock.contexts[1].download).toBe('letter.pdf')
		vi.runAllTimers()
		expect(URL.revokeObjectURL).toHaveBeenCalledTimes(2)
	})

	it('keeps the letter body and strips scripts and styles from the preview', () => {
		const body = letters.letterPreviewBody('<html><head><style>p{}</style></head><body><p>Hi</p><script>alert(1)</script><style>x{}</style></body></html>')
		expect(body).toBe('<p>Hi</p>')
		expect(letters.letterPreviewBody(null)).toBe('')
	})
})

describe('dashboard data', () => {
	it('asks once per type within five minutes and again after', async () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		await dash.getLeads()
		await dash.getLeads()
		expect(fetchRegisteredObjects).toHaveBeenCalledTimes(1)
		expect(fetchRegisteredObjects.mock.calls[0][1]).toEqual({ type: 'lead', params: { _limit: 500 } })
		vi.setSystemTime(new Date('2026-05-15T12:04:59Z'))
		await dash.getLeads()
		expect(fetchRegisteredObjects).toHaveBeenCalledTimes(1)
		vi.setSystemTime(new Date('2026-05-15T12:05:01Z'))
		await dash.getLeads()
		expect(fetchRegisteredObjects).toHaveBeenCalledTimes(2)
	})

	it('reads each kind of record with its own filter', async () => {
		await Promise.all([dash.getRequests(), dash.getPipelines(), dash.getContracts(), dash.getComplaints(), dash.getClients(), dash.getMyLeads(), dash.getMyRequests()])
		expect(fetchRegisteredObjects.mock.calls.map((c) => c[1])).toEqual([
			{ type: 'ticket', params: { ticketType: 'request', _limit: 500 } },
			{ type: 'pipeline', params: { _limit: 100 } },
			{ type: 'salesContract', params: { _limit: 1000 } },
			{ type: 'ticket', params: { ticketType: 'complaint', _limit: 500 } },
			{ type: 'client', params: { _limit: 500 } },
			{ type: 'lead', params: { assignee: 'ann', _limit: 200 } },
			{ type: 'ticket', params: { ticketType: 'request', assignee: 'ann', _limit: 200 } },
		])
	})

	it('has nothing of my own without a signed-in user', async () => {
		globalThis.OC = {}
		expect([await dash.getMyLeads(), await dash.getMyRequests()]).toEqual([[], []])
		expect(fetchRegisteredObjects).not.toHaveBeenCalled()
	})

	it('forgets a failed read so the next one tries again', async () => {
		fetchRegisteredObjects.mockRejectedValueOnce(new Error('down'))
		await expect(dash.getClients()).rejects.toThrow('down')
		await dash.getClients()
		expect(fetchRegisteredObjects).toHaveBeenCalledTimes(2)
	})

	it('reads the analytics endpoints per period and metric, failing on an error status', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ n: 1 }) }))
		vi.stubGlobal('fetch', fetchSpy)
		expect(await dash.getAnalyticsOverview('q 2')).toEqual({ n: 1 })
		await dash.getCommercialOverview('q2')
		await dash.getAnalyticsTrend('won value', 'q2')
		expect(fetchSpy.mock.calls.map((c) => c[0])).toEqual([`${P}/api/analytics/overview?period=q%202`, `${P}/api/analytics/commercial?period=q2`, `${P}/api/analytics/trends?metric=won%20value&period=q2`])
		fetchSpy.mockResolvedValueOnce({ ok: false })
		await expect(dash.getAnalyticsOverview('q3')).rejects.toThrow('Failed to fetch /apps/pipelinq/api/analytics/overview?period=q3')
	})

	it('clears the cache and raises the refresh signal on a page refresh', async () => {
		const signal = dash.getDashboardRefreshSignal()
		const before = signal.token
		await dash.getPipelines()
		bus.handlers['cn:page:refresh']()
		expect(signal.token).toBe(before + 1)
		await dash.getPipelines()
		expect(fetchRegisteredObjects).toHaveBeenCalledTimes(2)
	})

	it('collects the names of closed stages over all pipelines', () => {
		const names = dash.getClosedStageNames([{ stages: [{ name: 'Won', isClosed: true }, { name: 'New' }] }, {}, { stages: [{ name: 'Lost', isClosed: true }] }])
		expect([...names]).toEqual(['Won', 'Lost'])
		expect(dash.getClosedStageNames(null).size).toBe(0)
	})
})

describe('CRUD stores', () => {
	const cases = [
		['billing categories', useBillingCategoryStore, 'billingCategory', 'categories', 'fetchCategories', 'saveCategory', 'deleteCategory', 200],
		['skills', useSkillsStore, 'skill', 'skills', 'fetchSkills', 'saveSkill', 'deleteSkill', 100],
		['agent profiles', useAgentProfilesStore, 'agentProfile', 'profiles', 'fetchProfiles', 'saveProfile', 'deleteProfile', 200],
	]

	for (const [label, useStore, type, list, fetchName, saveName, deleteName, limit] of cases) {
		it(`loads, saves and deletes ${label}, keeping the error`, async () => {
			const store = useStore()
			objectStore.fetchCollection.mockResolvedValueOnce([{ id: 'a' }, { id: 'b' }])
			await store[fetchName]()
			expect(objectStore.fetchCollection).toHaveBeenCalledWith(type, { _limit: limit })
			expect([store[list].length, store.loading]).toEqual([2, false])
			objectStore.fetchCollection.mockResolvedValueOnce(null)
			await store[fetchName]()
			expect(store[list]).toEqual([])
			objectStore.fetchCollection.mockRejectedValueOnce(new Error('load failed'))
			await store[fetchName]()
			expect([store.error, store.loading]).toEqual(['load failed', false])

			objectStore.saveObject.mockResolvedValueOnce({ id: 'c' })
			objectStore.fetchCollection.mockResolvedValueOnce([{ id: 'c' }, { id: 'd' }])
			expect(await store[saveName]({ x: 1 })).toEqual({ id: 'c' })
			expect([objectStore.saveObject.mock.calls[0], store[list].length, store.error]).toEqual([[type, { x: 1 }], 2, null])
			objectStore.saveObject.mockResolvedValueOnce(null)
			const fetches = objectStore.fetchCollection.mock.calls.length
			expect(await store[saveName]({})).toBeNull()
			expect(objectStore.fetchCollection.mock.calls.length).toBe(fetches)
			objectStore.saveObject.mockRejectedValueOnce(new Error('save failed'))
			expect([await store[saveName]({}), store.error]).toEqual([null, 'save failed'])

			objectStore.deleteObject.mockResolvedValueOnce(false)
			expect([await store[deleteName]('c'), store[list].length]).toEqual([false, 2])
			objectStore.deleteObject.mockResolvedValueOnce(true)
			expect([await store[deleteName]('c'), store[list]]).toEqual([true, [{ id: 'd' }]])
			objectStore.deleteObject.mockRejectedValueOnce(new Error('delete failed'))
			expect([await store[deleteName]('d'), store.error, store.loading]).toEqual([false, 'delete failed', false])
		})
	}

	it('finds active and default billing categories by id, slug or uuid', () => {
		const store = useBillingCategoryStore()
		store.categories = [{ id: 'a', isDefault: true, isActive: false }, { id: 'b', isDefault: true, '@self': { slug: 'bee', uuid: 'u-b' } }, { id: 'c', isActive: true }]
		expect([store.activeCategories.map((c) => c.id), store.defaultCategory.id]).toEqual([['b', 'c'], 'b'])
		expect([store.getCategoryById('bee').id, store.getCategoryById('u-b').id, store.getCategoryById('c').id, store.getCategoryById('z')]).toEqual(['b', 'b', 'c', undefined])
		store.categories = [{ id: 'a' }]
		expect(store.defaultCategory).toBeNull()
	})

	it('lists active skills and available agents, and finds them', () => {
		const skills = useSkillsStore()
		skills.skills = [{ id: 's1' }, { id: 's2', isActive: false }]
		expect([skills.activeSkills.map((s) => s.id), skills.getSkillById('s2').id]).toEqual([['s1'], 's2'])
		const agents = useAgentProfilesStore()
		agents.profiles = [{ userId: 'ann' }, { userId: 'bob', isAvailable: false }]
		expect([agents.availableProfiles.map((p) => p.userId), agents.getProfileByUserId('bob').userId]).toEqual([['ann'], 'bob'])
	})

	it('counts an agent workload as open requests plus open leads', async () => {
		const fetchSpy = vi.fn(async (url) => {
			if (url.includes('_limit=200')) {
				return { ok: true, json: async () => ({ results: [{ status: 'new' }, { status: 'completed' }, { status: 'rejected' }, { status: 'open' }] }) }
			}
			if (url.includes('ticketType=request')) {
				return { ok: true, json: async () => ({ results: [{}], total: 9 }) }
			}
			return { ok: true, json: async () => ({ total: 3 }) }
		})
		vi.stubGlobal('fetch', fetchSpy)
		const agents = useAgentProfilesStore()
		expect(await agents.getWorkload('a b')).toBe(5)
		expect(fetchSpy.mock.calls.map((c) => c[0])).toEqual([
			'/index.php/apps/openregister/api/objects/r/ticket?ticketType=request&assignee=a%20b&_limit=1',
			'/index.php/apps/openregister/api/objects/r/ticket?ticketType=request&assignee=a%20b&_limit=200',
			'/index.php/apps/openregister/api/objects/r/lead?assignee=a%20b&status=open&_limit=1',
		])
	})

	it('counts zero for a failed or refused read, and without a registry entry', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false })))
		const agents = useAgentProfilesStore()
		expect(await agents.getWorkload('ann')).toBe(0)
		expect(await agents._countOpenRequests({ register: 'r', schema: 't' }, 'ann')).toBe(0)
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('x') }))
		expect([await agents.getWorkload('ann'), await agents._countOpenRequests({ register: 'r', schema: 't' }, 'ann')]).toEqual([0, 0])
		const registry = objectStore.objectTypeRegistry
		objectStore.objectTypeRegistry = {}
		expect(await agents.getWorkload('ann')).toBe(0)
		objectStore.objectTypeRegistry = registry
	})
})
