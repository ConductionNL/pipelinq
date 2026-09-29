// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Suggested colleagues on the ticket page (pipelinq#2039). RoutingSuggestionPanel
 * ranked colleagues by skill, availability and workload, but no page mounted
 * it and nothing handled its `assigned` event, so a handler never saw a
 * suggestion and an Assign click could not have saved anything.
 *
 * These specs mount the section with the HTTP layer and the object store
 * replaced: the ranked list renders for the record, an Assign click writes
 * the colleague into `assignee` while keeping the rest of the record (the
 * store's save is a PUT that replaces the object), marks the colleague as
 * assigned and hands the saved record to the page, and a failed save says so.
 * The last spec pins the caller: TicketDetail declares the section and the
 * registry resolves it, so the page actually renders it.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-routing-ui/tasks.md#task-1
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h, ref } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn() }))
const storeMock = vi.hoisted(() => ({ fetchObject: vi.fn(), saveObject: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
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
vi.mock('vue-material-design-icons/Refresh.vue', () => ({
	default: { name: 'Refresh', render: () => h('span') },
}))
vi.mock('vue-material-design-icons/Check.vue', () => ({
	default: { name: 'Check', render: () => h('span') },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		render() {
			return h(
				'button',
				{ onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		render() {
			return h('div', { class: 'note-' + this.type }, this.$slots.default?.())
		},
	},
}))

const { default: RoutingSuggestionSection } =
	await import('../../src/components/RoutingSuggestionSection.vue')

const ticket = {
	id: 't-1',
	title: 'Parkeervergunning aanvragen',
	ticketType: 'request',
	category: 'vergunningen',
	status: 'new',
	assignee: '',
}

/**
 * Mount the section for ticket t-1 and let the suggestions load.
 *
 * @param {object} [props] Extra props for the section.
 * @param {object} [provide] What the detail page provides.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountSection(props = {}, provide = {}) {
	const wrapper = mount(RoutingSuggestionSection, {
		props: { objectId: 't-1', category: 'vergunningen', ...props },
		global: { mocks: { t: (app, text) => text }, provide },
	})
	await flushPromises()
	return wrapper
}

describe('RoutingSuggestionSection', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		storeMock.fetchObject.mockReset()
		storeMock.saveObject.mockReset()
		axiosMock.get.mockResolvedValue({
			data: {
				suggestions: [
					{
						userId: 'anna',
						displayName: 'Anna de Vries',
						workload: 2,
						maxConcurrent: 10,
						matchedSkill: 'Vergunningen',
					},
				],
				atCapacity: 1,
			},
		})
		storeMock.fetchObject.mockResolvedValue({ ...ticket })
		storeMock.saveObject.mockImplementation((type, data) =>
			Promise.resolve(data),
		)
	})

	it('shows the ranked colleagues for the ticket it is mounted on', async () => {
		const wrapper = await mountSection()

		expect(axiosMock.get).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/routing/suggestions',
			{ params: { entityType: 'request', entityId: 't-1' } },
		)
		expect(wrapper.text()).toContain('Anna de Vries')
	})

	it('writes the chosen colleague into the assignee and keeps the rest of the ticket', async () => {
		const setObject = vi.fn()
		const wrapper = await mountSection({}, { cnSectionContext: ref({ setObject }) })

		const assign = wrapper.findAll('button').find((b) => b.text() === 'Assign')
		await assign.trigger('click')
		await flushPromises()

		expect(storeMock.fetchObject).toHaveBeenCalledWith('ticket', 't-1')
		expect(storeMock.saveObject).toHaveBeenCalledTimes(1)
		const [type, payload] = storeMock.saveObject.mock.calls[0]
		expect(type).toBe('ticket')
		expect(payload).toEqual({ ...ticket, assignee: 'anna' })
		expect(wrapper.find('.agent-assigned').text()).toBe('Assigned')
		expect(wrapper.findAll('button').some((b) => b.text() === 'Assign')).toBe(false)
		expect(setObject).toHaveBeenCalledWith({ ...ticket, assignee: 'anna' })
	})

	it('shows the colleague the ticket is already assigned to as assigned', async () => {
		const wrapper = await mountSection({ assignee: 'anna' })

		expect(wrapper.find('.agent-assigned').text()).toBe('Assigned')
		expect(wrapper.findAll('button').some((b) => b.text() === 'Assign')).toBe(false)
	})

	it('says so when the assignee does not save', async () => {
		storeMock.saveObject.mockResolvedValue(null)
		const wrapper = await mountSection()

		await wrapper
			.findAll('button')
			.find((b) => b.text() === 'Assign')
			.trigger('click')
		await flushPromises()

		expect(wrapper.find('.note-error').text()).toContain(
			'Could not save the assignee.',
		)
	})

	it('is declared on TicketDetail and resolved by the registry', () => {
		const manifest = JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		)
		const page = manifest.pages.find((p) => p.id === 'TicketDetail')
		const widget = (page.config.bodyWidgets || []).find(
			(w) => w.component === 'RoutingSuggestionSection',
		)
		expect(widget).toBeTruthy()
		expect(widget.props.objectId).toBe('@objectId')

		const registry = readFileSync(
			resolve(__dirname, '../../src/registry.js'),
			'utf8',
		)
		expect(registry).toMatch(
			/RoutingSuggestionSection: \{\n\t\tkind: 'section',\n\t\tcomponent: RoutingSuggestionSection,/,
		)
	})

	// pipelinq#2049: the lead half. A lead now carries a category, LeadDetail
	// mounts the section for it, and an Assign click writes the lead.
	it('is declared on LeadDetail for the lead and its category', () => {
		const manifest = JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		)
		const page = manifest.pages.find((p) => p.id === 'LeadDetail')
		const widget = (page.config.bodyWidgets || []).find(
			(w) => w.component === 'RoutingSuggestionSection',
		)
		expect(widget).toBeTruthy()
		expect(widget.props).toEqual({
			objectId: '@objectId',
			category: '@object.category',
			entityType: 'lead',
			objectType: 'lead',
			assignee: '@object.assignee',
		})
	})

	it('asks for lead suggestions and writes the chosen colleague into the lead', async () => {
		const lead = {
			id: 'l-1',
			title: 'Parkeerbeheer',
			category: 'vergunningen',
			assignee: '',
		}
		storeMock.fetchObject.mockResolvedValue({ ...lead })
		const wrapper = mount(RoutingSuggestionSection, {
			props: {
				objectId: 'l-1',
				category: 'vergunningen',
				entityType: 'lead',
				objectType: 'lead',
			},
			global: { mocks: { t: (app, text) => text } },
		})
		await flushPromises()

		expect(axiosMock.get).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/routing/suggestions',
			{ params: { entityType: 'lead', entityId: 'l-1' } },
		)

		await wrapper
			.findAll('button')
			.find((b) => b.text() === 'Assign')
			.trigger('click')
		await flushPromises()

		expect(storeMock.fetchObject).toHaveBeenCalledWith('lead', 'l-1')
		expect(storeMock.saveObject).toHaveBeenCalledWith('lead', {
			...lead,
			assignee: 'anna',
		})
	})

	it('lets a lead carry a category on the form and the list', () => {
		const schema = JSON.parse(
			readFileSync(
				resolve(__dirname, '../../lib/Settings/pipelinq_register.json'),
				'utf8',
			),
		).components.schemas.lead
		expect(schema.properties.category.type).toBe('string')
		expect(schema.required || []).not.toContain('category')

		const form = readFileSync(
			resolve(__dirname, '../../src/views/leads/LeadForm.vue'),
			'utf8',
		)
		expect(form).toContain(':modelValue="form.category"')
		expect(form).toMatch(/category: this\.lead\.category/)

		const manifest = JSON.parse(
			readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
		)
		const leads = manifest.pages.find((p) => p.id === 'Leads')
		expect(leads.config.columns).toContain('category')
	})
})
