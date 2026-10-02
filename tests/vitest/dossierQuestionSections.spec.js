// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The employee side of a question about a resident's Woo dossier
 * (questions-about-a-citizen-dossier, hydra woo-citizen-journey J4). Three
 * sections on TicketDetail, each mounted with the HTTP layer and the object
 * store replaced:
 *
 * - DossierSnapshotSection lists the dossier as the resident saw it, with
 *   links, and renders nothing for an ordinary ticket.
 * - CustomerReplySection writes customerMessage (and awaiting_customer on
 *   "Save and wait for a reply") while keeping the rest of the ticket, since
 *   the store's save is a PUT that replaces the object.
 * - WooConversionSection is hidden unless dossiq can take the ticket, and
 *   shows the case after converting.
 *
 * The last spec pins the callers: TicketDetail declares all three and the
 * registry resolves them, so the page actually renders them.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-sees-what-the-resident-asked-about-req-qcd-006
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const storeMock = vi.hoisted(() => ({ fetchObject: vi.fn(), saveObject: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params) =>
		'/index.php'
		+ String(path).replace(/\{(\w+)\}/g, (whole, key) =>
			params && key in params ? params[key] : whole,
		),
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
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{ disabled: this.disabled, onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		render() {
			return h('div', { class: 'note-' + this.type }, this.$slots.default?.())
		},
	},
	NcTextArea: {
		name: 'NcTextArea',
		props: ['modelValue', 'label', 'helperText', 'resize'],
		emits: ['update:modelValue'],
		render() {
			return h('textarea', {
				'aria-label': this.label,
				value: this.modelValue,
				onInput: (event) =>
					this.$emit('update:modelValue', event.target.value),
			})
		},
	},
}))

const { default: DossierSnapshotSection } =
	await import('../../src/components/DossierSnapshotSection.vue')
const { default: CustomerReplySection } =
	await import('../../src/components/CustomerReplySection.vue')
const { default: WooConversionSection } =
	await import('../../src/components/WooConversionSection.vue')

/** A question asked from a dossier with three items, as DossierQuestionService writes it. */
const question = {
	id: 't-9',
	ticketType: 'request',
	title: 'Windpark Noord',
	description: 'Wanneer valt het besluit over de vergunning?',
	channel: 'portal',
	status: 'in_progress',
	portalSubject: 'subj-7f3a',
	subjectReference: {
		type: 'opencatalogi.collection',
		id: '5b0e8c55-1f7e-4a53-9a0e-2f1f0c1d9a11',
		title: 'Windpark Noord',
		items: [
			{
				title: 'Besluit omgevingsvergunning windpark',
				url: 'https://gemeente.example/index.php/apps/opencatalogi/api/search/p-1',
			},
			{
				title: 'Advies Omgevingsdienst',
				url: 'https://gemeente.example/index.php/apps/opencatalogi/api/search/p-2',
			},
			{ title: 'Mijn eigen notitie', url: '' },
		],
	},
	portalReplies: [
		{ message: 'Tweede reactie', createdAt: '2026-09-30T09:00:00+00:00' },
		{ message: 'Eerste reactie', createdAt: '2026-09-29T09:00:00+00:00' },
	],
	notes: 'intern',
}

/**
 * Mount a section for ticket t-9 and let it load.
 *
 * @param {object} component The section.
 * @return {Promise<object>} The wrapper.
 */
async function mountFor(component) {
	const wrapper = mount(component, { props: { objectId: 't-9' } })
	await flushPromises()
	return wrapper
}

