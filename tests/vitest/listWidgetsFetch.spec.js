// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The compact dashboard list widgets (deals overview, my leads, recent
 * activities): the objects each asks OpenRegister for, the rows it shapes
 * from them, and what it does when a request fails.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-29
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const stores = vi.hoisted(() => ({ registry: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: async () => ({ objectStore: { objectTypeRegistry: stores.registry } }) }))
vi.mock('../../src/services/localeUtils.js', () => ({ formatCurrency: (v) => `EUR ${v}`, formatDate: (v) => `d:${v}` }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: { name: 'CnDataTable', props: ['rows', 'loading'], template: '<div class="table"><slot name="footer" /></div>' },
	openRowTarget: vi.fn(),
}))

globalThis.t = (app, text) => text
const mountOptions = { props: { title: 'W' }, global: { mocks: { t: globalThis.t } } }

let widgets

beforeAll(async () => {
	widgets = {
		deals: (await import('../../src/views/widgets/DealsOverviewWidget.vue')).default,
		mine: (await import('../../src/views/widgets/MyLeadsWidget.vue')).default,
		recent: (await import('../../src/views/widgets/RecentActivitiesWidget.vue')).default,
	}
}, 60000)

let answers
const signedIn = { requestToken: 'tok', getCurrentUser: () => ({ uid: 'anna' }) }

beforeEach(() => {
	window.OC = signedIn
	stores.registry = { lead: { register: 'r', schema: 'lead' }, client: { register: 'r', schema: 'client' }, ticket: { register: 'r', schema: 'ticket' } }
	answers = {}
	vi.stubGlobal('fetch', vi.fn(async (url) => {
		const schema = url.split('?')[0].split('/').pop()
		const answer = answers[schema] ?? { ok: true, body: { results: [] } }
		return { ok: answer.ok, json: async () => answer.body }
	}))
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

const headers = { headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' } }

describe('DealsOverviewWidget', () => {
	it('asks for the newest leads and the clients, and writes the client, value and stage', async () => {
		answers.lead = { ok: true, body: { results: [{ id: 'l1', title: 'Ovens', client: 'c1', value: 5, stage: 'New' }, { id: 'l2', clientId: 'c2' }, { id: 'l3' }] } }
		answers.client = { ok: true, body: [{ id: 'c1', name: 'Bakker' }, { id: 'c2', title: 'Delta' }] }
		const wrapper = mount(widgets.deals, mountOptions)
		await flushPromises()
		expect(fetch.mock.calls).toEqual([
			['/index.php/apps/openregister/api/objects/r/lead?_limit=20&_order=created_at%3Adesc', headers],
			['/index.php/apps/openregister/api/objects/r/client?_limit=500', headers],
		])
		expect(wrapper.vm.items).toEqual([
			{ id: 'l1', mainText: 'Ovens', subText: 'Bakker · EUR 5 · New' },
			{ id: 'l2', mainText: 'Untitled lead', subText: 'Delta' },
			{ id: 'l3', mainText: 'Untitled lead', subText: '' },
		])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('skips unregistered types, and logs a failed request', async () => {
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		stores.registry = { lead: { register: 'r', schema: 'lead' } }
		answers.lead = { ok: false, body: {} }
		const wrapper = mount(widgets.deals, mountOptions)
		await flushPromises()
		expect(fetch).toHaveBeenCalledTimes(1)
		expect(error).toHaveBeenCalledWith('DealsOverviewWidget fetch error:', new Error('Failed to fetch lead'))
		expect(wrapper.vm.leads).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('reads a body without results as the list itself, and an empty one as none', async () => {
		stores.registry = { lead: { register: 'r', schema: 'lead' } }
		answers.lead = { ok: true, body: null }
		const wrapper = mount(widgets.deals, mountOptions)
		await flushPromises()
		expect(wrapper.vm.leads).toEqual([])
	})
})

describe('MyLeadsWidget', () => {
	it('asks for the leads assigned to the current user', async () => {
		answers.lead = { ok: true, body: { results: [{ id: 'l1', title: 'Ovens', priority: 'high', stage: 'New', expectedCloseDate: '2000-01-01' }, { id: 'l2', expectedCloseDate: '2999-01-01' }] } }
		const wrapper = mount(widgets.mine, mountOptions)
		await flushPromises()
		expect(fetch.mock.calls).toEqual([['/index.php/apps/openregister/api/objects/r/lead?assignee=anna&_limit=20', headers]])
		expect(wrapper.vm.items.map((i) => i.subText)).toEqual(['high · New · ⚠ d:2000-01-01', 'd:2999-01-01'])
	})

	it('asks for nothing without a user, and logs a failed request', async () => {
		window.OC = { requestToken: 'tok', getCurrentUser: () => null }
		mount(widgets.mine, mountOptions)
		await flushPromises()
		expect(fetch).not.toHaveBeenCalled()
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		window.OC = signedIn
		answers.lead = { ok: false, body: {} }
		mount(widgets.mine, mountOptions)
		await flushPromises()
		expect(error).toHaveBeenCalledWith('MyLeadsWidget fetch error:', new Error('Failed to fetch lead'))
	})
})

describe('RecentActivitiesWidget', () => {
	it('merges the latest leads and requests, newest first', async () => {
		answers.lead = { ok: true, body: { results: [{ id: 'l1', title: 'Lead', updated: '2026-01-02T00:00:00Z' }, { id: 'l2', created: '2026-01-04T00:00:00Z' }] } }
		answers.ticket = { ok: true, body: { results: [{ id: 't1', title: 'Req', dateModified: '2026-01-03T00:00:00Z' }, { id: 't2' }] } }
		const wrapper = mount(widgets.recent, mountOptions)
		await flushPromises()
		expect(fetch.mock.calls).toEqual([
			['/index.php/apps/openregister/api/objects/r/lead?_limit=10&_order=updated%3Adesc', headers],
			['/index.php/apps/openregister/api/objects/r/ticket?ticketType=request&_limit=10&_order=updated%3Adesc', headers],
		])
		expect(wrapper.vm.activities.map((a) => [a.entityType, a.id, a.modified])).toEqual([
			['lead', 'l2', '2026-01-04T00:00:00Z'],
			['request', 't1', '2026-01-03T00:00:00Z'],
			['lead', 'l1', '2026-01-02T00:00:00Z'],
			['request', 't2', undefined],
		])
	})

	it('logs a failed request', async () => {
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		answers.ticket = { ok: false, body: {} }
		const wrapper = mount(widgets.recent, mountOptions)
		await flushPromises()
		expect(error).toHaveBeenCalledWith('RecentActivitiesWidget fetch error:', new Error('Failed to fetch ticket'))
		expect(wrapper.vm.activities).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})
})
