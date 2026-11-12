// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lead contact roles, the POS PIN sign-in, the agent performance report,
 * the contact social profile form, the communication history, a customer's
 * bookings and the portal requests page, each run against a plain component
 * context with their stores and services doubled.
 *
 * @spec openspec/specs/lead-management/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'), NcLoadingIcon: stub('NcLoadingIcon'), NcSelect: stub('NcSelect'), NcTextField: stub('NcTextField') }))
const openRowTarget = vi.fn()
vi.mock('@conduction/nextcloud-vue', () => ({ CnDataTable: stub('CnDataTable'), CnDetailCard: stub('CnDetailCard'), CnDialog: stub('CnDialog'), CnPagination: stub('CnPagination'), openRowTarget: (...args) => openRowTarget(...args) }))
vi.mock('../../src/dialogs/AddContactRoleDialog.vue', () => ({ default: stub('AddContactRoleDialog') }))
const objectStore = { fetchCollection: vi.fn(), fetchObject: vi.fn(), saveObject: vi.fn(), deleteObject: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => objectStore }))
const session = { openSession: vi.fn() }
vi.mock('../../src/store/modules/posSessionStore.js', () => ({ usePosSessionStore: () => session }))
const portalApi = vi.hoisted(() => ({ requests: vi.fn(), request: vi.fn(), replyRequest: vi.fn(), submitRequest: vi.fn() }))
vi.mock('../../src/portal/portalApi.js', () => ({ portalApi }))

const { default: LeadContactRoles } = await import('../../src/components/LeadContactRoles.vue')
const { default: PinLoginModal } = await import('../../src/modals/PinLoginModal.vue')
const { default: AgentPerformanceSection } = await import('../../src/components/rapportage/AgentPerformanceSection.vue')
const { default: ContactSocialProfileModal } = await import('../../src/modals/ContactSocialProfileModal.vue')
const { default: CommunicationHistory } = await import('../../src/components/CommunicationHistory.vue')
const { default: BookingsCard } = await import('../../src/components/bookings/BookingsCard.vue')
const { default: PortalRequests } = await import('../../src/portal/views/PortalRequests.vue')

