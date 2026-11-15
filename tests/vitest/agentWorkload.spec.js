// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An agent's workload: the open requests and the open leads assigned to them.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-4
 */
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const objects = vi.hoisted(() => ({ registry: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({ objectTypeRegistry: objects.registry }) }))

const { useAgentProfilesStore } = await import('../../src/store/modules/agentProfiles.js')

beforeEach(() => {
	setActivePinia(createPinia())
	globalThis.OC = { requestToken: 'tok' }
	objects.registry = { ticket: { register: 'r', schema: 't' }, lead: { register: 'r', schema: 'l' } }
})

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * @param {object} answers Answer per URL fragment: `{ ok, body }` or an Error.
 */
function answer(answers) {
	vi.stubGlobal('fetch', vi.fn(async (url) => {
		const key = Object.keys(answers).find((k) => url.includes(k))
		const next = answers[key]
		if (next instanceof Error) throw next
		return { ok: next.ok, json: async () => next.body }
	}))
}

describe('getWorkload', () => {
	it('counts the open requests over the first two hundred, plus the open leads', async () => {
		answer({
			'/t?ticketType=request&assignee=a%20b&_limit=1': { ok: true, body: { results: [{}] } },
			'/t?ticketType=request&assignee=a%20b&_limit=200': { ok: true, body: { results: [{ status: 'new' }, { status: 'completed' }, { status: 'rejected' }, { status: 'converted' }, {}] } },
			'/l?assignee=a%20b&status=open&_limit=1': { ok: true, body: { total: 4 } },
		})
		expect(await useAgentProfilesStore().getWorkload('a b')).toBe(6)
		expect(fetch.mock.calls.map((c) => c[0])).toEqual([
			'/index.php/apps/openregister/api/objects/r/t?ticketType=request&assignee=a%20b&_limit=1',
			'/index.php/apps/openregister/api/objects/r/t?ticketType=request&assignee=a%20b&_limit=200',
			'/index.php/apps/openregister/api/objects/r/l?assignee=a%20b&status=open&_limit=1',
		])
		expect(fetch.mock.calls[0][1]).toEqual({ headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' } })
	})

	it('reads a request answer without a list by its total, and skips refused or failed counts', async () => {
		answer({ '/t?': { ok: true, body: { total: 3, results: 5 } }, '/l?': { ok: false, body: {} } })
		expect(await useAgentProfilesStore().getWorkload('u')).toBe(3)
		answer({ '/t?': new Error('down'), '/l?': { ok: true, body: {} } })
		expect(await useAgentProfilesStore().getWorkload('u')).toBe(0)
		answer({ '/t?ticketType=request&assignee=u&_limit=1': { ok: true, body: [] }, '/t?ticketType=request&assignee=u&_limit=200': { ok: false }, '/l?': new Error('x') })
		expect(await useAgentProfilesStore().getWorkload('u')).toBe(0)
	})

	it('counts nothing for types that are not registered', async () => {
		objects.registry = {}
		vi.stubGlobal('fetch', vi.fn())
		expect(await useAgentProfilesStore().getWorkload('u')).toBe(0)
		expect(fetch).not.toHaveBeenCalled()
	})
})
