/**
 * Pipelinq xWiki Pinia store.
 *
 * Manages state for xWiki integration: article lists, current article content,
 * availability status, search queries, and loading/error state.
 * Calls the Pipelinq xWiki proxy endpoints which are backed by XWikiService.
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-3.1
 */
import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

/**
 * Build Nextcloud-authenticated fetch headers.
 *
 * @return {object} Headers object.
 */
function buildHeaders() {
	return {
		'Content-Type': 'application/json',
		requesttoken: OC.requestToken,
		'OCS-APIREQUEST': 'true',
	}
}

/**
 * xWiki Pinia store.
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-3.1
 */
export const useXWikiStore = defineStore('xwiki', {
	state: () => ({
		/** @type {Array<object>} Latest article/page list results */
		articles: [],
		/** @type {object|null} Currently viewed article (with content) */
		currentArticle: null,
		/** @type {Array<string>} Available xWiki spaces */
		spaces: [],
		/** @type {string} Active search query */
		searchQuery: '',
		/** @type {boolean} Whether any request is in-flight */
		loading: false,
		/** @type {string|null} Last error message */
		error: null,
		/** @type {boolean} Whether xWiki is reachable */
		available: false,
		/** @type {string} xWiki version string (when available) */
		version: '',
		/** @type {number} Total results for current search */
		total: 0,
	}),

	getters: {
		isLoading: (state) => state.loading,
		isAvailable: (state) => state.available,
		getArticles: (state) => state.articles,
		getCurrentArticle: (state) => state.currentArticle,
		getError: (state) => state.error,
	},

	actions: {
		/**
		 * Search xWiki pages.
		 *
		 * @param {object} params               Search parameters.
		 * @param {string} [params.q='']        Search query.
		 * @param {string} [params.space='']    xWiki space filter.
		 * @param {Array}  [params.tags=[]]     Tag filter.
		 * @param {number} [params.limit=10]    Max results.
		 * @param {number} [params.offset=0]    Pagination offset.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/xwiki-integration/tasks.md#task-3.1
		 */
		async search({ q = '', space = '', tags = [], limit = 10, offset = 0 } = {}) {
			this.loading = true
			this.error = null
			this.searchQuery = q

			const qs = new URLSearchParams({
				q,
				space,
				limit: String(limit),
				offset: String(offset),
			})
			if (tags.length > 0) {
				qs.set('tags', tags.join(','))
			}

			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/xwiki/search') + '?' + qs.toString(),
					{ method: 'GET', headers: buildHeaders() },
				)

				if (!response.ok) {
					throw new Error(t('pipelinq', 'xWiki search request failed ({status})', { status: response.status }))
				}

				const data = await response.json()
				this.articles = data.results ?? []
				this.total = data.total ?? 0
			} catch (err) {
				this.error = err.message ?? t('pipelinq', 'xWiki search failed')
				this.articles = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Get pages in a given xWiki space.
		 *
		 * @param {string} space           The space name.
		 * @param {number} [limit=20]      Max results.
		 * @param {number} [offset=0]      Pagination offset.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/xwiki-integration/tasks.md#task-3.1
		 */
		async getPages(space, limit = 20, offset = 0) {
			this.loading = true
			this.error = null

			const qs = new URLSearchParams({ space, limit: String(limit), offset: String(offset) })

			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/xwiki/pages') + '?' + qs.toString(),
					{ method: 'GET', headers: buildHeaders() },
				)

				if (!response.ok) {
					throw new Error(t('pipelinq', 'xWiki pages request failed ({status})', { status: response.status }))
				}

				const data = await response.json()
				this.articles = data.results ?? []
				this.total = data.total ?? 0
			} catch (err) {
				this.error = err.message ?? t('pipelinq', 'Fetching xWiki pages failed')
				this.articles = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the content of a single xWiki page.
		 *
		 * @param {string} wiki  The wiki name (e.g. "xwiki").
		 * @param {string} page  The page reference.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/xwiki-integration/tasks.md#task-3.1
		 */
		async getPageContent(wiki, page) {
			this.loading = true
			this.error = null
			this.currentArticle = null

			try {
				const encodedPage = encodeURIComponent(page)
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/xwiki/page/${wiki}/${encodedPage}`),
					{ method: 'GET', headers: buildHeaders() },
				)

				if (response.status === 404) {
					throw new Error(t('pipelinq', 'xWiki article not found'))
				}

				if (!response.ok) {
					throw new Error(t('pipelinq', 'xWiki page request failed ({status})', { status: response.status }))
				}

				this.currentArticle = await response.json()
			} catch (err) {
				this.error = err.message ?? t('pipelinq', 'Fetching xWiki page failed')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Check xWiki availability and populate available/version state.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/xwiki-integration/tasks.md#task-3.1
		 */
		async checkStatus() {
			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/xwiki/status'),
					{ method: 'GET', headers: buildHeaders() },
				)

				if (!response.ok) {
					this.available = false
					return
				}

				const data = await response.json()
				this.available = data.available === true
				this.version = data.version ?? ''
			} catch {
				this.available = false
			}
		},

		/**
		 * Clear the current article.
		 *
		 * @return {void}
		 */
		clearCurrentArticle() {
			this.currentArticle = null
		},
	},
})
