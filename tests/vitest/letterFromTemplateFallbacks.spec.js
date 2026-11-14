// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The letter dialog when parts of the client's context cannot be read: the
 * client, its tickets or its appointments each fall back to nothing and the
 * dialog still opens; and a preview answered after a newer one was asked
 * for, whether it succeeded or failed, changes nothing.
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({
	fetchObject: vi.fn(),
	fetchCollection: vi.fn(),
}))
const api = vi.hoisted(() => ({
	fetchLetterTemplates: vi.fn(),
	makeLetter: vi.fn(),
	downloadLetter: vi.fn(),
	previewLetter: vi.fn(),
	letterPreviewBody: vi.fn((html) => html),
}))

vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))
vi.mock('../../src/services/letters.js', () => api)
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'eyebrow', 'width'],
		render() {
			return h(
				'div',
				{
					class: 'nc-dialog',
					'data-eyebrow': this.eyebrow,
					'data-width': this.width,
					'data-name': this.name,
				},
				[this.$slots.default?.(), this.$slots.actions?.()],
			)
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		inheritAttrs: true,
		props: { disabled: Boolean },
		emits: ['click'],
		render() {
			return h(
				'button',
				{ disabled: this.disabled, onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcDialog: {
		name: 'NcDialog',
		render() {
			return h('div', { class: 'nc-dialog' }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel'],
		emits: ['update:modelValue'],
		render() {
			return h('select', { 'aria-label': this.inputLabel })
		},
	},
}))

let Modal

beforeAll(async () => {
	globalThis.t = (app, text, vars) =>
		text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
	Modal = (await import('../../src/modals/LetterFromTemplateModal.vue')).default
})

beforeEach(() => {
	Object.values(storeMock).forEach((fn) => fn.mockReset())
	Object.values(api).forEach((fn) => fn.mockReset())
	api.letterPreviewBody.mockImplementation((html) => html)
	api.previewLetter.mockResolvedValue({
		html: '',
		fields: [],
		complete: true,
		warnings: [],
	})
	storeMock.fetchCollection.mockImplementation(async (type) =>
		type === 'contact' ? [{ id: 'contact-1', name: 'Anna de Jong' }] : [],
	)
	storeMock.fetchObject.mockResolvedValue(null)
})

const CLIENT = 'client-1'
const TICKET = 'ticket-1'
const ONE = {
	available: true,
	templates: [{ id: 'tpl-1', name: 'Appointment letter' }],
}

function mountOn(path, id) {
	return mount(Modal, {
		global: {
			mixins: [
				{
					methods: {
						t: (app, text, vars) =>
							text.replace(/\{(\w+)\}/g, (m, k) =>
								vars && k in vars ? vars[k] : m,
							),
						n: (app, one, many, count) =>
							(count === 1 ? one : many).replace('%n', String(count)),
					},
				},
			],
			mocks: { $route: { path, params: { id } } },
		},
	})
}


const FUTURE = '2999-01-01T10:00:00Z'

/**
 * A preview answer the test resolves or rejects when it wants.
 *
 * @return {{promise: Promise<object>, resolve: Function, reject: Function}}
 */
function deferred() {
	let resolve
	let reject
	const promise = new Promise((res, rej) => {
		resolve = res
		reject = rej
	})
	return { promise, resolve, reject }
}

describe('the letter dialog when the client context cannot be read', () => {
	it('opens without a client name, tickets or appointment when those reads fail', async () => {
		api.fetchLetterTemplates.mockResolvedValue(ONE)
		storeMock.fetchObject.mockRejectedValue(new Error('client gone'))
		storeMock.fetchCollection.mockImplementation(async (type) => {
			if (type === 'contact') {
				return [{ id: 'contact-1', name: 'Anna de Jong' }]
			}
			throw new Error(type + ' unreadable')
		})

		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()

		expect(w.vm.clientName).toBe('')
		expect(w.vm.tickets).toEqual([])
		expect(w.vm.aboutTicketId).toBeFalsy()
		expect(w.vm.appointmentId).toBeNull()
		expect(w.vm.contacts).toEqual([{ id: 'contact-1', name: 'Anna de Jong' }])
		expect(w.find('[role="alert"]').exists()).toBe(false)
		expect(w.find('[data-testid="letter-make"]').attributes('disabled')).toBeUndefined()
	})

	it('keeps the client appointment when the ticket appointments cannot be read', async () => {
		api.fetchLetterTemplates.mockResolvedValue(ONE)
		storeMock.fetchObject.mockImplementation(async (type, id) =>
			type === 'ticket' ? { id, client: CLIENT } : { id, name: 'Gemeente Zoetermeer' },
		)
		storeMock.fetchCollection.mockImplementation(async (type, params) => {
			if (type === 'appointmentBooking' && params.ticket) {
				throw new Error('ticket bookings unreadable')
			}
			if (type === 'appointmentBooking') {
				return [{ id: 'booking-1', startAt: FUTURE, status: 'confirmed' }]
			}
			return []
		})

		const w = mountOn(`/tickets/${TICKET}`, TICKET)
		await flushPromises()

		expect(w.vm.appointmentId).toBe('booking-1')
		expect(storeMock.fetchCollection).toHaveBeenCalledWith('appointmentBooking', { ticket: TICKET, _limit: 50 })
	})
})

describe('a preview answered after a newer one was asked for', () => {
	async function openWithTemplate() {
		api.fetchLetterTemplates.mockResolvedValue(ONE)
		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()
		return w
	}

	it('is ignored when it arrives with fields', async () => {
		const w = await openWithTemplate()
		const older = deferred()
		const newer = deferred()
		api.previewLetter.mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise)

		const first = w.vm.loadPreview()
		const second = w.vm.loadPreview()
		newer.resolve({ html: '<p>new</p>', fields: [{ value: 'new', source: '', filled: true }], complete: true })
		await second
		older.resolve({ html: '<p>old</p>', fields: [{ value: 'old', source: '', filled: true }], complete: false })
		await first

		expect(w.vm.previewHtml).toBe('<p>new</p>')
		expect(w.vm.fields).toEqual([{ value: 'new', source: '', filled: true }])
		expect(w.vm.complete).toBe(true)
		expect(w.vm.previewing).toBe(false)
	})

	it('is ignored when it fails', async () => {
		const w = await openWithTemplate()
		const older = deferred()
		const newer = deferred()
		api.previewLetter.mockReturnValueOnce(older.promise).mockReturnValueOnce(newer.promise)

		const first = w.vm.loadPreview()
		const second = w.vm.loadPreview()
		newer.resolve({ html: '<p>new</p>', fields: [], complete: true })
		await second
		older.reject(new Error('too late'))
		await first

		expect(w.vm.previewError).toBe('')
		expect(w.vm.previewHtml).toBe('<p>new</p>')
		expect(w.vm.previewing).toBe(false)
	})
})
