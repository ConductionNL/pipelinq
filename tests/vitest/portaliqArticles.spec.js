/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Marketing articles live in portaliq: the picker list comes through
 * pipelinq's own endpoint, each article links into portaliq, and an old
 * pipelinq article address goes to portaliq only when portaliq is enabled.
 *
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-ui/spec.md#requirement-the-marketing-menu-no-longer-carries-articles
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const state = vi.hoisted(() => ({ value: {}, throws: false }))
const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => {
		if (state.throws) {
			throw new Error('no state')
		}
		return app === 'pipelinq' && key === 'dependency_statuses' ? state.value : fallback
	},
}))

const { fetchPortaliqArticles, oldArticleTarget, portaliqArticleUrl, portaliqEnabled } = await import('../../src/services/portaliqArticles.js')

beforeEach(() => {
	state.value = {}
	state.throws = false
	http.get.mockReset()
})

describe('portaliq articles', () => {
	it('lists the articles through pipelinq and keeps the shape when portaliq answers oddly', async () => {
		http.get.mockResolvedValueOnce({ data: { available: true, data: [{ id: 'a1' }] } })
		expect(await fetchPortaliqArticles()).toEqual({ available: true, data: [{ id: 'a1' }] })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/templates/articles')
		http.get.mockResolvedValueOnce({ data: { available: 'yes', data: 'x' } })
		expect(await fetchPortaliqArticles()).toEqual({ available: false, data: [] })
	})

	it('links an article, or the Articles page, in portaliq', () => {
		expect(portaliqArticleUrl('a 1')).toBe('/index.php/apps/portaliq/articles/a%201')
		expect(portaliqArticleUrl('')).toBe('/index.php/apps/portaliq/articles')
		expect(portaliqArticleUrl()).toBe('/index.php/apps/portaliq/articles')
		expect(portaliqArticleUrl(null)).toBe('/index.php/apps/portaliq/articles')
	})

	it('knows portaliq is enabled only from its dependency status', () => {
		expect(portaliqEnabled()).toBe(false)
		state.value = { portaliq: { installed: true, enabled: false } }
		expect(portaliqEnabled()).toBe(false)
		state.value = { portaliq: { installed: true, enabled: true } }
		expect(portaliqEnabled()).toBe(true)
		state.throws = true
		expect(portaliqEnabled()).toBe(false)
	})

	it('sends an old article address to the same article in portaliq, and stays put without it', () => {
		expect(oldArticleTarget({ params: { id: 'a1' } }, true)).toBe('/index.php/apps/portaliq/articles/a1')
		expect(oldArticleTarget({ params: {} }, true)).toBe('/index.php/apps/portaliq/articles')
		expect(oldArticleTarget({ params: { id: 'new' } }, true)).toBe('/index.php/apps/portaliq/articles')
		expect(oldArticleTarget({ params: { id: 'a1' } }, false)).toBeNull()
	})
})
