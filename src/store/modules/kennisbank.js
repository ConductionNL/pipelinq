import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'

const RECENT_ARTICLES_KEY = 'pipelinq_kennisbank_recent'
const MAX_RECENT_ARTICLES = 5

export const useKennisbankStore = defineStore('kennisbank', {
	state: () => ({
		articles: [],
		categories: [],
		feedback: [],
		currentArticle: null,
		searchQuery: '',
		searchResults: [],
		autocompleteResults: [],
		selectedCategory: null,
		loading: false,
		searchLoading: false,
		error: null,
		pagination: { page: 1, limit: 20, total: 0 },
		showArchived: false,
	}),
	getters: {
		publishedArticles(state) {
			let filtered = state.articles.filter(a => a.status === 'gepubliceerd')
			if (state.selectedCategory) filtered = filtered.filter(a => a.category === state.selectedCategory)
			return filtered
		},
		visibleArticles(state) {
			return state.showArchived ? state.articles : state.articles.filter(a => a.status !== 'gearchiveerd')
		},
		recentlyViewed() {
			try { const s = localStorage.getItem(RECENT_ARTICLES_KEY); return s ? JSON.parse(s) : [] } catch { return [] }
		},
		categoryTree(state) {
			const map = {}; const roots = []
			for (const c of state.categories) map[c.id] = { ...c, children: [] }
			for (const c of state.categories) { if (c.parent && map[c.parent]) map[c.parent].children.push(map[c.id]); else roots.push(map[c.id]) }
			const sort = (n) => { n.sort((a, b) => (a.order || 0) - (b.order || 0)); for (const x of n) sort(x.children) }
			sort(roots); return roots
		},
		articleCountsByCategory(state) {
			const c = {}; for (const a of state.articles.filter(a => a.status === 'gepubliceerd')) if (a.category) c[a.category] = (c[a.category] || 0) + 1; return c
		},
		getCategoryName(state) { return (id) => { const c = state.categories.find(c => c.id === id); return c ? c.name : '' } },
		getCategoryBreadcrumb(state) {
			return (id) => { const p = []; let c = state.categories.find(x => x.id === id); while (c) { p.unshift({ id: c.id, name: c.name }); c = c.parent ? state.categories.find(x => x.id === c.parent) : null }; return p }
		},
	},
	actions: {
		_getApiUrl(type, id = null) {
			const os = useObjectStore(); const c = os.objectTypeRegistry[type]; if (!c) return null
			let u = '/apps/openregister/api/objects/' + c.register + '/' + c.schema; if (id) u += '/' + id; return u
		},
		async _fetch(url, opts = {}) {
			const r = await fetch(url, { headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' }, ...opts })
			if (!r.ok) throw new Error('Request failed: ' + r.statusText); return r.json()
		},
		async fetchArticles(params = {}) {
			this.loading = true; this.error = null
			try {
				const u = this._getApiUrl('kennisartikel'); if (!u) return
				const q = new URLSearchParams(); q.set('_limit', String(this.pagination.limit)); q.set('_page', String(this.pagination.page))
				if (!this.showArchived) q.set('status', 'gepubliceerd')
				if (this.selectedCategory) q.set('category', this.selectedCategory)
				for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') q.set(k, String(v))
				const d = await this._fetch(u + '?' + q.toString()); this.articles = d.results || d || []; this.pagination.total = d.total || this.articles.length
			} catch (e) { this.error = e.message } finally { this.loading = false }
		},
		async fetchArticle(id) {
			this.loading = true; this.error = null
			try { const u = this._getApiUrl('kennisartikel', id); if (!u) return null; const d = await this._fetch(u); this.currentArticle = d; this._addToRecentlyViewed(id, d.title); return d }
			catch (e) { this.error = e.message; return null } finally { this.loading = false }
		},
		async createArticle(data) {
			this.loading = true; this.error = null
			try { const u = this._getApiUrl('kennisartikel'); if (!u) return null; return await this._fetch(u, { method: 'POST', body: JSON.stringify({ ...data, status: data.status || 'concept', version: 1, author: OC.currentUser, lastUpdatedBy: OC.currentUser }) }) }
			catch (e) { this.error = e.message; return null } finally { this.loading = false }
		},
		async updateArticle(id, data) {
			this.loading = true; this.error = null
			try {
				const u = this._getApiUrl('kennisartikel', id); if (!u) return null
				const ud = { ...data, lastUpdatedBy: OC.currentUser }
				if (data.status === 'gepubliceerd' && this.currentArticle) ud.version = (this.currentArticle.version || 1) + 1
				if (data.status === 'gepubliceerd' && (!this.currentArticle || this.currentArticle.status !== 'gepubliceerd')) ud.publishedAt = new Date().toISOString()
				if (data.status === 'gearchiveerd') ud.archivedAt = new Date().toISOString()
				const d = await this._fetch(u, { method: 'PUT', body: JSON.stringify(ud) }); this.currentArticle = d; return d
			} catch (e) { this.error = e.message; return null } finally { this.loading = false }
		},
		async deleteArticle(id) {
			this.loading = true; try { const u = this._getApiUrl('kennisartikel', id); if (!u) return false; await this._fetch(u, { method: 'DELETE' }); this.articles = this.articles.filter(a => a.id !== id); return true } catch (e) { this.error = e.message; return false } finally { this.loading = false }
		},
		async fetchCategories() {
			try { const u = this._getApiUrl('kenniscategorie'); if (!u) return; const d = await this._fetch(u + '?_limit=500'); this.categories = d.results || d || [] } catch (e) { console.error('Error fetching categories:', e) }
		},
		async createCategory(data) {
			try { const u = this._getApiUrl('kenniscategorie'); if (!u) return null; const d = await this._fetch(u, { method: 'POST', body: JSON.stringify(data) }); this.categories.push(d); return d } catch (e) { this.error = e.message; return null }
		},
		async updateCategory(id, data) {
			try { const u = this._getApiUrl('kenniscategorie', id); if (!u) return null; const d = await this._fetch(u, { method: 'PUT', body: JSON.stringify(data) }); const i = this.categories.findIndex(c => c.id === id); if (i !== -1) this.categories.splice(i, 1, d); return d } catch (e) { this.error = e.message; return null }
		},
		async deleteCategory(id) {
			try { const u = this._getApiUrl('kenniscategorie', id); if (!u) return false; await this._fetch(u, { method: 'DELETE' }); this.categories = this.categories.filter(c => c.id !== id); return true } catch (e) { this.error = e.message; return false }
		},
		async submitFeedback(articleId, rating, comment = '') {
			try { const u = this._getApiUrl('kennisfeedback'); if (!u) return null; return await this._fetch(u, { method: 'POST', body: JSON.stringify({ article: articleId, rating, comment: comment || undefined, agent: OC.currentUser, status: 'nieuw', createdAt: new Date().toISOString() }) }) } catch (e) { this.error = e.message; return null }
		},
		async fetchArticleFeedback(articleId) {
			try { const u = this._getApiUrl('kennisfeedback'); if (!u) return []; const d = await this._fetch(u + '?article=' + articleId + '&_limit=500'); this.feedback = d.results || d || []; return this.feedback } catch { return [] }
		},
		async searchArticles(query) {
			if (!query || query.length < 2) { this.searchResults = []; return }
			this.searchLoading = true; this.searchQuery = query
			try { const u = this._getApiUrl('kennisartikel'); if (!u) return; const d = await this._fetch(u + '?_search=' + encodeURIComponent(query) + '&status=gepubliceerd&_limit=50'); this.searchResults = d.results || d || [] } catch { this.searchResults = [] } finally { this.searchLoading = false }
		},
		async autocompleteArticles(query) {
			if (!query || query.length < 3) { this.autocompleteResults = []; return }
			try { const u = this._getApiUrl('kennisartikel'); if (!u) return; const d = await this._fetch(u + '?_search=' + encodeURIComponent(query) + '&status=gepubliceerd&_limit=5'); this.autocompleteResults = (d.results || d || []).slice(0, 5) } catch { this.autocompleteResults = [] }
		},
		_addToRecentlyViewed(id, title) {
			try { let r = []; const s = localStorage.getItem(RECENT_ARTICLES_KEY); if (s) r = JSON.parse(s); r = r.filter(x => x.id !== id); r.unshift({ id, title, viewedAt: new Date().toISOString() }); r = r.slice(0, MAX_RECENT_ARTICLES); localStorage.setItem(RECENT_ARTICLES_KEY, JSON.stringify(r)) } catch { /* ignore */ }
		},
		async checkDuplicateTitle(title, excludeId = null) {
			try { const u = this._getApiUrl('kennisartikel'); if (!u) return null; const d = await this._fetch(u + '?title=' + encodeURIComponent(title) + '&_limit=1'); const r = d.results || d || []; return r.find(a => a.id !== excludeId) || null } catch { return null }
		},
	},
})
