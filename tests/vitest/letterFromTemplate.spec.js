// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A letter from a filinq template (work-letter-from-filinq-template): the
 * modal lists pipelinq's templates, posts the choice, downloads the PDF and
 * shows filinq's warnings; the header action is declared on ClientDetail and
 * TicketDetail and hidden when filinq is absent.
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
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
			return h('div', { class: 'nc-dialog', 'data-eyebrow': this.eyebrow, 'data-width': this.width, 'data-name': this.name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
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
	globalThis.t = (app, text) => text
	Modal = (await import('../../src/modals/LetterFromTemplateModal.vue')).default
})

beforeEach(() => {
	Object.values(storeMock).forEach((fn) => fn.mockReset())
	Object.values(api).forEach((fn) => fn.mockReset())
	api.letterPreviewBody.mockImplementation((html) => html)
	api.previewLetter.mockResolvedValue({ html: '', fields: [], complete: true, warnings: [] })
	storeMock.fetchCollection.mockImplementation(async (type) => (type === 'contact'
		? [{ id: 'contact-1', name: 'Anna de Jong' }]
		: []))
	storeMock.fetchObject.mockResolvedValue(null)
})

const CLIENT = '6f1c1d2e-3b4a-4c5d-8e9f-0a1b2c3d4e5f'
const TICKET = '7a2b3c4d-5e6f-4a1b-9c2d-3e4f5a6b7c8d'

function mountOn(path, id) {
	return mount(Modal, {
		global: {
			mixins: [{ methods: { t: (app, text) => text, n: (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)) } }],
			mocks: { $route: { path, params: { id } } },
		},
	})
}

const read = (p) => readFileSync(resolve(__dirname, '../..', p), 'utf8')

describe('LetterFromTemplateModal', () => {
	it('lists the templates and posts the chosen one for the client on the page', async () => {
		api.fetchLetterTemplates.mockResolvedValue({
			available: true,
			templates: [
				{ id: 'tpl-1', name: 'Appointment letter', description: '' },
			],
		})
		api.makeLetter.mockResolvedValue({
			content: 'JVBERg==',
			filename: 'Appointment letter.pdf',
			warnings: [],
			contactMomentId: 'cm-1',
		})

		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()

		expect(w.find('[data-testid="letter-template"]').exists()).toBe(true)
		expect(storeMock.fetchCollection).toHaveBeenCalledWith('contact', {
			client: CLIENT,
			_limit: 100,
		})
		// One template is chosen for the user.
		await w.find('[data-testid="letter-make"]').trigger('click')
		await flushPromises()

		expect(api.makeLetter).toHaveBeenCalledWith(CLIENT, {
			templateId: 'tpl-1',
			contactId: undefined,
			ticketId: undefined,
		})
		expect(api.downloadLetter).toHaveBeenCalledTimes(1)
		expect(w.find('[data-testid="letter-result"]').exists()).toBe(true)
	})

	it('on a ticket page writes to the ticket client and quotes the ticket', async () => {
		storeMock.fetchObject.mockResolvedValue({ id: TICKET, client: CLIENT })
		api.fetchLetterTemplates.mockResolvedValue({
			available: true,
			templates: [
				{ id: 'tpl-1', name: 'Appointment letter', description: '' },
			],
		})
		api.makeLetter.mockResolvedValue({
			content: '',
			warnings: [],
			contactMomentId: 'cm-1',
		})

		const w = mountOn(`/tickets/${TICKET}`, TICKET)
		await flushPromises()
		await w.find('[data-testid="letter-make"]').trigger('click')
		await flushPromises()

		expect(storeMock.fetchObject).toHaveBeenCalledWith('ticket', TICKET)
		expect(api.makeLetter).toHaveBeenCalledWith(CLIENT, {
			templateId: 'tpl-1',
			contactId: undefined,
			ticketId: TICKET,
		})
	})

	it('shows the warnings filinq returned after the render', async () => {
		api.fetchLetterTemplates.mockResolvedValue({
			available: true,
			templates: [
				{ id: 'tpl-1', name: 'Appointment letter', description: '' },
			],
		})
		api.makeLetter.mockResolvedValue({
			content: 'JVBERg==',
			warnings: ['Variable client.salutation is empty'],
			contactMomentId: 'cm-1',
		})

		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()
		await w.find('[data-testid="letter-make"]').trigger('click')
		await flushPromises()

		expect(w.find('[data-testid="letter-warnings"]').text()).toContain(
			'Variable client.salutation is empty',
		)
	})

	it('says so when filinq is not there and offers nothing to make', async () => {
		api.fetchLetterTemplates.mockResolvedValue({
			available: false,
			reason: 'filinq_unavailable',
			templates: [],
		})

		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()

		expect(w.text()).toContain('filinq is not available')
		expect(
			w.find('[data-testid="letter-make"]').attributes('disabled'),
		).toBeDefined()
		expect(storeMock.fetchCollection).not.toHaveBeenCalled()
	})

	it('says there are no templates yet while the client is still loading', async () => {
		api.fetchLetterTemplates.mockResolvedValue({ available: true, templates: [] })
		storeMock.fetchObject.mockReturnValue(new Promise(() => {}))
		storeMock.fetchCollection.mockReturnValue(new Promise(() => {}))

		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()

		expect(w.text()).not.toContain('Loading templates')
		expect(w.text()).toContain('There are no letter templates yet')
	})

	it('on a ticket page asks for the templates before the ticket has answered', async () => {
		api.fetchLetterTemplates.mockResolvedValue({ available: true, templates: [] })
		storeMock.fetchObject.mockReturnValue(new Promise(() => {}))

		const w = mountOn(`/tickets/${TICKET}`, TICKET)
		await flushPromises()

		expect(api.fetchLetterTemplates).toHaveBeenCalledTimes(1)
		expect(w.text()).toContain('There are no letter templates yet')
	})

	it('keeps the dialog open with the error when the letter fails', async () => {
		api.fetchLetterTemplates.mockResolvedValue({
			available: true,
			templates: [
				{ id: 'tpl-1', name: 'Appointment letter', description: '' },
			],
		})
		api.makeLetter.mockRejectedValue({
			response: { data: { message: 'filinq could not make the letter.' } },
		})

		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()
		await w.find('[data-testid="letter-make"]').trigger('click')
		await flushPromises()

		expect(w.text()).toContain('filinq could not make the letter.')
		expect(api.downloadLetter).not.toHaveBeenCalled()
		expect(w.find('[data-testid="letter-result"]').exists()).toBe(false)
	})
})

