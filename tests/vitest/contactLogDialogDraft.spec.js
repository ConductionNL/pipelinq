// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTicketContactLoggen (#846): the ticket's contact dialog keeps what
 * the agent types as the same private draft as the client page's quick log,
 * and offers it again with Restore draft and Discard.
 *
 * @spec openspec/changes/contact-log-dialog-keeps-draft/specs/contact-log-dialog/spec.md#requirement-the-tickets-contact-dialog-keeps-a-draft
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { isDraftFormEmpty, pickDraft } from '../../src/services/contactMomentDraft.js'

const store = vi.hoisted(() => ({
	fetchObject: vi.fn(),
	saveObject: vi.fn(),
	deleteObject: vi.fn(() => Promise.resolve(true)),
	fetchCollection: vi.fn(),
	fetchCollectionForOptions: vi.fn(() => Promise.resolve([
		{ id: 'c-1', name: 'Sanne de Vries' },
		{ id: 'c-2', name: 'Bakkerij De Graaf' },
	])),
	getError: vi.fn(() => null),
}))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled', 'variant'], emits: ['click'], render() { return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()]) } },
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue', 'search'],
		render() { return h('div', { class: 'nc-select-stub' }, [this.modelValue ? this.$slots['selected-option']?.(this.modelValue) : null]) },
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { props: ['name', 'eyebrow', 'subtitle', 'width'], render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))

const { default: Dialog } = await import('../../src/dialogs/ContactLogDialog.vue')

const recent = () => new Date(Date.now() - 60 * 1000).toISOString()

/**
 * A draft contact moment of the agent's own.
 *
 * @param {object} fields The draft's fields.
 * @return {object} The draft ticket.
 */
function draft(fields) {
	return {
		id: 'd-1',
		ticketType: 'interaction',
		parentTicket: 't-1',
		'@self': { status: 'draft', owner: 'sanne', updated: recent() },
		...fields,
	}
}

/**
 * Mount the dialog on ticket t-1.
 *
 * @return {Promise<object>} The wrapper.
 */
async function open() {
	const w = mount(Dialog, { global: { mocks: { $route: { params: { id: 't-1' } } } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	window.OC = { getCurrentUser: () => ({ uid: 'sanne' }), requestToken: 'tok' }
	store.fetchObject.mockImplementation((type, id) => Promise.resolve(type === 'ticket'
		? { id: 't-1', reference: 'PQ-2026-0417', title: 'Vraag over vergunning', client: 'c-1' }
		: { id, name: id === 'c-2' ? 'Bakkerij De Graaf' : 'Sanne de Vries' }))
	store.fetchCollection.mockResolvedValue([])
	store.saveObject.mockReset()
	store.deleteObject.mockClear()
	globalThis.fetch = vi.fn()
})

afterEach(() => {
	vi.useRealTimers()
	delete window.OC
})

describe('contact moment draft helpers for the dialog', () => {
	it('does not count a preset channel or direction as a draft', () => {
		const defaults = { channel: 'telefoon', direction: 'inbound' }
		expect(isDraftFormEmpty({ channel: 'telefoon', direction: 'inbound', client: 'c-1', parentTicket: 't-1' }, { clientId: 'c-1', requestId: 't-1', defaults })).toBe(true)
		expect(isDraftFormEmpty({ channel: 'email', direction: 'inbound' }, { defaults })).toBe(false)
		expect(isDraftFormEmpty({ channel: 'telefoon', direction: 'outbound' }, { defaults })).toBe(false)
	})

	it('matches a draft on its request alone when no client is asked for', () => {
		const context = { author: 'sanne', requestId: 't-1', now: new Date() }
		expect(pickDraft([draft({ client: 'c-2' })], context)?.id).toBe('d-1')
		expect(pickDraft([draft({ client: 'c-2' })], { ...context, clientId: 'c-1' })).toBe(null)
		expect(pickDraft([draft({ parentTicket: 't-9' })], context)).toBe(null)
	})
})

describe('ContactLogDialog draft', () => {
	it('asks OpenRegister for its own draft contact moments', async () => {
		await open()
		expect(store.fetchCollection).toHaveBeenCalledWith('ticket', expect.objectContaining({ ticketType: 'interaction', _drafts: 'true', '@self[owner]': 'sanne' }))
	})

	it('offers the draft on its ticket, restores it, and Save finishes the same object', async () => {
		store.fetchCollection.mockResolvedValue([draft({ title: 'Vergunning straatfeest', notes: 'Belt terug', channel: 'email', client: 'c-2' })])
		globalThis.fetch.mockResolvedValue({ ok: true, json: () => Promise.resolve({ id: 'd-1' }) })
		const w = await open()

		const offer = w.find('[data-testid="contact-log-draft-offer"]')
		expect(offer.exists()).toBe(true)
		expect(offer.text()).toContain('You were already working on this.')
		expect(offer.text()).toContain('Restore draft')
		expect(offer.text()).toContain('Discard')

		await w.find('[data-testid="contact-log-draft-restore"]').trigger('click')
		await flushPromises()
		expect(w.find('[data-testid="contact-log-draft-offer"]').exists()).toBe(false)
		expect(w.find('[data-testid="contact-log-subject"]').element.value).toBe('Vergunning straatfeest')
		expect(w.find('[data-testid="contact-log-notes"]').element.value).toBe('Belt terug')
		expect(w.vm.client).toEqual({ id: 'c-2', label: 'Bakkerij De Graaf' })

		await w.find('[data-testid="contact-log-save"]').trigger('click')
		await flushPromises()
		expect(globalThis.fetch).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/pipelinq/ticket/d-1/activate', expect.objectContaining({ method: 'POST' }))
		const sent = JSON.parse(globalThis.fetch.mock.calls[0][1].body).object
		expect(sent).toMatchObject({ ticketType: 'interaction', parentTicket: 't-1', client: 'c-2', title: 'Vergunning straatfeest', channel: 'email' })
		expect(store.saveObject).not.toHaveBeenCalled()
		expect(w.emitted('close')).toBeTruthy()
	})

	it('discards the draft ticket and keeps the form as it opened', async () => {
		store.fetchCollection.mockResolvedValue([draft({ title: 'Oud' })])
		const w = await open()
		await w.find('[data-testid="contact-log-draft-discard"]').trigger('click')
		await flushPromises()
		expect(store.deleteObject).toHaveBeenCalledWith('ticket', 'd-1')
		expect(w.find('[data-testid="contact-log-draft-offer"]').exists()).toBe(false)
		expect(w.find('[data-testid="contact-log-subject"]').element.value).toBe('')
	})

	it('writes nothing for a dialog nobody typed in, and one draft once the agent types', async () => {
		vi.useFakeTimers()
		const w = mount(Dialog, { global: { mocks: { $route: { params: { id: 't-1' } } } } })
		await vi.runAllTimersAsync()
		await flushPromises()
		await vi.advanceTimersByTimeAsync(3000)
		expect(store.saveObject).not.toHaveBeenCalled()

		store.saveObject.mockResolvedValue({ id: 'd-9' })
		await w.find('[data-testid="contact-log-subject"]').setValue('Sanne vraagt hoe lang de vergunning duurt')
		await vi.advanceTimersByTimeAsync(2500)
		await flushPromises()
		expect(store.saveObject).toHaveBeenCalledTimes(1)
		expect(store.saveObject.mock.calls[0][1]).toMatchObject({
			ticketType: 'interaction',
			parentTicket: 't-1',
			client: 'c-1',
			title: 'Sanne vraagt hoe lang de vergunning duurt',
			'@self': { status: 'draft' },
		})
		w.unmount()
	})
})