/**
 * A component context: props and extras, methods (mixins first) bound,
 * data, and computed values as getters.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), $router: 'router', t: globalThis.t, ...props, ...extra }
	const methods = Object.assign({}, ...(comp.mixins || []).map((m) => m.methods || {}), comp.methods || {})
	for (const [name, fn] of Object.entries(methods)) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

const tick = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(() => {
	toasts.length = 0
	openRowTarget.mockReset()
	session.openSession.mockReset()
	for (const fn of [http.get, http.post, ...Object.values(objectStore), ...Object.values(portalApi)]) {
		fn.mockReset()
	}
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
})

describe('LeadContactRoles', () => {
	const roles = () => context(LeadContactRoles, { leadId: 'l1' })

	it('loads the CRM roles of the lead in board order and names each contact', async () => {
		objectStore.fetchCollection.mockResolvedValueOnce([{ id: 'r1', type: 'gebruiker', toContact: 'p1' }, { id: 'r2', type: 'beslisser', toContact: 'p2' }, { id: 'r3', type: 'odd' }])
		objectStore.fetchObject.mockImplementation(async (type, id) => ({ name: `Name ${id}` }))
		const r = roles()
		await LeadContactRoles.mounted.call(r)
		await tick()
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('relationship', { _limit: 50, fromContact: 'l1', category: 'CRM Rol' })
		expect(r.sortedContactRoles.map((role) => role.id)).toEqual(['r2', 'r1', 'r3'])
		expect([r.getEntityName('p1'), r.getEntityName('zz'), r.getEntityName(''), r.getRoleLabel('kampioen'), r.getRoleLabel('odd')]).toEqual(['Name p1', 'zz', '-', 'Champion', 'odd'])
		expect(r.roleOptions).toHaveLength(6)
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		await r.fetchRoles()
		expect([r.contactRoles, r.loading]).toEqual([[], false])
	})

	it('searches contacts after a pause from two characters', async () => {
		vi.useFakeTimers()
		objectStore.fetchCollection.mockResolvedValue([{ id: 'p1', name: 'Piet' }, { id: 'p2' }])
		const r = roles()
		await r.searchContacts('p')
		await r.searchContacts('pi')
		await r.searchContacts('pie')
		await vi.advanceTimersByTimeAsync(300)
		expect([objectStore.fetchCollection.mock.calls, r.contactOptions]).toEqual([[['contact', { _search: 'pie', _limit: 10 }]], [{ id: 'p1', name: 'Piet' }, { id: 'p2', name: 'p2' }]])
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		await r.searchContacts('zz')
		await vi.advanceTimersByTimeAsync(300)
		expect(r.contactOptions).toEqual([])
	})

	it('adds a role as a CRM relationship from the lead', async () => {
		const r = roles()
		await r.addRole(null)
		await r.addRole({ toContact: 'p1' })
		expect(objectStore.saveObject).not.toHaveBeenCalled()
		objectStore.fetchCollection.mockResolvedValue([])
		r.showAddDialog = true
		await r.addRole({ toContact: 'p1', type: 'kampioen' })
		expect(objectStore.saveObject).toHaveBeenCalledWith('relationship', { fromContact: 'l1', toContact: 'p1', fromType: 'lead', toType: 'contact', type: 'kampioen', inverseType: 'kampioen', category: 'CRM Rol', notes: '', strength: 'medium' })
		expect([toasts, r.showAddDialog]).toEqual([[['success', 'Contact role added']], false])
		objectStore.saveObject.mockRejectedValueOnce({})
		await r.addRole({ toContact: 'p1', type: 'kampioen' })
		expect(toasts.at(-1)).toEqual(['error', 'Failed to add contact role'])
	})

	it('removes a role only after confirmation', async () => {
		const r = roles()
		vi.stubGlobal('confirm', vi.fn(() => false))
		await r.removeRole({ id: 'r1' })
		expect(objectStore.deleteObject).not.toHaveBeenCalled()
		vi.stubGlobal('confirm', vi.fn(() => true))
		objectStore.fetchCollection.mockResolvedValue([])
		await r.removeRole({ id: 'r1' })
		expect([objectStore.deleteObject.mock.calls[0], toasts[0]]).toEqual([['relationship', 'r1'], ['success', 'Contact role removed']])
		objectStore.deleteObject.mockRejectedValueOnce(new Error('Locked'))
		await r.removeRole({ id: 'r1' })
		expect(toasts.at(-1)).toEqual(['error', 'Locked'])
	})
})

describe('PinLoginModal', () => {
	const modal = () => context(PinLoginModal)

	it('lists the active staff and builds the PIN by key', async () => {
		const m = modal()
		http.get.mockResolvedValueOnce({ data: { staff: [{ id: 's1', displayName: 'Kim' }, { id: 's2', isActive: false }, { id: 's3' }] } })
		await PinLoginModal.mounted.call(m)
		expect([http.get.mock.calls[0][0], m.staffOptions]).toEqual(['/index.php/apps/pipelinq/api/pos/staff', [{ value: 's1', label: 'Kim' }, { value: 's3', label: 's3' }]])
		for (const digit of '1234567') {
			m.press(digit)
		}
		expect(m.pin).toBe('123456')
		m.backspace()
		expect(m.pin).toBe('12345')
		m.errorMessage = 'x'
		m.clearPin()
		expect([m.pin, m.errorMessage]).toEqual(['', ''])
		http.get.mockRejectedValueOnce(new Error('x'))
		await m.loadStaff()
		expect([m.staff, m.loading]).toEqual([[], false])
	})

	it('signs in with four to six digits only', () => {
		const m = modal()
		m.selectedStaffId = 's1'
		const can = (pin) => {
			m.pin = pin
			return m.canSubmit
		}
		expect([can('123'), can('1234'), can('123456'), can('1234567'), can('12a4')]).toEqual([false, true, true, false, false])
		m.selectedStaffId = ''
		expect(can('1234')).toBe(false)
	})

	it('opens the staff session and closes on success', async () => {
		const m = modal()
		Object.assign(m, { selectedStaffId: 's1', pin: '1234' })
		http.post.mockResolvedValueOnce({ data: { session: { staffId: 's1', permissions: {} } } })
		await m.submit()
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos/staff/auth', { staffId: 's1', pin: '1234' })
		expect([session.openSession.mock.calls[0][0].staffId, m.$emit.mock.calls.map((c) => c[0]), m.submitting]).toEqual(['s1', ['loginSuccess', 'close'], false])
	})

	it('tells a wrong PIN from a locked account and other failures', async () => {
		const m = modal()
		const attempt = async (failure) => {
			Object.assign(m, { selectedStaffId: 's1', pin: '1234' })
			if (failure) {
				http.post.mockRejectedValueOnce(failure)
			} else {
				http.post.mockResolvedValueOnce({ data: {} })
			}
			await m.submit()
			return [m.errorMessage, m.lockedUntil, m.pin]
		}
		expect(await attempt({ response: { status: 403, data: { error: 'Account locked until 12:30' } } })).toEqual(['', 'Account locked until 12:30', ''])
		expect(await attempt({ response: { status: 403, data: { error: 'Geblokkeerd tot 12:30' } } })).toEqual(['', 'Geblokkeerd tot 12:30', ''])
		expect(await attempt({ response: { status: 403, data: {} } })).toEqual(['Incorrect PIN', '', ''])
		expect(await attempt({ response: { status: 500, data: { error: 'Down' } } })).toEqual(['Down', '', ''])
		expect(await attempt(null)).toEqual(['An unexpected error occurred', '', ''])
		expect(session.openSession).not.toHaveBeenCalled()
		m.pin = ''
		await m.submit()
		expect(http.post).toHaveBeenCalledTimes(5)
	})
})

describe('AgentPerformanceSection', () => {
	const section = (props = {}, ctx = null) => context(AgentPerformanceSection, { period: 'today', cnSectionContext: ctx, ...props })

	it('reads the period from the prop or the report workspace', () => {
		expect([section().effectivePeriod, section({ period: '' }, { workspace: { period: 'week' } }).effectivePeriod, section({ period: '' }).effectivePeriod]).toEqual(['today', 'week', 'today'])
	})

	it('loads the agents with at least one contact', async () => {
		const s = section({ period: 'month' })
		http.get.mockResolvedValueOnce({ data: { agents: { ann: { count: 5, fcrRate: 80, avgHandlingTime: '3:10' }, bob: { count: 0 }, eve: { count: 2 } } } })
		await s.fetchData()
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/rapportage/agents', { params: { period: 'month' } })
		expect(s.agents).toEqual([{ id: 'ann', count: 5, fcrRate: 80, avgHandlingTime: '3:10' }, { id: 'eve', count: 2, fcrRate: 0, avgHandlingTime: '0:00' }])
		expect(s.teamStats).toEqual({ totalContacts: 7, avgFcr: 40 })
		http.get.mockResolvedValueOnce({ data: {} })
		await s.fetchData()
		expect([s.agents, s.teamStats]).toEqual([[], { totalContacts: 0, avgFcr: 0 }])
		http.get.mockRejectedValueOnce(new Error('x'))
		await s.fetchData()
		expect([s.agents, s.loading]).toEqual([[], false])
	})

	it('sorts by a column, turning the direction on a second click', () => {
		const s = section()
		s.agents = [{ id: 'b', count: 1 }, { id: 'a', count: 3 }, { id: 'c', count: 2 }]
		expect(s.sortedAgents.map((a) => a.id)).toEqual(['a', 'c', 'b'])
		s.setSort('count')
		expect([s.sortDir, s.sortedAgents.map((a) => a.id)]).toEqual(['asc', ['b', 'c', 'a']])
		s.setSort('id')
		expect([s.sortDir, s.sortedAgents.map((a) => a.id)]).toEqual(['desc', ['c', 'b', 'a']])
		s.setSort('id')
		expect(s.sortedAgents.map((a) => a.id)).toEqual(['a', 'b', 'c'])
	})
})

describe('ContactSocialProfileModal', () => {
	it('starts on LinkedIn, or from the profile being edited', () => {
		const fresh = context(ContactSocialProfileModal, { profile: null })
		expect([fresh.isEdit, fresh.form]).toEqual([false, { network: 'linkedin', handle: '', url: '', verified: false, followedByUs: false, followsUs: false }])
		const edit = context(ContactSocialProfileModal, { profile: { network: 'mastodon', handle: '@a', verified: true } })
		expect([edit.isEdit, edit.form.network, edit.form.verified, edit.networkOptions.length]).toEqual([true, 'mastodon', true, 10])
	})

	it('needs a handle or a sound URL and saves trimmed values', () => {
		const m = context(ContactSocialProfileModal, { profile: null })
		m.submit()
		expect(m.error).toBe('A handle or a profile URL is required')
		m.form.url = 'linkedin.com/in/x'
		m.submit()
		expect([m.error, m.$emit.mock.calls.length]).toEqual(['Invalid URL format', 0])
		Object.assign(m.form, { url: ' https://linkedin.com/in/x ', handle: ' x ', followsUs: 1 })
		m.submit()
		expect(m.$emit.mock.calls[0]).toEqual(['save', { network: 'linkedin', handle: 'x', url: 'https://linkedin.com/in/x', verified: false, followedByUs: false, followsUs: true }])
		Object.assign(m.form, { url: '', handle: '@x' })
		m.submit()
		expect(m.$emit.mock.calls[1][1].url).toBe('')
	})
})

describe('CommunicationHistory', () => {
	const history = (props = {}) => context(CommunicationHistory, { entityType: 'client', entityId: 'c 1', ...props })

	it('loads a page of contact moments for the entity', async () => {
		const h = history()
		http.get.mockResolvedValueOnce({ data: { results: [{ id: 'm1' }], total: '12', pages: '2', page: '1' } })
		CommunicationHistory.mounted.call(h)
		await tick()
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/activity/client/c 1', { params: { type: 'contactmomenten', _page: 1, _limit: 10 } })
		expect([h.items, h.total, h.pages, h.page, h.loading]).toEqual([[{ id: 'm1' }], 12, 2, 1, false])
		http.get.mockResolvedValueOnce({ data: { results: 'x' } })
		h.onPageChanged('2')
		await tick()
		expect([http.get.mock.calls[1][1].params._page, h.items, h.total, h.pages, h.page]).toEqual([2, [], 0, 1, 2])
		expect(h.columns.map((c) => c.key)).toEqual(['channel', 'subject', 'agent', 'timestamp'])
	})

	it('ignores a bad page and starts over on refresh or a new entity', async () => {
		const h = history()
		http.get.mockResolvedValue({ data: {} })
		h.onPageChanged('x')
		h.onPageChanged(0)
		expect(http.get).not.toHaveBeenCalled()
		h.page = 3
		h.onRefresh()
		expect(h.page).toBe(1)
		h.page = 3
		CommunicationHistory.watch.entityId.call(h)
		h.page = 3
		CommunicationHistory.watch.entityType.call(h)
		expect([h.page, http.get.mock.calls.length]).toEqual([1, 3])
		await history({ entityId: '' }).fetchHistory()
		expect(http.get).toHaveBeenCalledTimes(3)
	})

	it('says when the history cannot be loaded', async () => {
		const h = history()
		Object.assign(h, { items: [{ id: 1 }], total: 4, pages: 3 })
		http.get.mockRejectedValueOnce(new Error('x'))
		await h.fetchHistory()
		expect([toasts, h.items, h.total, h.pages]).toEqual([[['error', 'Could not load communication history']], [], 0, 1])
	})

	it('opens a contact moment and formats channel and time', () => {
		const h = history()
		h.goToContactmoment(null, 'e')
		h.goToContactmoment({ id: 'm1' }, 'e')
		expect(openRowTarget.mock.calls).toEqual([['e', { name: 'TicketDetail', params: { id: 'm1' } }, 'router']])
		expect([h.formatChannel(''), h.formatChannel('email'), h.channelAriaLabel('Balie'), h.channelAriaLabel('fax'), h.channelAriaLabel(null)]).toEqual(['-', 'email', 'Counter', 'Channel', 'Channel'])
		expect([h.formatTimestamp(''), h.formatTimestamp('garbage'), h.formatTimestamp('2026-05-15T10:00:00Z'), h.rowClass()]).toEqual(['-', 'garbage', new Date('2026-05-15T10:00:00Z').toLocaleString(), 'communication-history__row'])
	})
})

describe('BookingsCard', () => {
	const card = (customerId = 'cust1') => context(BookingsCard, { customerId }, { objectStore })

	it('lists upcoming bookings first, soonest first, then past ones, latest first', () => {
		vi.useFakeTimers({ now: new Date('2026-05-15T12:00:00Z'), toFake: ['Date'] })
		const c = card()
		c.bookings = [{ id: 'past1', startAt: '2026-05-01T10:00:00Z' }, { id: 'next2', startAt: '2026-06-01T10:00:00Z' }, { id: 'past2', startAt: '2026-05-10T10:00:00Z' }, { id: 'next1', startAt: '2026-05-16T10:00:00Z' }, { id: 'undated' }]
		expect(c.sortedBookings.map((b) => b.id)).toEqual(['next1', 'next2', 'past2', 'past1', 'undated'])
	})

	it('loads the bookings and names their services and first resources once', async () => {
		objectStore.fetchCollection.mockResolvedValueOnce([
			{ id: 'b1', serviceId: 's1', resourceAssignments: [{ resourceId: 'r1' }, { resourceId: 'r2' }] },
			{ id: 'b2', serviceId: 's1', resourceAssignments: [{}] },
			{ id: 'b3', serviceId: 's2' },
		])
		objectStore.fetchObject.mockImplementation(async (type, id) => {
			if (id === 's2') {
				throw new Error('gone')
			}
			return id === 'r2' ? {} : { name: `${type}:${id}` }
		})
		const c = card()
		BookingsCard.watch.customerId.handler.call(c, 'cust1')
		await tick()
		await tick()
		expect(objectStore.fetchCollection).toHaveBeenCalledWith('appointmentBooking', { customerId: 'cust1', _limit: 200 })
		expect(objectStore.fetchObject.mock.calls.map((call) => call[1])).toEqual(['s1', 's2', 'r1', 'r2'])
		const [b1, b2, b3] = c.bookings
		expect([c.serviceLabel(b1), c.serviceLabel(b3), c.serviceLabel({}), c.resourceLabel(b1), c.resourceLabel(b2), c.resourceLabel({ resourceAssignments: [{ resourceId: 'r2' }] })]).toEqual(['appointmentService:s1', 's2', '-', 'appointmentResource:r1', '-', 'r2'])
		await c.primeLabels()
		expect(objectStore.fetchObject).toHaveBeenCalledTimes(6)
	})

	it('says when the bookings cannot be loaded, and loads nothing without a customer', async () => {
		const c = card()
		objectStore.fetchCollection.mockRejectedValueOnce(new Error('x'))
		await c.fetchBookings()
		expect([c.bookings, c.error, c.loading]).toEqual([[], 'Failed to load bookings for this customer.', false])
		BookingsCard.watch.customerId.handler.call(card(''), '')
		expect(objectStore.fetchCollection).toHaveBeenCalledTimes(1)
		objectStore.fetchCollection.mockResolvedValueOnce('odd')
		await c.fetchBookings()
		expect([c.bookings, c.error]).toEqual([[], ''])
	})

	it('opens a booking and labels its status', () => {
		const c = card()
		c.open({ id: 'b1' }, 'e')
		expect(openRowTarget).toHaveBeenCalledWith('e', { name: 'BookingDetail', params: { id: 'b1' } }, 'router')
		expect([c.statusLabel('odd'), c.statusLabel(''), typeof c.formatDateTime('2026-05-15T10:00:00Z')]).toEqual(['odd', '-', 'string'])
	})
})

describe('PortalRequests', () => {
	const page = () => context(PortalRequests)

	it('loads the requests and opens one', async () => {
		const p = page()
		portalApi.requests.mockResolvedValueOnce({ items: [{ id: 'q1' }] })
		PortalRequests.mounted.call(p)
		await tick()
		expect(p.rows).toEqual([{ id: 'q1' }])
		portalApi.request.mockResolvedValueOnce({ id: 'q1', body: 'Hi' })
		await p.open('q1')
		expect(p.detail).toEqual({ id: 'q1', body: 'Hi' })
		portalApi.request.mockRejectedValueOnce(new Error('Not yours'))
		await p.open('q2')
		expect(p.error).toBe('Not yours')
		portalApi.requests.mockResolvedValueOnce({})
		await p.load()
		expect([p.rows, p.error]).toEqual([[], ''])
		portalApi.requests.mockRejectedValueOnce({})
		await p.load()
		expect(p.error).toBe('Could not load requests.')
	})

	it('replies to the open request and reloads the list', async () => {
		const p = page()
		p.detail = { id: 'q1' }
		p.replyText = 'Thanks'
		portalApi.replyRequest.mockResolvedValueOnce({ id: 'q1', replies: 1 })
		portalApi.requests.mockResolvedValue({ items: [] })
		await p.sendReply()
		expect([portalApi.replyRequest.mock.calls[0], p.detail, p.replyText, portalApi.requests.mock.calls.length]).toEqual([['q1', 'Thanks'], { id: 'q1', replies: 1 }, '', 1])
		portalApi.replyRequest.mockRejectedValueOnce(new Error('Closed'))
		p.replyText = 'Again'
		await p.sendReply()
		expect([p.error, p.replyText]).toEqual(['Closed', 'Again'])
	})

	it('submits a new request and explains a refusal', async () => {
		const p = page()
		Object.assign(p.form, { subject: 'Bin', body: 'Not emptied', categoryId: 'waste' })
		portalApi.submitRequest.mockResolvedValueOnce({ requestId: 'Q-9', estimatedResponseTime: '2 days' })
		portalApi.requests.mockResolvedValue({ items: [] })
		await p.submit()
		expect(portalApi.submitRequest).toHaveBeenCalledWith({ subject: 'Bin', body: 'Not emptied', categoryId: 'waste', attachments: [] })
		expect([p.submitMessage, p.form, p.submitting]).toEqual(['Your request has been submitted (Q-9). Expected response: 2 days.', { subject: '', body: '', categoryId: 'waste' }, false])
		portalApi.submitRequest.mockRejectedValueOnce({ errorCode: 'rateLimited', message: 'x' })
		await p.submit()
		expect(p.error).toBe('Please wait 60 minutes before submitting another request.')
		portalApi.submitRequest.mockRejectedValueOnce({ message: 'Too long' })
		await p.submit()
		portalApi.submitRequest.mockRejectedValueOnce({})
		const q = page()
		await q.submit()
		expect([p.error, q.error, q.submitMessage]).toEqual(['Too long', 'Could not submit the request.', ''])
	})
})
