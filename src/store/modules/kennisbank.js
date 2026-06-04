/**
 * Kennisbank (knowledge base) store for Pipelinq.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'

const SCHEMA_ARTICLE = 'kennisartikel'
const SCHEMA_CATEGORY = 'kenniscategorie'
const SCHEMA_FEEDBACK = 'kennisfeedback'

export const useKennisbankStore = defineStore('kennisbank', {
	state: () => ({
		articles: [],
		categories: [],
		currentArticle: null,
		loading: false,
		searchLoading: false,
		searchResults: [],
		autocompleteResults: [],
		recentlyViewed: [],
		selectedCategory: null,
		categoryTree: [],
		articleCountsByCategory: {},
	}),
	getters: {
		visibleArticles: (state) => {
			if (!state.selectedCategory) {
				return state.articles
			}
			return state.articles.filter(a => a.category === state.selectedCategory)
		},
		getCategoryName: (state) => (id) => {
			const cat = state.categories.find(c => c.id === id)
			return cat ? cat.name : id
		},
	},
	actions: {
		async fetchArticles() {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				const params = { _limit: 200 }
				if (this.selectedCategory) {
					params.category = this.selectedCategory
				}
				const result = await objectStore.fetchCollection(SCHEMA_ARTICLE, params)
				this.articles = result || []
				this._buildCategoryTree()
			} catch (error) {
				console.error('Error fetching articles:', error)
			} finally {
				this.loading = false
			}
		},

		async fetchArticle(id) {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				const article = await objectStore.fetchObject(SCHEMA_ARTICLE, id)
				this.currentArticle = article || null
				if (article) {
					this._addToRecentlyViewed(article)
				}
				return article
			} catch (error) {
				console.error('Error fetching article:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		async fetchArticleFeedback(articleId) {
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchCollection(SCHEMA_FEEDBACK, { article: articleId, _limit: 100 })
				return result || []
			} catch (error) {
				console.error('Error fetching article feedback:', error)
				return []
			}
		},

		async fetchCategories() {
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchCollection(SCHEMA_CATEGORY, { _limit: 200 })
				this.categories = result || []
				this._buildCategoryTree()
			} catch (error) {
				console.error('Error fetching categories:', error)
			}
		},

		async searchArticles(query) {
			this.searchLoading = true
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchCollection(SCHEMA_ARTICLE, { _search: query, _limit: 50 })
				this.searchResults = result || []
			} catch (error) {
				console.error('Error searching articles:', error)
				this.searchResults = []
			} finally {
				this.searchLoading = false
			}
		},

		async autocompleteArticles(query) {
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchCollection(SCHEMA_ARTICLE, { _search: query, _limit: 10, _fields: 'id,title' })
				this.autocompleteResults = result || []
			} catch (error) {
				console.error('Error autocompleting articles:', error)
				this.autocompleteResults = []
			}
		},

		async createArticle(data) {
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject(SCHEMA_ARTICLE, data)
				if (result) {
					await this.fetchArticles()
				}
				return result
			} catch (error) {
				console.error('Error creating article:', error)
				throw error
			}
		},

		async updateArticle(id, data) {
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject(SCHEMA_ARTICLE, { ...data, id })
				if (result) {
					this.currentArticle = result
				}
				return result
			} catch (error) {
				console.error('Error updating article:', error)
				throw error
			}
		},

		async createCategory(data) {
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject(SCHEMA_CATEGORY, data)
				if (result) {
					await this.fetchCategories()
				}
				return result
			} catch (error) {
				console.error('Error creating category:', error)
				throw error
			}
		},

		async updateCategory(id, data) {
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject(SCHEMA_CATEGORY, { ...data, id })
				if (result) {
					await this.fetchCategories()
				}
				return result
			} catch (error) {
				console.error('Error updating category:', error)
				throw error
			}
		},

		async deleteCategory(id) {
			try {
				const objectStore = useObjectStore()
				const success = await objectStore.deleteObject(SCHEMA_CATEGORY, id)
				if (success) {
					this.categories = this.categories.filter(c => c.id !== id)
					this._buildCategoryTree()
				}
				return success
			} catch (error) {
				console.error('Error deleting category:', error)
				throw error
			}
		},

		async submitFeedback(articleId, rating, suggestionText = null) {
			try {
				const objectStore = useObjectStore()
				const data = { article: articleId, rating }
				if (suggestionText) {
					data.suggestionText = suggestionText
				}
				return await objectStore.saveObject(SCHEMA_FEEDBACK, data)
			} catch (error) {
				console.error('Error submitting feedback:', error)
				throw error
			}
		},

		async checkDuplicateTitle(title, currentId = null) {
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchCollection(SCHEMA_ARTICLE, { title, _limit: 1 })
				const articles = result || []
				const match = articles.find(a => a.title === title && a.id !== currentId)
				return match || null
			} catch (error) {
				console.error('Error checking duplicate title:', error)
				return null
			}
		},

		_addToRecentlyViewed(article) {
			this.recentlyViewed = [
				article,
				...this.recentlyViewed.filter(a => a.id !== article.id),
			].slice(0, 10)
		},

		_buildCategoryTree() {
			const counts = {}
			this.articles.forEach(a => {
				if (a.category) {
					counts[a.category] = (counts[a.category] || 0) + 1
				}
			})
			this.articleCountsByCategory = counts

			const roots = this.categories.filter(c => !c.parent)
			const buildTree = (cats) => cats.map(c => ({
				...c,
				children: buildTree(this.categories.filter(child => child.parent === c.id)),
			}))
			this.categoryTree = buildTree(roots)
		},
	},
})
