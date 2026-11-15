/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The xWiki store's single page, status check, space collection and request
 * headers: the request each sends, the state each leaves, and how each fails.
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
	return () => {
		vi.unstubAllGlobals()
		delete globalThis.OC
	}
})

const ok = (body) => ({ ok: true, status: 200, json: async () => body })

describe('xwiki store getPageContent', () => {
	it('fetches the encoded page with the request token and keeps it', async () => {
		fetchMock.mockResolvedValue(ok({ title: 'VAT', content: '<p>x</p>' }))
		const store = useXwikiStore()
		const pending = store.getPageContent('main wiki', 'Help/VAT rates')
		expect(store.loading).toBe(true)
		expect(await pending).toEqual({ title: 'VAT', content: '<p>x</p>' })
		expect(fetchMock).toHaveBeenCalledWith('/apps/pipelinq/api/xwiki/page/main%20wiki/Help%2FVAT%20rates', {
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' },
		})
		expect(store.currentArticle).toEqual({ title: 'VAT', content: '<p>x</p>' })
		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
	})

	it('uses the xwiki wiki when none is given', async () => {
		fetchMock.mockResolvedValue(ok({}))
		await useXwikiStore().getPageContent('', 'Start')
		expect(fetchMock.mock.calls[0][0]).toBe('/apps/pipelinq/api/xwiki/page/xwiki/Start')
	})

	it('answers an empty page with the error of a refused or failed read', async () => {
		const store = useXwikiStore()
		store.currentArticle = { title: 'old' }
		fetchMock.mockResolvedValue({ ok: false, status: 404 })
		expect(await store.getPageContent('xwiki', 'Gone')).toEqual({ title: '', content: '', error: 'xWiki page failed (404)' })
		expect(store.currentArticle).toBeNull()
		expect(store.error).toBe('xWiki page failed (404)')
		expect(store.loading).toBe(false)

		fetchMock.mockRejectedValue(new Error('offline'))
		expect(await store.getPageContent('xwiki', 'Any')).toEqual({ title: '', content: '', error: 'offline' })
	})

	it('sends an empty request token when OC is absent', async () => {
		delete globalThis.OC
		fetchMock.mockResolvedValue(ok({}))
		await useXwikiStore().getPageContent('xwiki', 'Start')
		expect(fetchMock.mock.calls[0][1].headers.requesttoken).toBe('')
	})
})

describe('xwiki store checkStatus', () => {
	it('keeps the status and whether xWiki is available', async () => {
		fetchMock.mockResolvedValue(ok({ available: 1, version: '16' }))
		const store = useXwikiStore()
		expect(await store.checkStatus()).toEqual({ available: 1, version: '16' })
		expect(fetchMock.mock.calls[0][0]).toBe('/apps/pipelinq/api/xwiki/status')
		expect(store.status).toEqual({ available: 1, version: '16' })
		expect(store.available).toBe(true)

		fetchMock.mockResolvedValue(ok({}))
		await store.checkStatus()
		expect(store.available).toBe(false)
	})

	it('marks xWiki unavailable on a refused or failed check', async () => {
		const store = useXwikiStore()
		store.available = true
		fetchMock.mockResolvedValue({ ok: false, status: 503 })
		expect(await store.checkStatus()).toEqual({ available: false, error: 'xWiki status failed (503)' })
		expect(store.available).toBe(false)
		expect(store.error).toBe('xWiki status failed (503)')
		expect(store.loading).toBe(false)
	})
})

describe('xwiki store spaces and hasArticles', () => {
	it('collects the distinct spaces, sorted, skipping empty entries', () => {
		const store = useXwikiStore()
		expect(store.hasArticles).toBe(false)
		store.articles = [{ space: 'Sales' }, null, { space: 'Help' }, { title: 'no space' }, { space: 'Sales' }]
		store.collectSpaces()
		expect(store.spaces).toEqual(['Help', 'Sales'])
		expect(store.hasArticles).toBe(true)
	})

	it('has no articles when the list is not an array', () => {
		const store = useXwikiStore()
		store.articles = null
		expect(store.hasArticles).toBe(false)
	})

	it('drops empty arrays from the query string', async () => {
		fetchMock.mockResolvedValue(ok({ results: [] }))
		await useXwikiStore().getPages('Help', { tags: [], limit: 0 })
		expect(fetchMock.mock.calls[0][0]).toBe('/apps/pipelinq/api/xwiki/pages?space=Help&limit=0')
	})
})