describe('LetterFromTemplateModal as board PqBriefOpstellen draws it', () => {
	it('names the client over the title and offers the client\'s tickets the letter is about', async () => {
		api.fetchLetterTemplates.mockResolvedValue({ available: true, templates: [{ id: 'tpl-1', name: 'Uitnodiging gesprek vergunning' }] })
		api.makeLetter.mockResolvedValue({ content: 'JVBERg==', filename: 'x.pdf', warnings: [], contactMomentId: 'cm-1' })
		storeMock.fetchObject.mockImplementation(async (type) => (type === 'client' ? { id: CLIENT, name: 'Sanne de Vries' } : null))
		storeMock.fetchCollection.mockImplementation(async (type) => (type === 'ticket'
			? [{ id: TICKET, reference: 'PQ-2026-0417', title: 'Evenementenvergunning straatfeest Parkstraat' }]
			: []))

		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()

		expect(w.find('.nc-dialog').attributes('data-eyebrow')).toBe('Sanne de Vries · letter')
		expect(w.find('.nc-dialog').attributes('data-width')).toBe('wizard')
		expect(w.find('[data-testid="letter-about"]').exists()).toBe(true)
		expect(w.find('[data-testid="letter-contact"]').exists()).toBe(false)
		expect(w.text()).toContain('Templates come from filinq; 1 is shared with pipelinq.')
		await w.find('[data-testid="letter-make"]').trigger('click')
		await flushPromises()
		expect(api.makeLetter).toHaveBeenCalledWith(CLIENT, { templateId: 'tpl-1', contactId: undefined, ticketId: TICKET })
	})
})

