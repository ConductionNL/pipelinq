// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The public booking confirmation page (status, deposit, price, a missing
 * booking) and linking a web form submission to a client (the suggested
 * parties, what may be linked, and the link itself with or without a lead),
 * each run against a plain component context.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: () => ({ currency: 'EUR' }) }))
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (m) => toasts.push(['success', m]),
	showWarning: (m) => toasts.push(['warning', m]),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'), NcLoadingIcon: stub('NcLoadingIcon'), NcNoteCard: stub('NcNoteCard') }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog'), CnPartyPicker: stub('CnPartyPicker') }))
vi.mock('../../src/services/bookingPortalApi.js', () => ({ fetchBooking: vi.fn() }))
vi.mock('../../src/services/contactSyncApi.js', () => ({ createWithContact: vi.fn() }))

const { default: BookingConfirmationPage } = await import('../../src/views/portal/BookingConfirmationPage.vue')
const linkModule = await import('../../src/modals/LinkSubmissionToClientModal.vue')
const LinkModal = linkModule.default
const { fetchBooking } = await import('../../src/services/bookingPortalApi.js')
const { createWithContact } = await import('../../src/services/contactSyncApi.js')

/**
 * A component context: props and extras, methods bound, data, and computed
 * values as getters.
 *
 * @param {object} comp The component definition.
 * @param {object} [props] Its props.
 * @param {object} [extra] Anything else on `this`.
 * @return {object} The context.
 */
