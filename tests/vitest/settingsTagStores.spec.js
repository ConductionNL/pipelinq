// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead source and request channel stores: the settings list each keeps,
 * and the add, remove and rename requests with their failures.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-28
 */
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const { useLeadSourcesStore } = await import('../../src/store/modules/leadSources.js')
const { useRequestChannelsStore } = await import('../../src/store/modules/requestChannels.js')

const kinds = [
	{ use: useLeadSourcesStore, path: 'lead-sources', fetch: 'fetchSources', add: 'addSource', remove: 'removeSource', rename: 'renameSource', names: 'sourceNames', what: 'lead sources', one: 'source' },
	{ use: useRequestChannelsStore, path: 'request-channels', fetch: 'fetchChannels', add: 'addChannel', remove: 'removeChannel', rename: 'renameChannel', names: 'channelNames', what: 'request channels', one: 'channel' },
]

const headers = { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' }

beforeEach(() => {
	setActivePinia(createPinia())
	globalThis.OC = { requestToken: 'tok' }
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

function answer(...answers) {
	const queue = [...answers]
	vi.stubGlobal('fetch', vi.fn(async () => {
		const next = queue.shift()
		return { ok: next.ok, status: next.status ?? 200, json: async () => next.body }
	}))
}

describe.each(kinds)('the $what store', (kind) => {
	it('loads the list and names it', async () => {
		answer({ ok: true, body: { tags: [{ id: 1, name: 'Web' }] } }, { ok: true, body: {} })
		const store = kind.use()
		await store[kind.fetch]()
		expect(fetch).toHaveBeenCalledWith(`/index.php/apps/pipelinq/api/settings/${kind.path}`, { headers })
		expect(store[kind.names]).toEqual(['Web'])
		expect([store.loading, store.error]).toEqual([false, null])
		await store[kind.fetch]()
		expect(store.tags).toEqual([])
	})

	it('logs and keeps a failed load', async () => {
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		answer({ ok: false, status: 503 })
		const store = kind.use()
		await store[kind.fetch]()
		expect(store.error).toBe(`Failed to fetch ${kind.what} (503)`)
		expect(error).toHaveBeenCalledWith(`Error fetching ${kind.what}:`, new Error(`Failed to fetch ${kind.what} (503)`))
		expect(store.loading).toBe(false)
	})

	it('adds, renames and removes, in the list too', async () => {
		answer(
			{ ok: true, body: { success: true, tag: { id: 2, name: 'Mail' } } },
			{ ok: true, body: { success: true, tag: { id: 2, name: 'E-mail' } } },
			{ ok: true, body: { success: true } },
			{ ok: true, body: { success: true, tag: { id: 9, name: 'Ghost' } } },
		)
		const store = kind.use()
		expect(await store[kind.add]('Mail')).toEqual({ id: 2, name: 'Mail' })
		expect(await store[kind.rename](2, 'E-mail')).toEqual({ id: 2, name: 'E-mail' })
		expect(store.tags).toEqual([{ id: 2, name: 'E-mail' }])
		await store[kind.remove](2)
		expect(store.tags).toEqual([])
		expect(await store[kind.rename](9, 'Ghost')).toEqual({ id: 9, name: 'Ghost' })
		expect(store.tags).toEqual([])
		expect(fetch.mock.calls).toEqual([
			[`/index.php/apps/pipelinq/api/settings/${kind.path}`, { method: 'POST', headers, body: '{"name":"Mail"}' }],
			[`/index.php/apps/pipelinq/api/settings/${kind.path}/2`, { method: 'PUT', headers, body: '{"name":"E-mail"}' }],
			[`/index.php/apps/pipelinq/api/settings/${kind.path}/2`, { method: 'DELETE', headers }],
			[`/index.php/apps/pipelinq/api/settings/${kind.path}/9`, { method: 'PUT', headers, body: '{"name":"Ghost"}' }],
		])
	})

	it('throws and keeps the reason of a refused or unsuccessful change', async () => {
		const store = kind.use()
		const cases = [
			[kind.add, ['x'], 'add'],
			[kind.remove, [1], 'remove'],
			[kind.rename, [1, 'y'], 'rename'],
		]
		for (const [action, args, verb] of cases) {
			answer({ ok: false, status: 400 })
			await expect(store[action](...args)).rejects.toThrow(`Failed to ${verb} ${kind.one} (400)`)
			expect(store.error).toBe(`Failed to ${verb} ${kind.one} (400)`)
			answer({ ok: true, body: { success: false, message: 'Taken' } })
			await expect(store[action](...args)).rejects.toThrow('Taken')
			answer({ ok: true, body: { success: false } })
			await expect(store[action](...args)).rejects.toThrow(`Failed to ${verb} ${kind.one}`)
			expect(store.error).toBe(`Failed to ${verb} ${kind.one}`)
		}
	})
})
