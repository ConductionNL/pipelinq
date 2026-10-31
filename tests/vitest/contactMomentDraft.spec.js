// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact moment draft (contact-moments-keep-draft, tasks 1.1 to 1.4):
 * the pure helpers, autosave timing with fake timers, the restore offer, the
 * save that finishes the draft through OpenRegister's owner action, and the
 * save that survives an ended session.
 *
 * The draft is the contact moment itself: a `ticket` of type `interaction`
 * in OpenRegister's status `draft` (decisions 180 and 216). So the tests
 * here assert what is sent to the ticket endpoints, never a draft schema.
 *
 * @spec openspec/changes/contact-moments-keep-draft/specs/contact-moment-drafts/spec.md#requirement-a-manual-contact-moment-is-kept-as-a-private-draft-req-cmd2-001
 * @spec openspec/changes/contact-moments-keep-draft/specs/contact-moment-drafts/spec.md#requirement-an-expired-session-does-not-lose-the-text-req-cmd2-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({
	fetchCollection: vi.fn(),
	saveObject: vi.fn(),
	deleteObject: vi.fn(),
	getError: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		String(text).replace(/\{(\w+)\}/g, (whole, key) =>
			vars && key in vars ? String(vars[key]) : whole,
		),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))

/**
 * A stub that renders its default slot, so a note card shows its text.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'label', 'inputLabel', 'type', 'disabled'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(
				tag,
				{
					'data-stub': name,
					'data-testid': this.$attrs['data-testid'],
					onClick: () => this.$emit('click'),
				},
				this.$slots.default?.(),
			)
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcTextArea: stub('NcTextArea'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnResourceSelect: stub('CnResourceSelect'),
}))
vi.mock('../../src/dialogs/ClientCreateDialog.vue', () => ({
	default: stub('ClientCreateDialog'),
}))
vi.mock('../../src/dialogs/ContactCreateDialog.vue', () => ({
	default: stub('ContactCreateDialog'),
}))

globalThis.t = (app, text, vars) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) =>
		vars && key in vars ? String(vars[key]) : whole,
	)

const {
	DRAFT_MAX_AGE_MS,
	DraftAutosaver,
	buildContactMoment,
	buildDraftTicket,
	draftFormFrom,
	isDraftFormEmpty,
	isSessionEnded,
	pickDraft,
	refusalText,
} = await import('../../src/services/contactMomentDraft.js')
const { parseResponseError } =
	await import('@conduction/nextcloud-vue/src/utils/errors.js')
const { default: ContactmomentQuickLog } =
	await import('../../src/components/ContactmomentQuickLog.vue')

const NOW = new Date('2026-10-09T10:00:00Z')
const ACTIVATE = '/index.php/apps/openregister/api/objects/pipelinq/ticket/'

/**
 * A quick log form nobody typed in.
 *
 * @return {object} The form.
 */
function emptyForm() {
	return {
		title: '',
		channel: null,
		outcome: null,
		client: null,
		contact: null,
		parentTicket: null,
		description: '',
		duration: '',
		notes: '',
	}
}

/**
 * A draft contact moment as OpenRegister lists it.
 *
 * @param {object} fields The ticket fields.
 * @param {object} self The `@self` metadata.
 * @return {object} The draft ticket.
 */
function draftTicket(fields, self = {}) {
	return {
		id: 'd-1',
		ticketType: 'interaction',
		...fields,
		'@self': {
			status: 'draft',
			owner: 'sanne',
			updated: '2026-10-09T09:58:00Z',
			...self,
		},
	}
}

