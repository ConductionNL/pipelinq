// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact moment draft (contact-moments-keep-draft, tasks 1.2, 1.3, 1.4):
 * the pure helpers, autosave timing with fake timers, the restore offer, and
 * the save that survives an ended session.
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
	buildDraftPayload,
	isDraftFormEmpty,
	isSessionEnded,
	pickDraft,
} = await import('../../src/services/contactMomentDraft.js')
const { parseResponseError } =
	await import('@conduction/nextcloud-vue/src/utils/errors.js')
const { default: ContactmomentQuickLog } =
	await import('../../src/components/ContactmomentQuickLog.vue')

const NOW = new Date('2026-10-09T10:00:00Z')
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

	it('writes no null references into the draft', () => {
		const payload = buildDraftPayload(
			{ ...emptyForm(), title: 'Adreswijziging', notes: 'Belt morgen terug' },
			{ author: 'sanne', clientId: null, requestId: null, now: NOW },
		)
		expect(payload).toEqual({
			author: 'sanne',
			form: { title: 'Adreswijziging', notes: 'Belt morgen terug' },
			updatedAt: NOW.toISOString(),
		})
	})

	it("offers only the author's own, newest, unexpired draft for this client", () => {
		const old = new Date(NOW.getTime() - DRAFT_MAX_AGE_MS - 1000).toISOString()
		const drafts = [
			{
				id: 'mehmet',
				author: 'mehmet',
				client: 'c-1',
				updatedAt: NOW.toISOString(),
			},
			{
				id: 'older',
				author: 'sanne',
				client: 'c-1',
				updatedAt: '2026-10-09T09:00:00Z',
			},
			{
				id: 'newest',
				author: 'sanne',
				client: 'c-1',
				updatedAt: '2026-10-09T09:30:00Z',
			},
			{
				id: 'other-client',
				author: 'sanne',
				client: 'c-2',
				updatedAt: '2026-10-09T09:45:00Z',
			},
			{ id: 'expired', author: 'sanne', client: 'c-1', updatedAt: old },
		]
		const { offer, stale } = pickDraft(drafts, {
			author: 'sanne',
			clientId: 'c-1',
			requestId: null,
			now: NOW,
		})
		expect(offer.id).toBe('newest')
		expect(stale.map((d) => d.id).sort()).toEqual(['expired', 'older'])
		expect(
			pickDraft(drafts, {
				author: 'mehmet',
				clientId: 'c-2',
				requestId: null,
				now: NOW,
			}).offer,
		).toBe(null)
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

	it('writes once after the quiet time and never twice at the same time', async () => {
		vi.useFakeTimers()
		const write = vi.fn().mockResolvedValue(undefined)
		const remove = vi.fn().mockResolvedValue(undefined)
		const saver = new DraftAutosaver({ write, remove, delayMs: 2000 })
		saver.schedule({ form: { title: 'A' } })
		await vi.advanceTimersByTimeAsync(1500)
		saver.schedule({ form: { title: 'AB' } })
		await vi.advanceTimersByTimeAsync(1999)
		expect(write).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(1)
		expect(write).toHaveBeenCalledTimes(1)
		expect(write.mock.calls[0][0]).toEqual({ form: { title: 'AB' } })
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
		storeMock.fetchCollection.mockReset()
		storeMock.saveObject.mockReset()
		storeMock.deleteObject.mockReset().mockResolvedValue(true)
		storeMock.getError.mockReset().mockReturnValue(null)
		storeMock.fetchCollection.mockImplementation(async (type) =>
			type === 'contactMomentDraft' ? [] : [],
		)
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

	const draftWrites = () =>
		storeMock.saveObject.mock.calls.filter(
			([type]) => type === 'contactMomentDraft',
		)

	it('writes one draft two seconds after typing stops', async () => {
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
		expect(draftWrites()).toHaveLength(0)

		await vi.advanceTimersByTimeAsync(1)
		expect(draftWrites()).toHaveLength(1)
		const [, payload] = draftWrites()[0]
		expect(payload).toMatchObject({
			author: 'sanne',
			client: 'c-1',
			form: { title: 'Adreswijziging', client: 'c-1' },
		})
		expect(payload).not.toHaveProperty('request')
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

	it('offers the draft, restores it, and removes it once the contact moment is saved', async () => {
		storeMock.fetchCollection.mockImplementation(async (type) =>
			type === 'contactMomentDraft'
				? [
						{
							id: 'd-9',
							author: 'sanne',
							client: 'c-1',
							updatedAt: '2026-10-09T09:58:00Z',
							form: {
								title: 'Adreswijziging',
								channel: 'telefoon',
								notes: 'Twee regels',
							},
						},
					]
				: [],
		)
		storeMock.saveObject.mockImplementation(async (type, data) => ({
			id: 't-1',
			...data,
		}))
		const wrapper = await mountOnClient()

		expect(
			wrapper.find('[data-testid="contactmoment-draft-offer"]').text(),
		).toContain('You have an unsaved contact moment from')
		await wrapper
			.find('[data-testid="contactmoment-draft-restore"]')
			.trigger('click')
		expect(wrapper.vm.form.title).toBe('Adreswijziging')
		expect(wrapper.vm.form.notes).toBe('Twee regels')
		expect(
			wrapper.find('[data-testid="contactmoment-draft-offer"]').exists(),
		).toBe(false)

		await wrapper.vm.onSave()
		await flushPromises()
		expect(storeMock.saveObject).toHaveBeenCalledWith(
			'ticket',
			expect.objectContaining({
				title: 'Adreswijziging',
				ticketType: 'interaction',
			}),
		)
		expect(storeMock.deleteObject).toHaveBeenCalledWith(
			'contactMomentDraft',
			'd-9',
		)
		await vi.advanceTimersByTimeAsync(5000)
		expect(draftWrites()).toHaveLength(0)
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('offers no draft that belongs to a colleague', async () => {
		storeMock.fetchCollection.mockImplementation(async (type) =>
			type === 'contactMomentDraft'
				? [
						{
							id: 'd-m',
							author: 'mehmet',
							client: 'c-1',
							updatedAt: '2026-10-09T09:58:00Z',
							form: { title: 'Van Mehmet' },
						},
					]
				: [],
		)
		const wrapper = await mountOnClient()
		expect(
			wrapper.find('[data-testid="contactmoment-draft-offer"]').exists(),
		).toBe(false)
		expect(storeMock.fetchCollection).toHaveBeenCalledWith(
			'contactMomentDraft',
			expect.objectContaining({ author: 'sanne' }),
		)
		wrapper.unmount()
	})

	it('keeps the text on a 401, says the session ended, and saves on the next Save', async () => {
		let ticketTries = 0
		storeMock.saveObject.mockImplementation(async (type, data) => {
			if (type !== 'ticket') {
				return { id: 'd-1', ...data }
			}
			ticketTries++
			return ticketTries === 1 ? null : { id: 't-1', ...data }
		})
		storeMock.getError.mockImplementation((type) =>
			type === 'ticket'
				? { status: 401, message: 'Current user is not logged in' }
				: null,
		)
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
})