describe('DossierSnapshotSection', () => {
	beforeEach(() => {
		storeMock.fetchObject.mockReset()
	})

	it('shows the dossier title and its three items with links (REQ-QCD-006)', async () => {
		storeMock.fetchObject.mockResolvedValue({ ...question })
		const wrapper = await mountFor(DossierSnapshotSection)

		expect(storeMock.fetchObject).toHaveBeenCalledWith('ticket', 't-9')
		expect(wrapper.text()).toContain(
			'Question about the dossier "Windpark Noord"',
		)
		const items = wrapper.findAll('li')
		expect(items).toHaveLength(3)
		const links = wrapper.findAll('a')
		expect(links.map((a) => a.attributes('href'))).toEqual([
			'https://gemeente.example/index.php/apps/opencatalogi/api/search/p-1',
			'https://gemeente.example/index.php/apps/opencatalogi/api/search/p-2',
		])
		expect(links[0].attributes('rel')).toBe('noopener noreferrer')
		expect(items[2].text()).toBe('Mijn eigen notitie')
	})

	it('renders nothing for a ticket without a snapshot', async () => {
		const { subjectReference, ...ordinary } = question
		storeMock.fetchObject.mockResolvedValue(ordinary)
		const wrapper = await mountFor(DossierSnapshotSection)

		expect(subjectReference).toBeTruthy()
		expect(wrapper.find('section').exists()).toBe(false)
	})

	it('never turns a non-http value into a link', async () => {
		storeMock.fetchObject.mockResolvedValue({
			...question,
			subjectReference: {
				...question.subjectReference,
				items: [{ title: 'Gevaarlijk', url: 'javascript:alert(1)' }],
			},
		})
		const wrapper = await mountFor(DossierSnapshotSection)

		expect(wrapper.find('a').exists()).toBe(false)
		expect(wrapper.text()).toContain('Gevaarlijk')
	})
})

describe('CustomerReplySection', () => {
	beforeEach(() => {
		storeMock.fetchObject.mockReset()
		storeMock.saveObject.mockReset()
		storeMock.fetchObject.mockResolvedValue({ ...question })
		storeMock.saveObject.mockImplementation((type, data) =>
			Promise.resolve(data),
		)
	})

	it('leaves its title to the widget frame, so the heading shows once', async () => {
		const wrapper = await mountFor(CustomerReplySection)

		// The manifest's bodyWidget title "Answer to the customer" is the
		// heading CnDetailPage renders above the section. A heading of the
		// section's own printed it a second time (Woo round 3).
		expect(wrapper.find('h1, h2, h3, h4, h5, h6').exists()).toBe(false)
		expect(wrapper.text()).not.toContain('Answer to the customer')
	})

	it('lists the portal replies oldest first (REQ-QCD-007)', async () => {
		const wrapper = await mountFor(CustomerReplySection)

		const replies = wrapper.findAll('ol li').map((li) => li.text())
		expect(replies[0]).toContain('Eerste reactie')
		expect(replies[1]).toContain('Tweede reactie')
	})

	it('saves the answer and waits for a reply, keeping the rest of the ticket (REQ-QCD-007)', async () => {
		const wrapper = await mountFor(CustomerReplySection)

		await wrapper.find('textarea').setValue('Het besluit valt in november.')
		await wrapper
			.findAll('button')
			.find((b) => b.text() === 'Save and wait for a reply')
			.trigger('click')
		await flushPromises()

		expect(storeMock.saveObject).toHaveBeenCalledTimes(1)
		const [type, payload] = storeMock.saveObject.mock.calls[0]
		expect(type).toBe('ticket')
		expect(payload).toEqual({
			...question,
			customerMessage: 'Het besluit valt in november.',
			portalAnswers: [
				{
					message: 'Het besluit valt in november.',
					createdAt: expect.stringMatching(/^\d{4}-\d{2}-\d{2}T/),
				},
			],
			status: 'awaiting_customer',
		})
		expect(wrapper.find('.note-success').exists()).toBe(true)
	})

	it('keeps every answer with its date, and saving the same answer again adds none (REQ-QDP-001)', async () => {
		storeMock.fetchObject.mockResolvedValue({
			...question,
			customerMessage: 'Eerste antwoord',
			portalAnswers: [
				{ message: 'Eerste antwoord', createdAt: '2026-09-28T10:00:00Z' },
			],
		})
		const wrapper = await mountFor(CustomerReplySection)

		await wrapper
			.findAll('button')
			.find((b) => b.text() === 'Save answer')
			.trigger('click')
		await flushPromises()
		expect(storeMock.saveObject.mock.calls[0][1].portalAnswers).toHaveLength(1)

		await wrapper.find('textarea').setValue('Tweede antwoord')
		await wrapper
			.findAll('button')
			.find((b) => b.text() === 'Save answer')
			.trigger('click')
		await flushPromises()
		const answers = storeMock.saveObject.mock.calls[1][1].portalAnswers
		expect(answers.map((a) => a.message)).toEqual([
			'Eerste antwoord',
			'Tweede antwoord',
		])
		expect(answers[0].createdAt).toBe('2026-09-28T10:00:00Z')
	})

	it('"Save answer" leaves the status alone', async () => {
		const wrapper = await mountFor(CustomerReplySection)

		await wrapper.find('textarea').setValue('Antwoord')
		await wrapper
			.findAll('button')
			.find((b) => b.text() === 'Save answer')
			.trigger('click')
		await flushPromises()

		expect(storeMock.saveObject.mock.calls[0][1].status).toBe('in_progress')
		expect(storeMock.saveObject.mock.calls[0][1].customerMessage).toBe(
			'Antwoord',
		)
	})

	it('says so when the answer does not save', async () => {
		storeMock.saveObject.mockResolvedValue(null)
		const wrapper = await mountFor(CustomerReplySection)

		await wrapper.find('textarea').setValue('Antwoord')
		await wrapper
			.findAll('button')
			.find((b) => b.text() === 'Save answer')
			.trigger('click')
		await flushPromises()

		expect(wrapper.find('.note-error').text()).toContain(
			'Could not save the answer.',
		)
	})

	it('is not offered on a contact moment', async () => {
		storeMock.fetchObject.mockResolvedValue({
			...question,
			ticketType: 'interaction',
		})
		const wrapper = await mountFor(CustomerReplySection)

		expect(wrapper.find('section').exists()).toBe(false)
	})
})