describe('contact moment draft helpers', () => {
	it('calls a form with only the prefilled client empty', () => {
		const form = { ...emptyForm(), client: 'c-1' }
		expect(isDraftFormEmpty(form, { clientId: 'c-1' })).toBe(true)
		expect(
			isDraftFormEmpty({ ...form, title: 'Bel terug' }, { clientId: 'c-1' }),
		).toBe(false)
		expect(
			isDraftFormEmpty({ ...form, channel: 'telefoon' }, { clientId: 'c-1' }),
		).toBe(false)
		expect(
			isDraftFormEmpty({ ...form, title: '   ' }, { clientId: 'c-1' }),
		).toBe(true)
	})

	it('writes the draft as a contact moment ticket in status draft, without nulls or an assignee', () => {
		const draft = buildDraftTicket({
			...emptyForm(),
			title: 'Adreswijziging',
			client: 'c-1',
			notes: 'Belt morgen terug',
		})
		expect(draft).toEqual({
			ticketType: 'interaction',
			title: 'Adreswijziging',
			client: 'c-1',
			notes: 'Belt morgen terug',
			'@self': { status: 'draft' },
		})
	})

	it('builds the saved contact moment with the moment of saving and the agent', () => {
		const saved = buildContactMoment(
			{ ...emptyForm(), title: 'Adreswijziging', channel: 'telefoon' },
			{ author: 'sanne', now: NOW },
		)
		expect(saved).toEqual({
			ticketType: 'interaction',
			title: 'Adreswijziging',
			channel: 'telefoon',
			occurredAt: '2026-10-09T10:00:00.000Z',
			assignee: 'sanne',
		})
		// The status is never sent: only OpenRegister's owner action leaves draft.
		expect(saved).not.toHaveProperty('@self')
		// No empty envelope: `{}` reaches a PHP validator as `[]`, which is no object.
		expect(saved).not.toHaveProperty('channelMetadata')
	})

	it("offers only the author's own, newest, unexpired contact moment draft for this client", () => {
		const context = {
			author: 'sanne',
			clientId: 'c-1',
			requestId: null,
			now: NOW,
		}
		const mine = draftTicket({ id: 'mine', client: 'c-1', title: 'Nieuw' })
		const older = draftTicket(
			{ id: 'older', client: 'c-1', title: 'Oud' },
			{ updated: '2026-10-08T09:00:00Z' },
		)
		const expired = draftTicket(
			{ id: 'expired', client: 'c-1' },
			{
				updated: new Date(
					NOW.getTime() - DRAFT_MAX_AGE_MS - 1000,
				).toISOString(),
			},
		)
		const colleague = draftTicket(
			{ id: 'theirs', client: 'c-1' },
			{ owner: 'mehmet' },
		)
		const elsewhere = draftTicket({ id: 'elsewhere', client: 'c-2' })
		const onARequest = draftTicket({
			id: 'request',
			client: 'c-1',
			parentTicket: 'r-1',
		})
		const aRequestDraft = draftTicket({
			id: 'other-type',
			client: 'c-1',
			ticketType: 'request',
		})
		const sent = draftTicket({ id: 'sent', client: 'c-1' }, { status: 'active' })

		expect(
			pickDraft(
				[
					older,
					expired,
					colleague,
					elsewhere,
					onARequest,
					aRequestDraft,
					sent,
					mine,
				],
				context,
			)?.id,
		).toBe('mine')
		expect(
			pickDraft([expired, colleague, elsewhere, aRequestDraft, sent], context),
		).toBe(null)
		expect(pickDraft([onARequest], { ...context, requestId: 'r-1' })?.id).toBe(
			'request',
		)
		// A draft without any date is not offered: its age cannot be told.
		expect(
			pickDraft(
				[draftTicket({ client: 'c-1' }, { updated: undefined })],
				context,
			),
		).toBe(null)
	})

	it('restores only the fields the form has', () => {
		expect(
			draftFormFrom(
				draftTicket({
					title: 'Adreswijziging',
					channel: 'telefoon',
					client: 'c-1',
					status: 'new',
					assignee: 'sanne',
				}),
			),
		).toEqual({ title: 'Adreswijziging', channel: 'telefoon', client: 'c-1' })
	})

	it('turns a refusal into one sentence with what was found', () => {
		expect(
			refusalText({
				message:
					'This draft is not complete yet. Check the fields marked below.',
				findings: [
					{
						property: 'channel',
						code: 'required',
						message: 'The required properties (channel) are missing',
					},
				],
			}),
		).toBe(
			'This draft is not complete yet. Check the fields marked below. The required properties (channel) are missing',
		)
		expect(refusalText(null)).toBe('')
	})

	it("reads 401 and 412 from the library's own error object as an ended session", async () => {
		const unauthorised = await parseResponseError(
			new Response(
				JSON.stringify({ message: 'Current user is not logged in' }),
				{ status: 401 },
			),
			'ticket',
		)
		const staleToken = await parseResponseError(
			new Response(JSON.stringify({ message: 'CSRF check failed' }), {
				status: 412,
			}),
			'ticket',
		)
		const invalid = await parseResponseError(
			new Response(JSON.stringify({ message: 'title is required' }), {
				status: 400,
			}),
			'ticket',
		)
		expect(isSessionEnded(unauthorised)).toBe(true)
		expect(isSessionEnded(staleToken)).toBe(true)
		expect(isSessionEnded(invalid)).toBe(false)
	})

	it('writes once after the quiet time, never twice at the same time, and settles', async () => {
		vi.useFakeTimers()
		let finish
		const write = vi.fn(
			() =>
				new Promise((resolve) => {
					finish = resolve
				}),
		)
		const remove = vi.fn().mockResolvedValue(undefined)
		const saver = new DraftAutosaver({ write, remove, delayMs: 2000 })
		saver.schedule({ title: 'A' })
		await vi.advanceTimersByTimeAsync(1500)
		saver.schedule({ title: 'AB' })
		await vi.advanceTimersByTimeAsync(1999)
		expect(write).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(1)
		expect(write).toHaveBeenCalledTimes(1)
		expect(write.mock.calls[0][0]).toEqual({ title: 'AB' })

		// A save waits for the write that is on its way.
		let settled = false
		const settling = saver.settle().then(() => {
			settled = true
		})
		await Promise.resolve()
		expect(settled).toBe(false)
		finish()
		await settling
		expect(settled).toBe(true)

		saver.schedule(null)
		await vi.advanceTimersByTimeAsync(2000)
		expect(remove).toHaveBeenCalledTimes(1)
		vi.useRealTimers()
	})
})

