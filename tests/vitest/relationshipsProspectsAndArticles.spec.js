// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Contact relationships (grouping, the search, duplicates, saving both ends
 * and removing them), the prospect list (lenses, sorting, paging, the header
 * line and adding a prospect as a client) and the article content section
 * (hero image, loading, refreshing and the lifecycle moves), each run against
 * a plain component context.
 *
 * @spec openspec/specs/contact-relationships/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
const http = vi.hoisted(() => ({ get: vi.fn() }))
const bus = vi.hoisted(() => ({ emitted: [], subscribed: [], unsubscribed: [] }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@nextcloud/event-bus', () => ({
	emit: (name, payload) => bus.emitted.push([name, payload]),
	subscribe: (name) => bus.subscribed.push(name),
	unsubscribe: (name) => bus.unsubscribed.push(name),
}))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcEmptyContent: stub('NcEmptyContent'), NcLoadingIcon: stub('NcLoadingIcon'), NcNoteCard: stub('NcNoteCard'), NcSelect: stub('NcSelect') }))
const openRowTarget = vi.fn()
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDetailCard: stub('CnDetailCard'),
	CnIndexPage: stub('CnIndexPage'),
	CnQuickFilterBar: stub('CnQuickFilterBar'),
	cnRenderMarkdown: (text) => `<p>${text}</p>`,
	openRowTarget: (...args) => openRowTarget(...args),
	resolveImageUrl: (path) => `/resolved/${path}`,
}))
vi.mock('../../src/dialogs/RemoveRelationshipDialog.vue', () => ({ default: stub('RemoveRelationshipDialog') }))
vi.mock('../../src/modals/ArticleEditModal.vue', () => ({ default: stub('ArticleEditModal') }))
const objectStore = { fetchCollection: vi.fn(), fetchObject: vi.fn(), saveObject: vi.fn(), deleteObject: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))
const prospectStore = { prospects: [], icp: null, error: '', cachedAt: null, fetchProspects: vi.fn(), removeProspect: vi.fn() }
vi.mock('../../src/store/modules/prospect.js', () => ({ useProspectStore: () => prospectStore }))
vi.mock('../../src/services/contactSyncApi.js', () => ({ createWithContact: vi.fn() }))
const articleApi = vi.hoisted(() => ({ publishArticle: vi.fn(), archiveArticle: vi.fn(), transitionArticle: vi.fn() }))
vi.mock('../../src/services/articlesApi.js', () => articleApi)

const { default: ContactRelationships } = await import('../../src/components/ContactRelationships.vue')
const { default: ProspectsView } = await import('../../src/views/prospects/ProspectsView.vue')
const { default: ArticleContentSection } = await import('../../src/components/marketing/ArticleContentSection.vue')
const { createWithContact } = await import('../../src/services/contactSyncApi.js')