describe('WooConversionSection', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('is hidden when dossiq cannot take the ticket (REQ-QCD-008)', async () => {
		axiosMock.get.mockResolvedValue({
			data: {
				available: false,
				canConvert: false,
				status: 'in_progress',
				caseReference: '',
			},
		})
		const wrapper = await mountFor(WooConversionSection)

		expect(axiosMock.get).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/tickets/t-9/woo-request/availability',
		)
		expect(wrapper.find('button').exists()).toBe(false)
	})

	it('converts and shows the Woo request (REQ-QCD-008)', async () => {
		axiosMock.get.mockResolvedValue({
			data: {
				available: true,
				canConvert: true,
				status: 'in_progress',
				caseReference: '',
			},
		})
		axiosMock.post.mockResolvedValue({
			data: {
				status: 'converted',
				caseReference: 'c-42',
				caseUrl: '/index.php/apps/dossiq/#/cases/c-42',
			},
		})
		const wrapper = await mountFor(WooConversionSection)

		const button = wrapper.find('button')
		expect(button.text()).toBe('Convert to Woo request')
		await button.trigger('click')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/tickets/t-9/woo-request',
			{},
		)
		expect(wrapper.find('button').exists()).toBe(false)
		expect(wrapper.text()).toContain('This question is now a Woo request.')
		expect(wrapper.find('a').attributes('href')).toBe(
			'/index.php/apps/dossiq/#/cases/c-42',
		)
	})
})

describe('Tickets index', () => {
	it('opens on the newest tickets first', () => {
		const manifest = JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		)
		const page = manifest.pages.find((p) => p.id === 'Tickets')

		// Without a default the list came in creation order, oldest first, so
		// a new ticket sat on the last page (Woo round 3).
		expect(page.config.sortKey).toBe('occurredAt')
		expect(page.config.sortOrder).toBe('desc')
	})
})

describe('TicketDetail', () => {
	it('declares the three sections and the registry resolves them', () => {
		const manifest = JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		)
		const page = manifest.pages.find((p) => p.id === 'TicketDetail')
		const registry = readFileSync(
			resolve(__dirname, '../../src/registry.js'),
			'utf8',
		)

		for (const name of [
			'DossierSnapshotSection',
			'CustomerReplySection',
			'WooConversionSection',
		]) {
			const widget = (page.config.bodyWidgets || []).find(
				(w) => w.component === name,
			)
			expect(widget, name).toBeTruthy()
			expect(widget.props.objectId).toBe('@objectId')
			expect(registry).toMatch(
				new RegExp(
					name
						+ ": \\{\\n\\t\\tkind: 'section',\\n\\t\\tcomponent: "
						+ name
						+ ',',
				),
			)
		}
	})
})