describe('ContactmomentQuickLog draft', () => {
	let fetchMock

	beforeEach(() => {
		vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
		vi.setSystemTime(NOW)
		window.OC = {
			getCurrentUser: () => ({ uid: 'sanne' }),
			requestToken: 'old-token',
		}
		storeMock.fetchCollection.mockReset().mockResolvedValue([])
		storeMock.saveObject.mockReset()
		storeMock.deleteObject.mockReset().mockResolvedValue(true)
		storeMock.getError.mockReset().mockReturnValue(null)
		fetchMock = vi.fn()
		globalThis.fetch = fetchMock
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	/**
	 * Mount the quick log on a client page.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountOnClient() {
		const wrapper = mount(ContactmomentQuickLog, {
			props: { clientId: 'c-1' },
			global: { mocks: { t: globalThis.t } },
		})
		await flushPromises()
		return wrapper
	}

	/**
	 * Make the draft list answer with these drafts, and the request list with none.
	 *
	 * @param {Array<object>} drafts The draft tickets.
	 * @return {void}
	 */
	function draftsOnServer(drafts) {
		storeMock.fetchCollection.mockImplementation(async (type, params) =>
			params?._drafts === 'true' ? drafts : [],
		)
	}

	it('asks OpenRegister for its own draft contact moments, and nothing else', async () => {
		const wrapper = await mountOnClient()
		const draftLists = storeMock.fetchCollection.mock.calls.filter(
			([, params]) => params?._drafts !== undefined,
		)
		expect(draftLists).toEqual([
			[
				'ticket',
				{
					ticketType: 'interaction',
					_drafts: 'true',
					'@self[owner]': 'sanne',
					_limit: 50,
				},
			],
		])
		// The request dropdown is an ordinary list: it never asks for drafts.
		expect(storeMock.fetchCollection).toHaveBeenCalledWith('ticket', {
			ticketType: 'request',
			_limit: 100,
		})
		wrapper.unmount()
	})

	it('writes one draft ticket two seconds after typing stops, then overwrites it', async () => {
		storeMock.saveObject.mockImplementation(async (type, data) => ({
			id: 'd-1',
			...data,
		}))
		const wrapper = await mountOnClient()

		wrapper.vm.form.title = 'Adres'
		await flushPromises()
		await vi.advanceTimersByTimeAsync(1000)
		wrapper.vm.form.title = 'Adreswijziging'
		await flushPromises()
		await vi.advanceTimersByTimeAsync(1999)
		expect(storeMock.saveObject).not.toHaveBeenCalled()

		await vi.advanceTimersByTimeAsync(1)
		expect(storeMock.saveObject.mock.calls).toEqual([
			[
				'ticket',
				{
					ticketType: 'interaction',
					title: 'Adreswijziging',
					client: 'c-1',
					'@self': { status: 'draft' },
				},
			],
		])

		wrapper.vm.form.notes = 'Belt morgen terug'
		await flushPromises()
		await vi.advanceTimersByTimeAsync(2000)
		expect(storeMock.saveObject).toHaveBeenCalledTimes(2)
		expect(storeMock.saveObject.mock.calls[1][1]).toMatchObject({
			id: 'd-1',
			notes: 'Belt morgen terug',
			'@self': { status: 'draft' },
		})
		wrapper.unmount()
	})

	it('writes nothing for a form nobody typed in', async () => {
		const wrapper = await mountOnClient()
		wrapper.vm.form.client = 'c-1'
		await flushPromises()
		await vi.advanceTimersByTimeAsync(5000)
		expect(storeMock.saveObject).not.toHaveBeenCalled()
		wrapper.unmount()
		await flushPromises()
		expect(storeMock.saveObject).not.toHaveBeenCalled()
	})

	it('offers the draft, restores it, and Save makes that same object definitive', async () => {
		draftsOnServer([
			draftTicket({
				id: 'd-9',
				client: 'c-1',
				title: 'Adreswijziging',
				notes: 'Belt morgen terug',
			}),
		])
		fetchMock.mockImplementation(async (url, options) => {
			const sent = JSON.parse(options.body).object
			return new Response(
				JSON.stringify({
					id: 'd-9',
					...sent,
					'@self': { status: 'active' },
				}),
				{ status: 200 },
			)
		})
		const wrapper = await mountOnClient()

		expect(
			wrapper.find('[data-testid="contactmoment-draft-offer"]').text(),
		).toContain('You have an unsaved contact moment from')
		// The draft on offer is not overwritten by the empty form.
		await vi.advanceTimersByTimeAsync(5000)
		expect(storeMock.saveObject).not.toHaveBeenCalled()

		await wrapper
			.find('[data-testid="contactmoment-draft-restore"]')
			.trigger('click')
		expect(wrapper.vm.form.title).toBe('Adreswijziging')
		expect(wrapper.vm.form.notes).toBe('Belt morgen terug')

		wrapper.vm.form.channel = 'telefoon'
		await flushPromises()
		await wrapper.vm.onSave()
		await flushPromises()

		// One call, to the owner action of the draft itself, with the whole form.
		expect(fetchMock).toHaveBeenCalledTimes(1)
		const [url, options] = fetchMock.mock.calls[0]
		expect(url).toBe(ACTIVATE + 'd-9/activate')
		expect(options.method).toBe('POST')
		expect(options.headers.requesttoken).toBe('old-token')
		expect(JSON.parse(options.body)).toEqual({
			object: {
				ticketType: 'interaction',
				title: 'Adreswijziging',
				channel: 'telefoon',
				client: 'c-1',
				notes: 'Belt morgen terug',
				// Five seconds passed while the offer stood.
				occurredAt: '2026-10-09T10:00:05.000Z',
				assignee: 'sanne',
			},
		})
		// No second ticket is created and the draft is not deleted: it became the contact moment.
		expect(storeMock.saveObject).not.toHaveBeenCalled()
		expect(storeMock.deleteObject).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')[0][0]).toMatchObject({
			id: 'd-9',
			'@self': { status: 'active' },
		})
		wrapper.unmount()
	})

	it('saves a contact moment nobody drafted the ordinary way', async () => {
		storeMock.saveObject.mockImplementation(async (type, data) => ({
			id: 't-1',
			...data,
		}))
		const wrapper = await mountOnClient()
		wrapper.vm.form.title = 'Kort gesprek'
		wrapper.vm.form.channel = 'balie'
		await flushPromises()

		// Save before the quiet time is over: there is no draft yet.
		await wrapper.vm.onSave()
		await flushPromises()

		expect(fetchMock).not.toHaveBeenCalled()
		expect(storeMock.saveObject).toHaveBeenCalledTimes(1)
		expect(storeMock.saveObject.mock.calls[0][1]).toMatchObject({
			ticketType: 'interaction',
			title: 'Kort gesprek',
			channel: 'balie',
			assignee: 'sanne',
		})
		expect(storeMock.saveObject.mock.calls[0][1]).not.toHaveProperty('@self')
		expect(wrapper.emitted('saved')).toHaveLength(1)
		// Nothing writes a draft after the save.
		await vi.advanceTimersByTimeAsync(5000)
		expect(storeMock.saveObject).toHaveBeenCalledTimes(1)
		wrapper.unmount()
	})

	it('shows what OpenRegister found when the draft is not complete, and keeps the draft', async () => {
		draftsOnServer([
			draftTicket({ id: 'd-9', client: 'c-1', title: 'Adreswijziging' }),
		])
		fetchMock.mockResolvedValue(
			new Response(
				JSON.stringify({
					message:
						'This draft is not complete yet. Check the fields marked below.',
					reason: 'invalid',
					findings: [
						{
							property: 'channel',
							code: 'enum',
							message: 'The value is not one of the allowed channels',
						},
					],
				}),
				{ status: 422 },
			),
		)
		const wrapper = await mountOnClient()
		await wrapper
			.find('[data-testid="contactmoment-draft-restore"]')
			.trigger('click')
		wrapper.vm.form.channel = 'postduif'
		await flushPromises()

		await wrapper.vm.onSave()
		await flushPromises()

		expect(wrapper.find('.form-error').text()).toBe(
			'This draft is not complete yet. Check the fields marked below. The value is not one of the allowed channels',
		)
		expect(wrapper.emitted('saved')).toBeUndefined()
		expect(wrapper.vm.draftId).toBe('d-9')
		expect(wrapper.vm.form.title).toBe('Adreswijziging')
		expect(storeMock.saveObject).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('saves a new contact moment when the draft is gone', async () => {
		draftsOnServer([
			draftTicket({ id: 'd-9', client: 'c-1', title: 'Adreswijziging' }),
		])
		fetchMock.mockResolvedValue(
			new Response(
				JSON.stringify({
					message: 'This draft does not exist.',
					reason: 'not-found',
					findings: [],
				}),
				{
					status: 404,
				},
			),
		)
		storeMock.saveObject.mockImplementation(async (type, data) => ({
			id: 't-2',
			...data,
		}))
		const wrapper = await mountOnClient()
		await wrapper
			.find('[data-testid="contactmoment-draft-restore"]')
			.trigger('click')
		wrapper.vm.form.channel = 'telefoon'
		await flushPromises()

		await wrapper.vm.onSave()
		await flushPromises()

		expect(storeMock.saveObject).toHaveBeenCalledTimes(1)
		expect(storeMock.saveObject.mock.calls[0][1]).not.toHaveProperty('id')
		expect(wrapper.emitted('saved')[0][0]).toMatchObject({ id: 't-2' })
		wrapper.unmount()
	})

	it('discards the draft ticket and starts from an empty form', async () => {
		draftsOnServer([
			draftTicket({ id: 'd-9', client: 'c-1', title: 'Adreswijziging' }),
		])
		const wrapper = await mountOnClient()

		await wrapper
			.find('[data-testid="contactmoment-draft-discard"]')
			.trigger('click')
		await flushPromises()

		expect(storeMock.deleteObject).toHaveBeenCalledWith('ticket', 'd-9')
		expect(
			wrapper.find('[data-testid="contactmoment-draft-offer"]').exists(),
		).toBe(false)
		expect(wrapper.vm.form.title).toBe('')
		expect(wrapper.vm.draftId).toBe(null)
		wrapper.unmount()
	})

	it('offers no draft that belongs to a colleague', async () => {
		draftsOnServer([
			draftTicket(
				{ id: 'd-7', client: 'c-1', title: 'Van Mehmet' },
				{ owner: 'mehmet' },
			),
		])
		const wrapper = await mountOnClient()
		expect(
			wrapper.find('[data-testid="contactmoment-draft-offer"]').exists(),
		).toBe(false)
		expect(storeMock.deleteObject).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('keeps the text on a 401, says the session ended, and saves on the next Save', async () => {
		let ticketTries = 0
		storeMock.saveObject.mockImplementation(async (type, data) => {
			ticketTries++
			return ticketTries === 1 ? null : { id: 't-1', ...data }
		})
		storeMock.getError.mockReturnValue({
			status: 401,
			message: 'Current user is not logged in',
		})
		fetchMock.mockResolvedValue(
			new Response(JSON.stringify({ token: 'new-token' }), { status: 200 }),
		)
		const wrapper = await mountOnClient()
		wrapper.vm.form.title = 'Lang gesprek'
		wrapper.vm.form.channel = 'telefoon'
		wrapper.vm.form.notes = 'Alles over de verhuizing'
		await flushPromises()

		await wrapper.vm.onSave()
		await flushPromises()
		expect(
			wrapper.find('[data-testid="contactmoment-session-ended"]').text(),
		).toContain(
			'Your session has ended. Log in again in a new tab, then press Save here.',
		)
		expect(wrapper.vm.form.notes).toBe('Alles over de verhuizing')
		expect(wrapper.emitted('saved')).toBeUndefined()

		await wrapper.vm.onSave()
		await flushPromises()
		expect(fetchMock).toHaveBeenCalledWith(
			'/index.php/csrftoken',
			expect.anything(),
		)
		expect(window.OC.requestToken).toBe('new-token')
		expect(ticketTries).toBe(2)
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(
			wrapper.find('[data-testid="contactmoment-session-ended"]').exists(),
		).toBe(false)
	})

	it('reads an ended session from the owner action too', async () => {
		draftsOnServer([
			draftTicket({ id: 'd-9', client: 'c-1', title: 'Adreswijziging' }),
		])
		fetchMock.mockResolvedValue(new Response('{}', { status: 412 }))
		const wrapper = await mountOnClient()
		await wrapper
			.find('[data-testid="contactmoment-draft-restore"]')
			.trigger('click')
		wrapper.vm.form.channel = 'telefoon'
		await flushPromises()

		await wrapper.vm.onSave()
		await flushPromises()

		expect(
			wrapper.find('[data-testid="contactmoment-session-ended"]').exists(),
		).toBe(true)
		expect(wrapper.vm.draftId).toBe('d-9')
		expect(wrapper.emitted('saved')).toBeUndefined()
		wrapper.unmount()
	})
})