/**
 * A component context: data, props and extras, computed values as getters
 * and methods bound.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), t: globalThis.t, ...props, ...extra }
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
	bus.emitted.length = 0
	bus.subscribed.length = 0
	bus.unsubscribed.length = 0
	vi.clearAllMocks()
	for (const fn of [...Object.values(objectStore), http.get, createWithContact, ...Object.values(articleApi)]) {
		fn.mockReset()
	}
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	vi.useRealTimers()
})

describe('ContactRelationships', () => {
	const rel = (over) => ({ id: 'r1', fromContact: 'me', toContact: 'p1', type: 'partner', category: 'Familie', ...over })

	it('loads the relationships and names the other end of each', async () => {
		objectStore.fetchCollection.mockResolvedValueOnce([rel(), rel({ id: 'r2', toContact: 'c1', toType: 'client' }), rel({ id: 'r3' })])
		objectStore.fetchObject.mockImplementation(async (type, id) => (id === 'p1' ? { name: 'Piet' } : null))
		const c = context(ContactRelationships, { entityId: 'me', entityType: 'contact' })
		await ContactRelationships.mounted.call(c)
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('relationship', { _limit: 100, fromContact: 'me' })
		expect(objectStore.fetchObject.mock.calls.slice(0, 2)).toEqual([['contact', 'p1'], ['client', 'c1']])
		expect([c.getEntityName('p1'), c.getEntityName('c1'), c.getEntityName('zz'), c.getEntityName('')]).toEqual(['Piet', '[Deleted]', 'zz', '-'])
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		await c.fetchRelationships()
		expect([c.relationships, c.loading]).toEqual([[], false])
	})

	it('groups by category in the order that suits a person or an organisation', () => {
		const relationships = [rel({ category: 'Organisatie' }), rel({ category: 'Familie' }), rel({ category: undefined }), rel({ category: 'Professioneel' })]
		const person = context(ContactRelationships, { entityId: 'me', entityType: 'contact' })
		person.relationships = relationships
		expect(person.groupedRelationships.map((g) => g.category)).toEqual(['Familie', 'Professioneel', 'Organisatie', 'Other'])
		const org = context(ContactRelationships, { entityId: 'me', entityType: 'client' })
		org.relationships = relationships
		expect(org.groupedRelationships.map((g) => g.category)).toEqual(['Organisatie', 'Professioneel', 'Familie', 'Other'])
		expect(person.typeOptions[1]).toEqual({ value: 'ouder', label: 'Ouder (Familie)', inverse: 'kind', category: 'Familie', symmetric: false })
		expect(person.strengthOptions.map((o) => o.value)).toEqual(['strong', 'medium', 'weak'])
	})

	it('tells an ended relationship and opens the other end', () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		const c = context(ContactRelationships, { entityId: 'me', entityType: 'contact' }, { $router: 'router' })
		expect([c.isEnded({}), c.isEnded({ endDate: '2026-05-01' }), c.isEnded({ endDate: '2026-06-01' })]).toEqual([false, true, false])
		c.navigateToEntity({ toType: 'client', toContact: 'c1' }, 'event')
		c.navigateToEntity({ toContact: 'p1' }, 'event')
		expect(openRowTarget.mock.calls.map((call) => call[1])).toEqual([{ name: 'ClientDetail', params: { id: 'c1' } }, { name: 'ContactDetail', params: { id: 'p1' } }])
		expect(openRowTarget.mock.calls[0][2]).toBe('router')
		c.onTypeSelect('werkgever')
		expect([c.addForm._inverse, c.addForm._category, c.addForm._symmetric]).toEqual(['werknemer', 'Professioneel', false])
		c.onTypeSelect('unknown')
		expect(c.addForm._inverse).toBe('werknemer')
	})

	it('searches contacts and clients after a pause, leaving out itself', async () => {
		vi.useFakeTimers()
		objectStore.fetchCollection.mockImplementation(async (type) => (type === 'contact' ? [{ id: 'me', name: 'Me' }, { id: 'p1', name: 'Piet' }] : [{ id: 'c1', name: 'Acme' }]))
		const c = context(ContactRelationships, { entityId: 'me', entityType: 'contact' })
		await c.searchEntities('p')
		expect(c.entityOptions).toEqual([])
		await c.searchEntities('pi')
		await c.searchEntities('pie')
		await vi.advanceTimersByTimeAsync(300)
		expect(objectStore.fetchCollection).toHaveBeenCalledTimes(2)
		expect(objectStore.fetchCollection.mock.calls[0]).toEqual(['contact', { _search: 'pie', _limit: 10 }])
		expect(c.entityOptions).toEqual([{ id: 'p1', name: 'Piet (contact)', entityType: 'contact' }, { id: 'c1', name: 'Acme (client)', entityType: 'client' }])
		objectStore.fetchCollection.mockRejectedValue(new Error('x'))
		await c.searchEntities('zz')
		await vi.advanceTimersByTimeAsync(300)
		expect(c.entityOptions).toEqual([])
	})

	it('saves both ends of a new relationship, refusing a duplicate', async () => {
		const c = context(ContactRelationships, { entityId: 'me', entityType: 'contact' })
		await c.saveRelationship()
		expect(objectStore.saveObject).not.toHaveBeenCalled()
		c.relationships = [rel()]
		c.addForm = { ...c.addForm, toContact: 'p1', type: 'partner' }
		await c.saveRelationship()
		expect(toasts).toEqual([['error', 'This relationship already exists']])
		c.entityOptions = [{ id: 'c1', entityType: 'client' }]
		c.addForm = { ...c.addForm, toContact: 'c1', type: 'werknemer', endDate: '2026-12-31' }
		objectStore.fetchCollection.mockResolvedValue([])
		await c.saveRelationship()
		expect(objectStore.saveObject.mock.calls.map((call) => call[1])).toEqual([
			{ fromContact: 'me', toContact: 'c1', fromType: 'contact', toType: 'client', type: 'werknemer', inverseType: 'werkgever', category: 'Professioneel', notes: '', startDate: null, endDate: '2026-12-31', strength: 'medium' },
			{ fromContact: 'c1', toContact: 'me', fromType: 'client', toType: 'contact', type: 'werkgever', inverseType: 'werknemer', category: 'Professioneel', notes: '', startDate: null, endDate: '2026-12-31', strength: 'medium' },
		])
		expect([toasts.at(-1), c.showAddDialog, c.addForm.toContact]).toEqual([['success', 'Relationship saved'], false, null])
	})

	it('edits a relationship together with its inverse, and reports a failed save', async () => {
		const c = context(ContactRelationships, { entityId: 'me', entityType: 'contact' })
		c.relationships = [rel({ notes: 'n', strength: 'strong' }), { id: 'inv', fromContact: 'p1', toContact: 'me' }]
		c.editRelationship(c.relationships[0])
		expect([c.showAddDialog, c.editingRelationship._inverseId, c.addForm]).toEqual([true, 'inv', { toContact: 'p1', type: 'partner', notes: 'n', startDate: '', endDate: '', strength: 'strong' }])
		expect(c.isDuplicateRelationship()).toBe(false)
		objectStore.fetchCollection.mockResolvedValue([])
		await c.saveRelationship()
		expect(objectStore.saveObject.mock.calls.map((call) => [call[1].id, call[1].type])).toEqual([['r1', 'partner'], ['inv', 'partner']])
		c.editRelationship(rel({ toContact: 'lonely' }))
		expect(c.editingRelationship._inverseId).toBeUndefined()
		expect(c.selectedEntityType()).toBe('contact')
		objectStore.saveObject.mockRejectedValueOnce(new Error('Not allowed'))
		await c.saveRelationship()
		objectStore.saveObject.mockRejectedValueOnce({})
		await c.saveRelationship()
		expect(toasts.slice(-2)).toEqual([['error', 'Not allowed'], ['error', 'Failed to save relationship']])
	})

	it('removes a relationship and every inverse of it', async () => {
		const c = context(ContactRelationships, { entityId: 'me', entityType: 'contact' })
		await c.confirmRemove()
		expect(objectStore.deleteObject).not.toHaveBeenCalled()
		c.removeRelationship(rel())
		expect(c.showDeleteDialog).toBe(true)
		objectStore.fetchCollection.mockResolvedValueOnce([{ id: 'inv1' }, { id: 'inv2' }]).mockResolvedValue([])
		await c.confirmRemove()
		expect(objectStore.fetchCollection.mock.calls[0]).toEqual(['relationship', { _limit: 10, fromContact: 'p1', toContact: 'me' }])
		expect(objectStore.deleteObject.mock.calls.map((call) => call[1])).toEqual(['r1', 'inv1', 'inv2'])
		expect([c.showDeleteDialog, c.deletingRelationship, toasts]).toEqual([false, null, [['success', 'Relationship removed']]])
		c.removeRelationship(rel())
		objectStore.deleteObject.mockRejectedValueOnce({})
		await c.confirmRemove()
		expect(toasts.at(-1)).toEqual(['error', 'Failed to remove relationship'])
	})
})

describe('ProspectsView', () => {
	const prospects = [
		{ kvkNumber: '1', tradeName: 'Bakker', fitScore: 90, employeeCount: 5, address: { city: 'Utrecht', street: 'Dorp 1' }, registrationDate: '2026-05-14' },
		{ kvkNumber: '2', tradeName: 'Acme', fitScore: 40, employeeCount: 50, address: { city: 'Amsterdam' }, registrationDate: '2025-01-01' },
		{ kvkNumber: '3', tradeName: 'Ceder', fitScore: 75, address: { city: 'Utrecht' } },
	]
	const view = () => context(ProspectsView, {}, { prospectStore })

	beforeEach(() => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00'), toFake: ['Date'] })
		Object.assign(prospectStore, { prospects: prospects.map((p) => ({ ...p })), error: '', cachedAt: null, icp: null })
	})

	it('sorts best first, and by any column either way', () => {
		const v = view()
		expect(v.sortedProspects.map((p) => p.kvkNumber)).toEqual(['1', '3', '2'])
		v.onSort({ keys: [{ key: 'tradeName', order: 'asc' }] })
		expect(v.sortedProspects.map((p) => p.tradeName)).toEqual(['Acme', 'Bakker', 'Ceder'])
		v.onSort({ key: 'employeeCount', order: 'desc' })
		expect(v.sortedProspects.map((p) => p.kvkNumber)).toEqual(['2', '1', '3'])
		v.onSort({})
		expect(v.sortKey).toBe('employeeCount')
		expect(v.columns.find((c) => c.key === 'address.city').enum).toEqual(['Amsterdam', 'Utrecht'])
	})

	it('counts and narrows by lens, search and filter, back to page one', () => {
		const v = view()
		expect(v.lensCounts).toEqual([3, 2, 1])
		v.page = 3
		v.selectLens(1)
		expect([v.page, v.sortedProspects.map((p) => p.kvkNumber)]).toEqual([1, ['1', '3']])
		v.selectLens(null)
		expect(v.lens).toBe(0)
		v.onFilterChange({ key: 'address.city', values: ['Amsterdam'] })
		expect(v.sortedProspects.map((p) => p.kvkNumber)).toEqual(['2'])
		v.onFilterChange({ key: 'address.city', values: [] })
		v.onFilterChange(null)
		expect(v.activeFilters).toEqual({})
		v.onSearch(42)
		expect(v.search).toBe('')
	})

	it('pages the list, never past the last page', () => {
		prospectStore.prospects = Array.from({ length: 45 }, (_, i) => ({ kvkNumber: String(i), fitScore: i }))
		const v = view()
		expect([v.totalPages, v.pagedProspects.length]).toEqual([3, 20])
		v.page = 9
		expect([v.currentPage, v.pagedProspects.length, v.pagination]).toEqual([3, 5, { currentPage: 3, totalPages: 3, totalItems: 45, pageSize: 20 }])
		prospectStore.prospects = []
		expect(v.totalPages).toBe(1)
	})

	it('says how many fit and when they were refreshed', () => {
		const v = view()
		expect(v.headerLine).toBe('3 of 3 companies from the KVK register that fit your profile · best first')
		prospectStore.cachedAt = '2026-05-15T09:05:00'
		expect(v.headerLine).toMatch(/· refreshed today at 09:05/)
		prospectStore.cachedAt = '2026-05-01T09:05:00'
		expect(v.headerLine).toMatch(/· refreshed on .+$/)
		prospectStore.cachedAt = 'garbage'
		expect(v.headerLine.endsWith('best first')).toBe(true)
	})

	it('explains an empty list and grades the score', () => {
		const v = view()
		expect(v.emptyDescription).toBe('No companies currently match your Ideal Customer Profile.')
		prospectStore.error = 'No Ideal Customer Profile set'
		expect(v.emptyDescription).toBe('Set an ideal customer profile first.')
		prospectStore.error = 'KVK is down'
		expect(v.emptyDescription).toBe('KVK is down')
		expect([v.scoreClass(71), v.scoreClass(70), v.scoreClass(40), v.scoreClass(39), v.scoreClass(undefined)]).toEqual(['score--high', 'score--medium', 'score--medium', 'score--low', 'score--low'])
		expect(v.profileUrl).toBe('/index.php/settings/admin/pipelinq')
	})

	it('loads on mount, refreshes on request, and offers both in the header', () => {
		const v = view()
		ProspectsView.mounted.call(v)
		v.headerActions[0].handler()
		expect(prospectStore.fetchProspects.mock.calls).toEqual([[], [true]])
		expect(v.headerButtons.map((b) => b.action)).toEqual(['export', 'refresh-prospects'])
	})

	it('adds a prospect as a client with its address and KVK number', async () => {
		const v = view()
		createWithContact.mockResolvedValueOnce({ id: 'c1' })
		await v.addAsClient(prospects[0])
		expect(createWithContact).toHaveBeenCalledWith('client', { name: 'Bakker', type: 'organization', address: 'Dorp 1, Utrecht', notes: 'KVK: 1' })
		expect([prospectStore.removeProspect.mock.calls[0], toasts, v.addingKvk]).toEqual([['1'], [['success', 'Added Bakker as a client']], null])
		createWithContact.mockResolvedValueOnce({})
		await v.addAsClient({ sbiDescription: 'Bakery' })
		expect(createWithContact.mock.calls[1][1]).toEqual({ name: 'Unknown company', type: 'organization', address: '', notes: 'Bakery' })
		createWithContact.mockRejectedValueOnce({ response: { data: { error: 'Already a client' } } })
		await v.addAsClient(prospects[1])
		createWithContact.mockRejectedValueOnce(new Error('x'))
		await v.addAsClient(prospects[1])
		expect(toasts.slice(1).map((e) => e[1])).toEqual(['Could not add this prospect as a client', 'Already a client', 'Could not add this prospect as a client'])
	})
})

describe('ArticleContentSection', () => {
	const section = (props = {}, extra = {}) => context(ArticleContentSection, { article: null, articleId: '', cnSectionContext: null, ...props }, extra)

	it('renders the body, the status and its moves', () => {
		const s = section({ article: { id: 'a1', body: 'Hi', status: 'draft' } })
		expect([s.renderedBody, s.statusLabel, s.transitions.map((m) => m.id)]).toEqual(['<p>Hi</p>', 'Draft', ['submitForReview', 'publish']])
		const none = section()
		expect([none.renderedBody, none.statusLabel, none.transitions]).toEqual(['<p></p>', 'Unknown', []])
		expect([s.actionLabel('restore'), s.actionLabel('odd')]).toEqual(['Restore as draft', 'odd'])
	})

	it('builds the hero image link for a web URL, an app image and a file', () => {
		const hero = (heroImage) => section({ article: { heroImage } }).heroImageUrl
		expect([hero(''), hero('https://x/y.png'), hero('app:pipelinq/hero.svg'), hero('/Photos/a b.jpg')]).toEqual(['', 'https://x/y.png', '/resolved/app:pipelinq/hero.svg', '/index.php/core/preview.png?file=%2FPhotos%2Fa%20b.jpg&x=1200&y=630&a=1'])
	})

	it('finds the article id from the prop, the article or the page context', () => {
		expect(section({ articleId: 'p' }).effectiveId()).toBe('p')
		expect(section({ article: { uuid: 'u' } }).effectiveId()).toBe('u')
		expect(section({ cnSectionContext: { value: { articleId: 'ctx' } } }).effectiveId()).toBe('ctx')
		expect(section().effectiveId()).toBe('')
	})

	it('loads the article itself when none is passed, and says when it cannot', async () => {
		const s = section({ articleId: 'a 1' })
		http.get.mockResolvedValueOnce({ data: { article: { id: 'a 1', status: 'published' } } })
		ArticleContentSection.watch.articleId.handler.call(s)
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/articles/a 1')
		expect([s.effectiveArticle.status, s.loading]).toEqual(['published', false])
		http.get.mockRejectedValueOnce(new Error('x'))
		await s.load()
		expect(s.error).toBe('This article could not be loaded.')
		const given = section({ article: { id: 'g' } })
		ArticleContentSection.watch.articleId.handler.call(given)
		expect(http.get).toHaveBeenCalledTimes(2)
	})

	it('listens for page refreshes only while mounted and reloads on one from elsewhere', async () => {
		const s = section({ articleId: 'a1' })
		ArticleContentSection.mounted.call(s)
		ArticleContentSection.beforeUnmount.call(s)
		expect([bus.subscribed, bus.unsubscribed]).toEqual([['cn:page:refresh'], ['cn:page:refresh']])
		http.get.mockResolvedValue({ data: {} })
		s.onPageRefresh({ source: 'article-content' })
		expect(http.get).not.toHaveBeenCalled()
		const waitUntil = vi.fn()
		s.onPageRefresh({ source: 'other', waitUntil })
		expect([http.get.mock.calls.length, waitUntil.mock.calls.length]).toEqual([1, 1])
		section({ article: { id: 'x' } }).onPageRefresh({})
		section().onPageRefresh({})
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('runs each move through its own endpoint and refreshes the page', async () => {
		const s = section({ articleId: 'a1' })
		http.get.mockResolvedValue({ data: { article: { id: 'a1' } } })
		await s.runTransition({ id: 'publish', endpoint: 'publish' })
		await s.runTransition({ id: 'archive', endpoint: 'archive' })
		await s.runTransition({ id: 'returnToDraft', endpoint: 'transition' })
		expect([articleApi.publishArticle.mock.calls, articleApi.archiveArticle.mock.calls, articleApi.transitionArticle.mock.calls]).toEqual([[['a1']], [['a1']], [['a1', 'returnToDraft']]])
		expect([s.$emit.mock.calls.length, bus.emitted.at(-1), s.busy]).toEqual([3, ['cn:page:refresh', { source: 'article-content' }], false])
		articleApi.publishArticle.mockRejectedValueOnce({ response: { data: { error: 'Missing hero image' } } })
		await s.runTransition({ endpoint: 'publish' })
		expect(s.actionError).toBe('Missing hero image')
		articleApi.archiveArticle.mockRejectedValueOnce({})
		await s.runTransition({ endpoint: 'archive' })
		expect(s.actionError).toBe('That move could not be applied.')
		await section().runTransition({ endpoint: 'publish' })
		expect(articleApi.publishArticle).toHaveBeenCalledTimes(2)
	})

	it('closes the editor and refreshes after a save, without reloading a passed article', async () => {
		const s = section({ article: { id: 'a1' } })
		s.showEdit = true
		await s.onSaved()
		expect([s.showEdit, http.get.mock.calls.length, s.$emit.mock.calls[0]]).toEqual([false, 0, ['refresh']])
		s.heroFailed = true
		ArticleContentSection.watch.heroImageUrl.call(s)
		expect(s.heroFailed).toBe(false)
	})
})