function context(comp, props = {}, extra = {}) {
	const ctx = { $emit: vi.fn(), t: globalThis.t, ...props, ...extra }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

beforeEach(() => {
	toasts.length = 0
	for (const fn of [http.get, http.post, http.patch, fetchBooking, createWithContact]) {
		fn.mockReset()
	}
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('BookingConfirmationPage', () => {
	const page = (bookingId = 'b1') => context(BookingConfirmationPage, {}, { $route: { params: { bookingId } } })

	it('loads the booking from the route and labels it', async () => {
		const p = page('b 1')
		fetchBooking.mockResolvedValueOnce({ status: 'pending', paymentStatus: 'pending', depositRequired: true, email: 'a@b.nl', price: '12.5' })
		BookingConfirmationPage.mounted.call(p)
		await new Promise((resolve) => setTimeout(resolve, 0))
		expect(fetchBooking).toHaveBeenCalledWith('b 1')
		expect([p.statusLabel, p.paymentStatusLabel, p.depositPending, p.hasPrice, p.emailNotice, p.loading]).toEqual(['Pending', 'Payment pending', true, true, 'A confirmation email has been sent to a@b.nl.', false])
		expect(p.priceLabel).toMatch(/12[.,]50/)
	})

	it('asks for no deposit once paid or confirmed, and keeps unknown statuses', () => {
		const p = page()
		expect([p.depositPending, p.hasPrice, p.statusLabel, p.emailNotice]).toEqual([false, null, '', 'A confirmation email has been sent to .'])
		p.booking = { depositRequired: true, paymentStatus: 'paid', status: 'odd' }
		expect([p.depositPending, p.statusLabel, p.paymentStatusLabel]).toEqual([false, 'odd', 'Paid'])
		p.booking = { depositRequired: true, status: 'confirmed', paymentStatus: 'failed', price: 0 }
		expect([p.depositPending, p.paymentStatusLabel, p.hasPrice]).toEqual([false, 'Payment failed', false])
		expect(context(BookingConfirmationPage, {}, { $route: null }).bookingId).toBe('')
	})

	it('tells a missing booking from another failure', async () => {
		const p = page()
		fetchBooking.mockRejectedValueOnce({ response: { status: 404 } })
		await p.load()
		expect(p.error).toBe('This booking could not be found.')
		fetchBooking.mockRejectedValueOnce({ status: 404 })
		await p.load()
		expect(p.error).toBe('This booking could not be found.')
		fetchBooking.mockRejectedValueOnce(new Error('x'))
		await p.load()
		expect([p.error, p.loading]).toEqual(['Something went wrong. Please try again.', false])
	})

	it('writes a date out in full, keeping one it cannot read', () => {
		const p = page()
		expect(p.formatDateTime('garbage')).toBe('garbage')
		expect(p.formatDateTime('2026-05-15T10:00:00Z')).toMatch(/2026/)
	})
})

describe('LinkSubmissionToClientModal', () => {
	const ticket = { reporterName: 'Jan', reporterEmail: 'JAN@ACME.NL ', reporterPhone: '0612345678', reporterOrganisation: 'Acme', sourceUrl: 'https://acme.nl/contact/', description: 'Call me', title: 'Question' }
	const modal = () => context(LinkModal, { ticketId: 't 1' })
	const answer = (data) => ({ data })

	it('shows a page link without its scheme or trailing slash', () => {
		expect([linkModule.readablePage('https://acme.nl/contact/'), linkModule.readablePage('http://acme.nl/'), linkModule.readablePage('/'), linkModule.readablePage(null)]).toEqual(['acme.nl/contact', 'acme.nl', '/', ''])
	})

	it('loads the ticket and suggests the client and contact it matches', async () => {
		http.get.mockImplementation(async (url) => {
			if (url.endsWith('/ticket/t%201')) {
				return answer(ticket)
			}
			if (url.endsWith('/client')) {
				return answer({ results: [{ '@self': { id: 7 }, name: 'Acme BV' }] })
			}
			return answer({ results: [{ id: 'p1', name: 'Jan' }] })
		})
		const m = modal()
		await LinkModal.created.call(m)
		expect(http.get.mock.calls.slice(1).map((c) => c[1].params)).toEqual([{ name: 'Acme', _limit: 1 }, { email: 'jan@acme.nl', _limit: 1 }])
		expect([m.loading, m.clientMode, m.clientId, m.contactMode, m.contactId, m.suggestedClient]).toEqual([false, 'existing', '7', 'existing', 'p1', { id: '7', name: 'Acme BV' }])
		expect(m.sentRows).toEqual([
			{ label: 'Name', value: 'Jan', wide: false },
			{ label: 'Email', value: 'jan@acme.nl', wide: false },
			{ label: 'Phone', value: '0612345678', wide: false },
			{ label: 'Organisation', value: 'Acme', wide: false },
			{ label: 'Page', value: 'acme.nl/contact', wide: false },
			{ label: 'Message', value: 'Call me', wide: true },
		])
		expect([m.plannedClientName, m.plannedContactName, m.clientSources[0].schema, m.contactSources[0].schema, m.canLink]).toEqual(['Acme', 'Jan', 'client', 'contact', true])
	})

	it('suggests nothing it cannot find, and shows nothing for a missing ticket', async () => {
		http.get.mockImplementation(async (url) => {
			if (url.includes('/ticket/')) {
				return answer({ reporterEmail: 'x@y.nl' })
			}
			throw new Error('down')
		})
		const m = modal()
		await m.load()
		expect([m.clientMode, m.contactMode, m.suggestedContact, m.plannedClientName, m.plannedContactName]).toEqual(['new', 'new', null, 'x@y.nl', 'x@y.nl'])
		http.get.mockRejectedValue(new Error('gone'))
		const none = modal()
		await none.load()
		expect([none.ticket, none.loading, none.canLink]).toEqual([null, false, false])
	})

	it('allows a link only with a client and a contact to link to', () => {
		const m = modal()
		m.ticket = {}
		expect(m.canLink).toBe(false)
		m.ticket = { reporterName: 'Jan' }
		expect(m.canLink).toBe(true)
		m.clientMode = 'existing'
		expect(m.canLink).toBe(false)
		m.clientId = 'c1'
		m.contactMode = 'existing'
		expect(m.canLink).toBe(false)
		m.contactId = 'p1'
		expect(m.canLink).toBe(true)
	})

	it('links new parties and a lead on the default pipeline, then reloads', async () => {
		const reload = vi.fn()
		vi.stubGlobal('location', { reload })
		createWithContact.mockImplementation(async (type) => ({ id: type === 'client' ? 'c9' : 'p9' }))
		http.get.mockResolvedValue(answer({ results: [{ id: 'pipe1' }] }))
		http.post.mockResolvedValue(answer({ id: 'lead1' }))
		const m = modal()
		Object.assign(m, { ticket, createLead: true })
		await m.link()
		expect(createWithContact.mock.calls).toEqual([['client', { name: 'Acme', type: 'organization', email: 'jan@acme.nl' }], ['contact', { name: 'Jan', email: 'jan@acme.nl', phone: '0612345678' }]])
		expect(http.patch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/pipelinq/ticket/t%201', { client: 'c9', contact: 'p9' })
		expect(http.post.mock.calls[0]).toEqual(['/index.php/apps/openregister/api/objects/pipelinq/lead', { title: 'Question', description: 'Call me', source: 'website', client: 'c9', contact: 'p9', pipeline: 'pipe1' }])
		expect([toasts, m.$emit.mock.calls[0], reload.mock.calls.length, m.linking]).toEqual([[['success', 'The submission is linked to the client.']], ['close'], 1, false])
	})

	it('links without a lead when there is no default pipeline, and says so', async () => {
		vi.stubGlobal('location', { reload: vi.fn() })
		http.get.mockResolvedValue(answer({ results: [] }))
		const m = modal()
		Object.assign(m, { ticket, createLead: true, clientMode: 'existing', clientId: 'c1', contactMode: 'existing', contactId: 'p1' })
		await m.link()
		expect([createWithContact.mock.calls.length, http.post.mock.calls.length, http.patch.mock.calls[0][1], toasts]).toEqual([0, 0, { client: 'c1', contact: 'p1' }, [['warning', 'Linked. No lead was made: there is no default pipeline.']]])
	})

	it('changes nothing and explains a failed link', async () => {
		const m = modal()
		Object.assign(m, { ticket, clientMode: 'existing', clientId: null })
		await m.link()
		expect(m.error).toBe('No existing client chosen.')
		Object.assign(m, { clientMode: 'new' })
		createWithContact.mockRejectedValueOnce({ response: { data: { message: 'Duplicate client' } } })
		await m.link()
		expect(m.error).toBe('Duplicate client')
		createWithContact.mockRejectedValueOnce({})
		await m.link()
		expect([m.error, m.linking, http.patch.mock.calls.length, m.$emit.mock.calls.length]).toEqual(['Linking failed; nothing was changed on the ticket.', false, 0, 0])
	})
})
