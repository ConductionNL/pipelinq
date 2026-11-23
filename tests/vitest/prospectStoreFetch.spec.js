// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The prospect store's fetch: the URL it asks, what it keeps from the
 * answer, and what it says when the answer is refused or fails.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-33
 */
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useProspectStore } from '../../src/store/modules/prospect.js'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

beforeEach(() => {
	setActivePinia(createPinia())
	globalThis.OC = { requestToken: 'tok' }
})

afterEach(() => {
	vi.unstubAllGlobals()
})

function answer(ok, body) {
	vi.stubGlobal('fetch', vi.fn(async () => ({ ok, json: async () => body })))
}

const headers = { headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' } }

describe('useProspectStore.fetchProspects', () => {
	it('asks for every prospect, refreshed when asked, and keeps the answer', async () => {
		const data = { icp: { a: 1 }, prospects: [{ id: 'p' }], total: 3, displayed: 1, cachedAt: 'c', icpHash: 'h' }
		answer(true, data)
		const store = useProspectStore()
		expect(await store.fetchProspects(true)).toBe(data)
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/prospects?limit=0&refresh=true', headers)
		expect([store.icp, store.prospects, store.total, store.displayed, store.cachedAt, store.icpHash, store.loading, store.error])
			.toEqual([{ a: 1 }, [{ id: 'p' }], 3, 1, 'c', 'h', false, null])
		answer(true, {})
		await store.fetchProspects()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/prospects?limit=0', headers)
		expect([store.icp, store.prospects, store.total, store.displayed, store.cachedAt, store.icpHash]).toEqual([null, [], 0, 0, null, null])
	})

	it('keeps the profile of a refusal and says why, leaving the rest', async () => {
		const store = useProspectStore()
		store.displayed = 4
		for (const [body, message] of [[{ icp: 'x', message: 'M', error: 'E' }, 'M'], [{ error: 'E' }, 'E'], [{}, 'Failed to fetch prospects']]) {
			answer(false, body)
			store.prospects = [1]
			store.total = 9
			expect(await store.fetchProspects()).toBe(null)
			expect([store.error, store.prospects, store.total, store.displayed]).toEqual([message, [], 0, 4])
		}
		expect(store.icp).toBe(null)
	})

	it('says why a request failed', async () => {
		const store = useProspectStore()
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('down') }))
		expect(await store.fetchProspects()).toBe(null)
		expect(store.error).toBe('down')
		vi.stubGlobal('fetch', vi.fn(async () => { throw {} }))
		await store.fetchProspects()
		expect(store.error).toBe('Failed to fetch prospects')
		expect(store.loading).toBe(false)
	})
})