describe('the Make a letter action (caller)', () => {
	const manifest = JSON.parse(read('src/manifest.json'))
	const page = (id) => manifest.pages.find((p) => p.id === id)

	it.each(['ClientDetail', 'TicketDetail'])(
		'%s opens the letter modal, only when filinq answers available',
		(id) => {
			const action = (page(id).config.headerActions || []).find(
				(a) => a.target === 'LetterFromTemplateModal',
			)
			expect(action).toBeDefined()
			expect(action.type).toBe('open-modal')
			expect(action.label).toBe('Make a letter')
			expect(action.visibleWhen).toEqual({
				endpoint: '/apps/pipelinq/api/letters/templates',
				field: 'available',
				op: 'eq',
				value: true,
			})
		},
	)

	it('registers the modal as a modal', () => {
		const registry = read('src/registry.js')
		expect(registry).toMatch(
			/LetterFromTemplateModal:\s*{\s*kind:\s*'modal',\s*component:\s*LetterFromTemplateModal/,
		)
	})

	it('routes both endpoints', () => {
		const routes = read('appinfo/routes.php')
		expect(routes).toContain(
			"['name' => 'letter#templates', 'url' => '/api/letters/templates', 'verb' => 'GET']",
		)
		expect(routes).toContain(
			"['name' => 'letter#create', 'url' => '/api/clients/{id}/letters', 'verb' => 'POST']",
		)
	})
})

describe('LetterFromTemplateModal preview', () => {
	const ONE_TEMPLATE = {
		available: true,
		templates: [{ id: 'tpl-1', name: 'Uitnodiging gesprek vergunning', description: '' }],
	}

	it('previews the chosen template and lists what filinq filled in', async () => {
		api.fetchLetterTemplates.mockResolvedValue(ONE_TEMPLATE)
		api.previewLetter.mockResolvedValue({
			html: '<p>Geachte mevrouw De Vries,</p>',
			fields: [
				{ label: 'Aanhef', value: 'Geachte mevrouw De Vries,', source: 'op basis van het opgegeven geslacht', filled: true },
				{ label: 'Adres', value: 'Lindelaan 14', source: 'uit de klantgegevens', filled: true },
			],
			complete: true,
			warnings: [],
		})

		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()

		expect(api.previewLetter).toHaveBeenCalledWith(CLIENT, { templateId: 'tpl-1', contactId: undefined, ticketId: undefined })
		expect(w.find('[data-testid="letter-preview"]').html()).toContain('Geachte mevrouw De Vries,')
		const fields = w.find('[data-testid="letter-fields"]').text()
		expect(fields).toContain('Aanhef')
		expect(fields).toContain('Lindelaan 14, uit de klantgegevens')
		expect(w.find('[data-testid="letter-check"]').text()).toContain('All 2 fields filled in, nothing missing')
		expect(w.find('[data-testid="letter-channel"]').exists()).toBe(true)
	})

	it('names the fields that are still empty', async () => {
		api.fetchLetterTemplates.mockResolvedValue(ONE_TEMPLATE)
		api.previewLetter.mockResolvedValue({
			html: '<p>x</p>',
			fields: [
				{ label: 'Aanhef', value: 'Geachte heer,', source: '', filled: true },
				{ label: 'Afspraak', value: '', source: 'uit Afspraken', filled: false },
			],
			complete: false,
			warnings: [],
		})

		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()

		expect(w.find('[data-testid="letter-check"]').text()).toContain('Still empty: {fields}')
		expect(w.find('[data-testid="letter-fields"]').text()).toContain('Not filled in, uit Afspraken')
	})

	it('passes the client\'s next appointment, so the Afspraak field can be filled', async () => {
		api.fetchLetterTemplates.mockResolvedValue(ONE_TEMPLATE)
		storeMock.fetchCollection.mockImplementation(async (type, params) => {
			if (type === 'appointmentBooking' && params.customerId === CLIENT) {
				return [{ id: 'appt-1', startAt: '2999-10-14T10:30:00+02:00', status: 'confirmed' }]
			}
			return []
		})

		mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()

		expect(api.previewLetter).toHaveBeenLastCalledWith(CLIENT, { templateId: 'tpl-1', contactId: undefined, ticketId: undefined, appointmentId: 'appt-1' })
	})

	it('takes the appointment on the ticket the user picks', async () => {
		api.fetchLetterTemplates.mockResolvedValue(ONE_TEMPLATE)
		storeMock.fetchCollection.mockImplementation(async (type, params) => {
			if (type === 'ticket') return [{ id: 't-1', title: 'Een' }, { id: 't-2', title: 'Twee' }]
			if (type === 'appointmentBooking' && params.ticket === 't-2') {
				return [{ id: 'appt-2', startAt: '2999-10-14T10:30:00+02:00', status: 'confirmed', ticket: 't-2' }]
			}
			return []
		})

		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()
		w.vm.aboutTicketId = 't-2'
		await flushPromises()

		expect(api.previewLetter).toHaveBeenLastCalledWith(CLIENT, { templateId: 'tpl-1', contactId: undefined, ticketId: 't-2', appointmentId: 'appt-2' })
	})

	it('downloads the PDF without filing or logging it', async () => {
		api.fetchLetterTemplates.mockResolvedValue(ONE_TEMPLATE)
		api.makeLetter.mockResolvedValue({ content: 'JVBERg==', filename: 'x.pdf', warnings: [], contactMomentId: null })

		const w = mountOn(`/clients/${CLIENT}`, CLIENT)
		await flushPromises()
		await w.find('[data-testid="letter-download"]').trigger('click')
		await flushPromises()

		expect(api.makeLetter).toHaveBeenCalledWith(CLIENT, { templateId: 'tpl-1', contactId: undefined, ticketId: undefined, downloadOnly: true })
		expect(api.downloadLetter).toHaveBeenCalledTimes(1)
		expect(w.find('[data-testid="letter-result"]').exists()).toBe(false)
	})
})

