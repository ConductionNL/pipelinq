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
 * - The dossier as the resident saw it is a read-only `field-list` over the
 *   ticket's snapshot, with links, shown only on a ticket that has one. Its
 *   drawing is nextcloud-vue's (CnFieldListWidgetLinksLeadAfterSave.spec.js);
 *   this file pins the config.
 * - The answer to the customer is a `conversation` that posts to
 *   POST /api/tickets/{id}/answer (TicketAnswerServiceTest saves
 *   customerMessage, portalAnswers and awaiting_customer). It replaced
 *   CustomerReplySection, which wrote customerMessage (and awaiting_customer on
 *   "Send answer" with the wait box ticked) while keeping the rest of the ticket, since
 *   the store's save is a PUT that replaces the object.
 * - The Woo conversion is a library `handoff` section: hidden unless dossiq
 *   can take the ticket, and showing the Woo request after converting. Its
 *   behaviour is nextcloud-vue's (CnHandoffAction.spec.js); this file pins the
 *   endpoints, paths and texts pipelinq declares for it.
 *
 * The last spec pins the callers: TicketDetail declares all three and the
 * registry or the library resolves them, so the page actually renders them.
 *
 * @spec openspec/changes/questions-about-a-citizen-dossier/specs/dossier-questions/spec.md#requirement-the-employee-sees-what-the-resident-asked-about-req-qcd-006
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
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
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render() {
			return h('label', [
				h('input', {
					type: 'checkbox',
					checked: this.modelValue,
					onChange: (event) =>
						this.$emit('update:modelValue', event.target.checked),
				}),
				this.$slots.default?.(),
			])
		},
	},
	NcSelect: { name: 'NcSelect', render: () => h('div') },
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

describe('the dossier snapshot', () => {
	const section = () =>
		JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		)
			.pages.find((p) => p.id === 'TicketDetail')
			.config.bodyWidgets.find((w) => w.id === 'dossier-snapshot')

	it('lists the snapshot items as links under the dossier title (REQ-QCD-006)', () => {
		const { type, content } = section()
		expect(type).toBe('field-list')
		expect(content.field).toBe('subjectReference.items')
		expect(content.readOnly).toBe(true)
		expect(content.row).toEqual({ title: 'title', href: 'url' })
		expect(content.lead).toBe('Question about the dossier "{subjectReference.title}"')
		expect(question.subjectReference.items).toHaveLength(3)
	})

	it('shows nothing on a ticket without a snapshot', () => {
		expect(section().visibleWhen).toEqual({ field: 'subjectReference', op: 'notEmpty' })
	})

	it('reads the ticket only, never the dossier', () => {
		expect(JSON.stringify(section())).not.toContain('opencatalogi')
	})
})

describe('the answer to the customer', () => {
	const section = () =>
		JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		)
			.pages.find((p) => p.id === 'TicketDetail')
			.config.bodyWidgets.find((w) => w.id === 'customer-reply')

	it('names the frame "Customer contact" once, in the manifest', () => {
		expect(section().title).toBe('Customer contact')
		expect(section().content.title).toBeUndefined()
	})

	it("shows the resident's replies and the answers as one thread, oldest first (REQ-QCD-007)", () => {
		const { content } = section()
		expect(content.fields).toEqual([
			{ field: 'portalReplies', side: 'them' },
			{ field: 'portalAnswers', side: 'us' },
		])
		expect(content.textField).toBe('message')
		expect(content.timeField).toBe('createdAt')
	})

	it('saves the answer on the server and can wait for a reply (REQ-QCD-007)', () => {
		const { content } = section()
		expect(content.endpoint).toBe('/apps/pipelinq/api/tickets/@objectId/answer')
		expect(content.replyOptions).toEqual([
			{ key: 'waitForCustomer', label: 'Also set the ticket to waiting for the customer', default: true },
		])
		expect(content.sendLabel).toBe('Send answer')
	})

	it('offers the portal saved replies, filled for this ticket', () => {
		expect(section().content.templates).toEqual({
			url: '/apps/pipelinq/api/saved-replies',
			params: { channel: 'portal', ticket: '@objectId' },
			inputLabel: 'Saved reply',
			textField: 'body',
		})
	})

	it('is not offered on a contact moment', () => {
		expect(section().visibleWhen).toEqual({ field: 'ticketType', op: 'in', value: ['request', 'complaint'] })
	})
})

describe('Woo conversion handoff', () => {
	const ticketDetail = () =>
		JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		).pages.find((p) => p.id === 'TicketDetail')

	it('asks dossiq whether it can take the ticket and hides otherwise (REQ-QCD-008)', () => {
		const section = ticketDetail().config.bodyWidgets.find(
			(w) => w.id === 'woo-conversion',
		)

		expect(section.type).toBe('handoff')
		expect(section.placement).toBe('after-data')
		expect(section.content.availability.url).toBe(
			'/apps/pipelinq/api/tickets/@objectId/woo-request/availability',
		)
		expect(section.content.canPath).toBe('canConvert')
	})

	it('converts and shows the Woo request with its link (REQ-QCD-008)', () => {
		const { content } = ticketDetail().config.bodyWidgets.find(
			(w) => w.id === 'woo-conversion',
		)

		expect(content.action).toEqual({
			url: '/apps/pipelinq/api/tickets/@objectId/woo-request',
			label: 'Convert to Woo request',
		})
		expect(content.donePath).toBe('status')
		expect(content.doneValue).toBe('converted')
		expect(content.linkPath).toBe('caseUrl')
		expect(content.labels.done).toBe('This question is now a Woo request.')
		expect(content.labels.link).toBe('Open the Woo request')
		expect(content.errors.map['intake-failed'].reload).toBe(true)
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
	it('declares the three sections and the registry or the library resolves them', () => {
		const manifest = JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		)
		const page = manifest.pages.find((p) => p.id === 'TicketDetail')
		expect(
			(page.config.bodyWidgets || []).find((w) => w.id === 'customer-reply').type,
		).toBe('conversation')
		const woo = (page.config.bodyWidgets || []).find(
			(w) => w.id === 'woo-conversion',
		)
		expect(woo.type).toBe('handoff')
		expect(
			(page.config.bodyWidgets || []).find((w) => w.id === 'dossier-snapshot').type,
		).toBe('field-list')

	})
})
