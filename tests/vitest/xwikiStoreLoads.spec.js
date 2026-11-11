/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The xWiki store's search and page list: the request each sends, the
 * articles each leaves, and how each fails.
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const { useXwikiStore } = await import('../../src/store/modules/xwiki.js')

let fetchMock

beforeEach(() => {
	setActivePinia(createPinia())
	globalThis.OC = { requestToken: 'tok' }
	fetchMock = vi.fn()
	vi.stubGlobal('fetch', fetchMock)
	return () => vi.unstubAllGlobals()
})

describe('xwiki store search', () => {
	it('searches with the filled-in params and keeps the articles', async () => {
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ results: [{ id: 1, space: 'Help' }], total: 1 }) })
		const store = useXwikiStore()
		const pending = store.search({ q: 'vat', space: '', tags: ['a', 'b'], limit: 5, offset: null })
		expect(store.loading).toBe(true)
		expect(store.searchQuery).toBe('vat')
		expect(await pending).toEqual({ results: [{ id: 1, space: 'Help' }], total: 1 })
		expect(fetchMock.mock.calls[0][0]).toBe('/apps/pipelinq/api/xwiki/search?q=vat&tags=a%2Cb&limit=5')
		expect(fetchMock.mock.calls[0][1].headers).toBeTruthy()
		expect(store.articles).toEqual([{ id: 1, space: 'Help' }])
		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ results: 'x' }) })
		await store.search()
		expect(fetchMock.mock.calls[1][0]).toBe('/apps/pipelinq/api/xwiki/search')
		expect(store.searchQuery).toBe('')
		expect(store.articles).toEqual([])
	})

	it('answers an empty result with the error of a refused or failed search', async () => {
		fetchMock.mockResolvedValue({ ok: false, status: 502 })
		const store = useXwikiStore()
		store.articles = [{ id: 9 }]
		expect(await store.search({ q: 'x' })).toEqual({ results: [], total: 0, error: 'xWiki search failed (502)' })
		expect(store.error).toBe('xWiki search failed (502)')
		expect(store.articles).toEqual([])
		fetchMock.mockRejectedValue(new Error('offline'))
		expect(await store.search({})).toEqual({ results: [], total: 0, error: 'offline' })
		expect(store.loading).toBe(false)
	})
})

describe('xwiki store getPages', () => {
	it('lists a space\'s pages', async () => {
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ results: [{ id: 2, space: 'Help' }] }) })
		const store = useXwikiStore()
		store.searchQuery = 'kept'
		expect(await store.getPages('Help', { limit: 10 })).toEqual({ results: [{ id: 2, space: 'Help' }] })
		expect(fetchMock.mock.calls[0][0]).toBe('/apps/pipelinq/api/xwiki/pages?space=Help&limit=10')
		expect(store.articles).toEqual([{ id: 2, space: 'Help' }])
		expect(store.searchQuery).toBe('kept')
		await store.getPages('Docs')
		expect(fetchMock.mock.calls[1][0]).toBe('/apps/pipelinq/api/xwiki/pages?space=Docs')
	})

	it('answers an empty result with the error of a refused or failed list', async () => {
		fetchMock.mockResolvedValue({ ok: false, status: 404 })
		const store = useXwikiStore()
		expect(await store.getPages('Help')).toEqual({ results: [], total: 0, error: 'xWiki pages failed (404)' })
		fetchMock.mockRejectedValue(new Error('offline'))
		expect(await store.getPages('Help')).toEqual({ results: [], total: 0, error: 'offline' })
		expect(store.error).toBe('offline')
		expect(store.loading).toBe(false)
	})
})
