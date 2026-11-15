// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An agent's workload over the answer shapes OpenRegister can give: a bare
 * list, a falsy body, a body without a list or a total; and dropping a
 * prospect from the prospect store's list.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-4
 */
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const objects = vi.hoisted(() => ({ registry: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({ objectTypeRegistry: objects.registry }) }))

const { useAgentProfilesStore } = await import('../../src/store/modules/agentProfiles.js')
const { useProspectStore } = await import('../../src/store/modules/prospect.js')

beforeEach(() => {
	setActivePinia(createPinia())
	globalThis.OC = { requestToken: 'tok' }
	objects.registry = { ticket: { register: 'r', schema: 't' } }
})

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * Answer each request by the first URL fragment it contains.
 *
 * @param {object} answers Body per URL fragment, all with ok: true.
 */
function answer(answers) {
	vi.stubGlobal('fetch', vi.fn(async (url) => {
		const key = Object.keys(answers).find((k) => url.includes(k))
		return { ok: true, json: async () => answers[key] }
	}))
}

describe('getWorkload over answer shapes', () => {
	it('counts a bare list of requests by its open entries', async () => {
		answer({
			'_limit=1': [{ status: 'new' }],
			'_limit=200': [{ status: 'new' }, { status: 'in_progress' }, { status: 'completed' }],
		})
		expect(await useAgentProfilesStore().getWorkload('u')).toBe(2)
		expect(fetch).toHaveBeenCalledTimes(2)
	})

	it('treats a falsy body as an empty list, and an empty open list as nothing', async () => {
		answer({ '_limit=1': false, '_limit=200': false })
		expect(await useAgentProfilesStore().getWorkload('u')).toBe(0)
		expect(fetch).toHaveBeenCalledTimes(2)
	})

	it('counts a body without a list and without a total as nothing, without asking for the list', async () => {
		answer({ '_limit=1': { results: 'none' } })
		expect(await useAgentProfilesStore().getWorkload('u')).toBe(0)
		expect(fetch).toHaveBeenCalledTimes(1)
	})

	it('counts the open requests directly over a results list', async () => {
		answer({ '_limit=200': { results: [{ status: 'rejected' }, { status: 'converted' }, { status: 'waiting' }] } })
		expect(await useAgentProfilesStore()._countOpenRequests({ register: 'r', schema: 't' }, 'a&b')).toBe(1)
		expect(fetch.mock.calls[0][0]).toBe('/index.php/apps/openregister/api/objects/r/t?ticketType=request&assignee=a%26b&_limit=200')
	})
})

describe('useProspectStore.removeProspect', () => {
	it('drops only the prospect with that KVK number', () => {
		const store = useProspectStore()
		store.prospects = [{ kvkNumber: '1' }, { kvkNumber: '2' }, { kvkNumber: '1' }, { kvkNumber: '3' }]
		store.removeProspect('1')
		expect(store.prospects).toEqual([{ kvkNumber: '2' }, { kvkNumber: '3' }])
		store.removeProspect('missing')
		expect(store.prospects).toEqual([{ kvkNumber: '2' }, { kvkNumber: '3' }])
	})
})
