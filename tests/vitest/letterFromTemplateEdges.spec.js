// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The letter dialog off its happy path: no templates yet, a ticket without a
 * client, templates that cannot be read, a client with several tickets and
 * contacts to choose from (the about-ticket choice rereads the next
 * appointment), a preview that fails or leaves fields empty, a make that
 * fails, a download-only make and a letter that was not logged.
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

const alert = (w) => w.find('[role="alert"]').text()

describe('the letter dialog off its happy path', () => {
	it('says there are no templates yet', async () => {
		api.fetchLetterTemplates.mockResolvedValue({
			available: true,
			templates: [],
		})
		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()
		expect(w.text()).toContain('There are no letter templates yet.')
		expect(
			w.find('[data-testid="letter-make"]').attributes('disabled'),
		).toBeDefined()
	})

	it('refuses a ticket that has no client', async () => {
		storeMock.fetchObject.mockResolvedValue({ id: TICKET })
		api.fetchLetterTemplates.mockResolvedValue(ONE)
		const w = mountOn(`/tickets/${TICKET}`, TICKET)
		await flushPromises()
		expect(alert(w)).toBe(
			'This ticket has no client, so there is nobody to write to.',
		)
		expect(storeMock.fetchCollection).not.toHaveBeenCalled()
	})

	it('says when the templates or the ticket cannot be read', async () => {
		api.fetchLetterTemplates.mockRejectedValueOnce(new Error('down'))
		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()
		expect(w.text()).toContain(
			'Letters are made by filinq, and filinq is not available.',
		)

		api.fetchLetterTemplates.mockResolvedValue(ONE)
		storeMock.fetchObject.mockRejectedValueOnce(new Error('gone'))
		const t2 = mountOn(`/tickets/${TICKET}`, TICKET)
		await flushPromises()
		expect(alert(t2)).toBe('The letter templates could not be loaded.')
	})

	it('offers the client tickets and contacts and rereads the appointment for another ticket', async () => {
		api.fetchLetterTemplates.mockResolvedValue({
			available: true,
			templates: [
				{ id: 'tpl-1', name: 'Appointment letter' },
				{ id: 'tpl-2', name: 'Reminder' },
			],
		})
		storeMock.fetchObject.mockResolvedValue({
			id: CLIENT,
			name: 'Gemeente Zoetermeer',
		})
		storeMock.fetchCollection.mockImplementation(async (type, params) => {
			if (type === 'ticket') {
				return [
					{ id: 't-1', reference: 'T-1', title: 'Paspoort' },
					{ id: 't-2', reference: 'T-2', title: '' },
				]
			}
			if (type === 'contact') {
				return [{ id: 'c-1', name: 'Anna' }, { id: 'c-2' }]
			}
			if (params.ticket === 't-2') {
				return [
					{ id: 'ap-2', ticket: 't-2', startAt: '2099-02-01T10:00:00Z' },
				]
			}
			return [{ id: 'ap-1', startAt: '2099-03-01T10:00:00Z' }]
		})
		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()

		expect(w.find('.nc-dialog').attributes('data-eyebrow')).toBe(
			'Gemeente Zoetermeer · letter',
		)
		expect(w.text()).toContain('2 are shared with pipelinq.')
		const about = w
			.findAllComponents({ name: 'NcSelect' })
			.find((s) => s.props('inputLabel') === 'About')
		const contact = w
			.findAllComponents({ name: 'NcSelect' })
			.find((s) => s.props('inputLabel') === 'Contact person (optional)')
		expect(about.props('options')).toEqual([
			{ value: 't-1', label: 'T-1 Paspoort' },
			{ value: 't-2', label: 'T-2' },
		])
		expect(about.props('modelValue')).toBe('t-1')
		expect(contact.props('options')).toEqual([
			{ value: 'c-1', label: 'Anna' },
			{ value: 'c-2', label: 'c-2' },
		])

		const template = w.findAllComponents({ name: 'NcSelect' })[0]
		await template.vm.$emit('update:modelValue', 'tpl-2')
		await flushPromises()
		expect(api.previewLetter).toHaveBeenLastCalledWith(CLIENT, {
			templateId: 'tpl-2',
			contactId: undefined,
			ticketId: 't-1',
			appointmentId: 'ap-1',
		})

		await about.vm.$emit('update:modelValue', 't-2')
		await contact.vm.$emit('update:modelValue', 'c-1')
		await flushPromises()
		expect(api.previewLetter).toHaveBeenLastCalledWith(CLIENT, {
			templateId: 'tpl-2',
			contactId: 'c-1',
			ticketId: 't-2',
			appointmentId: 'ap-2',
		})
	})

	it('lists the empty fields and their sources, or says the preview failed', async () => {
		api.fetchLetterTemplates.mockResolvedValue(ONE)
		api.previewLetter.mockResolvedValueOnce({
			html: '<p>Beste</p>',
			complete: false,
			fields: [
				{ label: 'Name', filled: true, value: 'Anna', source: 'client' },
				{ label: 'Salutation', filled: false, source: 'contact' },
			],
		})
		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()
		expect(w.find('.letter-modal__letter').html()).toContain('<p>Beste</p>')
		expect(
			w.findAll('[data-testid="letter-fields"] dd').map((d) => d.text()),
		).toEqual(['Anna, client', 'Not filled in, contact'])
		expect(w.find('[data-testid="letter-check"]').text()).toBe(
			'Still empty: Salutation',
		)

		api.previewLetter.mockRejectedValueOnce(new Error('render'))
		await w
			.findAllComponents({ name: 'NcSelect' })[0]
			.vm.$emit('update:modelValue', null)
		await w
			.findAllComponents({ name: 'NcSelect' })[0]
			.vm.$emit('update:modelValue', 'tpl-1')
		await flushPromises()
		expect(w.find('[data-testid="letter-preview"]').text()).toContain(
			'The preview could not be made. You can still make the letter.',
		)
		expect(w.find('[data-testid="letter-fields"]').exists()).toBe(false)
	})

	it('downloads only without leaving the form, and names a failed make', async () => {
		api.fetchLetterTemplates.mockResolvedValue(ONE)
		api.makeLetter.mockResolvedValueOnce({ content: 'x', warnings: [] })
		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()

		await w.find('[data-testid="letter-download"]').trigger('click')
		await flushPromises()
		expect(api.makeLetter).toHaveBeenCalledWith(CLIENT, {
			templateId: 'tpl-1',
			contactId: undefined,
			ticketId: undefined,
			appointmentId: undefined,
			downloadOnly: true,
		})
		expect(api.downloadLetter).toHaveBeenCalledTimes(1)
		expect(w.find('[data-testid="letter-result"]').exists()).toBe(false)

		api.makeLetter.mockRejectedValueOnce({
			response: { data: { message: 'filinq refused' } },
		})
		await w.find('[data-testid="letter-make"]').trigger('click')
		await flushPromises()
		expect(alert(w)).toBe('filinq refused')

		api.makeLetter.mockRejectedValueOnce(new Error('x'))
		await w.find('[data-testid="letter-make"]').trigger('click')
		await flushPromises()
		expect(alert(w)).toBe('The letter could not be made.')
	})

	it('warns when the made letter was not logged on the client', async () => {
		api.fetchLetterTemplates.mockResolvedValue(ONE)
		api.makeLetter.mockResolvedValueOnce({ content: 'x', warnings: [] })
		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()
		await w.find('[data-testid="letter-make"]').trigger('click')
		await flushPromises()
		expect(alert(w)).toBe(
			'The letter is not logged on the client. Log it as a contact moment by hand.',
		)
		expect(w.findAll('button').map((b) => b.text())).toEqual(['Close'])
		await w.find('button').trigger('click')
		expect(w.emitted('close')).toHaveLength(1)
	})
})
