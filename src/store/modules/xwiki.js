// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// xWiki Pinia store — wraps the Pipelinq xWiki proxy endpoints
// (xwiki-integration). Calls /api/xwiki/{search,pages,page,status} via fetch
// with the Nextcloud request token. State is consumed by XWikiWidget,
// XWikiArticleList, XWikiArticleViewer and XWikiSidebarTab.

import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

const BASE = generateUrl('/apps/pipelinq/api/xwiki')

/**
 *
 */
function headers() {
	return {
		'Content-Type': 'application/json',
		requesttoken:
			typeof OC !== 'undefined' && OC.requestToken ? OC.requestToken : '',
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * Build a query string, dropping empty values.
 *
 * @param {object} params The query parameters.
 * @return {string} The encoded query string.
 */
function qs(params) {
	const usp = new URLSearchParams()
	Object.entries(params).forEach(([key, value]) => {
		if (value === undefined || value === null || value === '') return
		if (Array.isArray(value)) {
			if (value.length === 0) return
			usp.append(key, value.join(','))
			return
		}
		usp.append(key, String(value))
	})
	const s = usp.toString()
	return s === '' ? '' : `?${s}`
}

export const useXwikiStore = defineStore('xwiki', {
	state: () => ({
		// Last-fetched search/list results keyed by query intent.
		articles: [],
		// Last-fetched single page content.
		currentArticle: null,
		// Distinct spaces seen so far in the result stream.
		spaces: [],
		// Last search query the user entered.
		searchQuery: '',
		// True while any fetch is in flight.
		loading: false,
		// Last error message, if any.
		error: null,
		// Result of the most recent status check.
		available: false,
		// Status payload last returned by /api/xwiki/status.
		status: null,
	}),
	getters: {
		hasArticles: (state) =>
			Array.isArray(state.articles) && state.articles.length > 0,
	},
	actions: {
		/**
		 * Search xWiki pages.
		 *
		 * @param {object} params Search params: { q, space, tags, limit, offset }.
		 * @return {Promise<object>} Raw response payload.
		 */
		async search(params = {}) {
			this.loading = true
			this.error = null
			this.searchQuery = params.q || ''
			try {
				const response = await fetch(BASE + '/search' + qs(params), {
					headers: headers(),
				})
				if (!response.ok) {
					throw new Error(`xWiki search failed (${response.status})`)
				}
				const data = await response.json()
				this.articles = Array.isArray(data.results) ? data.results : []
				this.collectSpaces()
				return data
			} catch (e) {
				this.error = e.message
				this.articles = []
				return { results: [], total: 0, error: e.message }
			} finally {
				this.loading = false
			}
		},
		/**
		 * List pages in a space.
		 *
		 * @param {string} space Space name.
		 * @param {object} extra Optional { limit, offset } overrides.
		 * @return {Promise<object>} Raw response payload.
		 */
		async getPages(space, extra = {}) {
			this.loading = true
			this.error = null
			try {
				const response = await fetch(
					BASE + '/pages' + qs({ space, ...extra }),
					{ headers: headers() },
				)
				if (!response.ok) {
					throw new Error(`xWiki pages failed (${response.status})`)
				}
				const data = await response.json()
				this.articles = Array.isArray(data.results) ? data.results : []
				this.collectSpaces()
				return data
			} catch (e) {
				this.error = e.message
				this.articles = []
				return { results: [], total: 0, error: e.message }
			} finally {
				this.loading = false
			}
		},
		/**
		 * Fetch rendered page content.
		 *
		 * @param {string} wiki Wiki id (default 'xwiki').
		 * @param {string} page Dotted page reference.
		 * @return {Promise<object>} Raw page payload.
		 */
		async getPageContent(wiki, page) {
			this.loading = true
			this.error = null
			try {
				const w = encodeURIComponent(wiki || 'xwiki')
				const p = encodeURIComponent(page)
				const response = await fetch(`${BASE}/page/${w}/${p}`, {
					headers: headers(),
				})
				if (!response.ok) {
					throw new Error(`xWiki page failed (${response.status})`)
				}
				const data = await response.json()
				this.currentArticle = data
				return data
			} catch (e) {
				this.error = e.message
				this.currentArticle = null
				return { title: '', content: '', error: e.message }
			} finally {
				this.loading = false
			}
		},
		/**
		 * Check xWiki status. Updates `available` and `status`.
		 *
		 * @return {Promise<object>} Status payload.
		 */
		async checkStatus() {
			try {
				const response = await fetch(BASE + '/status', {
					headers: headers(),
				})
				if (!response.ok) {
					throw new Error(`xWiki status failed (${response.status})`)
				}
				const data = await response.json()
				this.status = data
				this.available = !!data.available
				return data
			} catch (e) {
				this.error = e.message
				this.available = false
				return { available: false, error: e.message }
			}
		},
		/**
		 * Collect distinct spaces from the current articles array.
		 *
		 * @return {void}
		 */
		collectSpaces() {
			const set = new Set()
			this.articles.forEach((a) => {
				if (a && a.space) set.add(a.space)
			})
			this.spaces = Array.from(set).sort()
		},
	},
})
