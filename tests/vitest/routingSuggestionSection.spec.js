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
 * store's save is a PUT that replaces the object), and a failed save says so.
 * The last spec pins the caller: TicketDetail declares the section and the
 * registry resolves it, so the page actually renders it.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-routing-ui/tasks.md#task-1
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

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
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		render() {
			return h('button', { onClick: () => this.$emit('click') }, this.$slots.default?.())
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

const { default: RoutingSuggestionSection } = await import(
	'../../src/components/RoutingSuggestionSection.vue'
)

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
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountSection() {
	const wrapper = mount(RoutingSuggestionSection, {
		props: { objectId: 't-1', category: 'vergunningen' },
		global: { mocks: { t: (app, text) => text } },
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
					{ userId: 'anna', displayName: 'Anna de Vries', workload: 2, maxConcurrent: 10, matchedSkill: 'Vergunningen' },
				],
				atCapacity: 1,
			},
		})
		storeMock.fetchObject.mockResolvedValue({ ...ticket })
		storeMock.saveObject.mockImplementation((type, data) => Promise.resolve(data))
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
		const wrapper = await mountSection()

		const assign = wrapper.findAll('button').find((b) => b.text() === 'Assign')
		await assign.trigger('click')
		await flushPromises()

		expect(storeMock.fetchObject).toHaveBeenCalledWith('ticket', 't-1')
		expect(storeMock.saveObject).toHaveBeenCalledTimes(1)
		const [type, payload] = storeMock.saveObject.mock.calls[0]
		expect(type).toBe('ticket')
		expect(payload).toEqual({ ...ticket, assignee: 'anna' })
		expect(wrapper.text()).toContain('Assigned to anna.')
	})

	it('says so when the assignee does not save', async () => {
		storeMock.saveObject.mockResolvedValue(null)
		const wrapper = await mountSection()

		await wrapper.findAll('button').find((b) => b.text() === 'Assign').trigger('click')
		await flushPromises()

		expect(wrapper.find('.note-error').text()).toContain('Could not save the assignee.')
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

		const registry = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')
		expect(registry).toMatch(/RoutingSuggestionSection: \{\n\t\tkind: 'section',\n\t\tcomponent: RoutingSuggestionSection,/)
	})
})
