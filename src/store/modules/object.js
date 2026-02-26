import { defineStore } from 'pinia'

export const useObjectStore = defineStore('object', {
	state: () => ({
		objectTypeRegistry: {},
		collections: {},
		objects: {},
		loading: {},
		errors: {},
		pagination: {},
		searchTerms: {},
	}),
	getters: {
		objectTypes: (state) => Object.keys(state.objectTypeRegistry),
		getCollection: (state) => (type) => state.collections[type] || [],
		getObject: (state) => (type, id) => state.objects[type]?.[id] || null,
		getCachedObject: (state) => (type, id) => state.objects[type]?.[id] || null,
		isLoading: (state) => (type) => state.loading[type] || false,
		getError: (state) => (type) => state.errors[type] || null,
		getPagination: (state) => (type) => state.pagination[type] || { total: 0, page: 1, pages: 1, limit: 20 },
		getSearchTerm: (state) => (type) => state.searchTerms[type] || '',
	},
	actions: {
		registerObjectType(slug, schemaId, registerId) {
			this.objectTypeRegistry[slug] = { schema: schemaId, register: registerId }
			this.collections[slug] = []
			this.objects[slug] = {}
			this.loading[slug] = false
			this.errors[slug] = null
			this.pagination[slug] = { total: 0, page: 1, pages: 1, limit: 20 }
			this.searchTerms[slug] = ''
		},

		unregisterObjectType(slug) {
			delete this.objectTypeRegistry[slug]
			delete this.collections[slug]
			delete this.objects[slug]
			delete this.loading[slug]
			delete this.errors[slug]
			delete this.pagination[slug]
			delete this.searchTerms[slug]
		},

		_getTypeConfig(type) {
			const config = this.objectTypeRegistry[type]
			if (!config) {
				throw new Error(`Object type "${type}" is not registered`)
			}
			return config
		},

		_getHeaders() {
			return {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
				'OCS-APIREQUEST': 'true',
			}
		},

		_buildUrl(type, id = null) {
			const config = this._getTypeConfig(type)
			let url = `/apps/openregister/api/objects/${config.register}/${config.schema}`
			if (id) {
				url += `/${id}`
			}
			return url
		},

		async _parseResponseError(response, fallbackMessage) {
			const status = response.status
			let message = ''
			let fields = null

			if (status === 404) {
				message = t('pipelinq', 'The requested item was not found. It may have been deleted.')
			} else if (status === 403) {
				message = t('pipelinq', 'You do not have permission to perform this action.')
			} else if (status === 422) {
				try {
					const data = await response.json()
					message = data.message || t('pipelinq', 'Validation failed. Please check your input.')
					fields = data.errors || data.validationErrors || null
				} catch {
					message = t('pipelinq', 'Validation failed. Please check your input.')
				}
			} else if (status >= 500) {
				message = t('pipelinq', 'An unexpected server error occurred. Please try again.')
			} else {
				message = fallbackMessage || response.statusText || t('pipelinq', 'An unexpected error occurred.')
			}

			return { message, status, fields }
		},

		_networkError() {
			return {
				message: t('pipelinq', 'A network error occurred. Check your connection and try again.'),
				status: 0,
				fields: null,
			}
		},

		clearError(type) {
			this.errors[type] = null
		},

		async fetchCollection(type, params = {}) {
			this.loading[type] = true
			this.errors[type] = null

			try {
				const queryParams = new URLSearchParams()

				for (const [key, value] of Object.entries(params)) {
					if (value === undefined || value === null || value === '') continue
					if (key === '_order') {
						queryParams.set(key, JSON.stringify(value))
					} else {
						queryParams.set(key, value)
					}
				}

				const url = this._buildUrl(type) + (queryParams.toString() ? '?' + queryParams.toString() : '')

				const response = await fetch(url, {
					method: 'GET',
					headers: this._getHeaders(),
				})

				if (!response.ok) {
					this.errors[type] = await this._parseResponseError(response, `Failed to fetch ${type}`)
					console.error(`Error fetching ${type} collection:`, this.errors[type])
					return []
				}

				const data = await response.json()

				this.collections[type] = data.results || data
				this.pagination[type] = {
					total: data.total || (data.results || data).length,
					page: data.page || 1,
					pages: data.pages || 1,
					limit: params._limit || 20,
				}

				return this.collections[type]
			} catch (error) {
				this.errors[type] = error.name === 'TypeError'
					? this._networkError()
					: { message: error.message, status: null, fields: null }
				console.error(`Error fetching ${type} collection:`, error)
				return []
			} finally {
				this.loading[type] = false
			}
		},

		async fetchObject(type, id) {
			this.loading[type] = true
			this.errors[type] = null

			try {
				const url = this._buildUrl(type, id)

				const response = await fetch(url, {
					method: 'GET',
					headers: this._getHeaders(),
				})

				if (!response.ok) {
					this.errors[type] = await this._parseResponseError(response, `Failed to fetch ${type}/${id}`)
					console.error(`Error fetching ${type}/${id}:`, this.errors[type])
					return null
				}

				const data = await response.json()

				if (!this.objects[type]) {
					this.objects[type] = {}
				}
				this.objects[type][id] = data

				return data
			} catch (error) {
				this.errors[type] = error.name === 'TypeError'
					? this._networkError()
					: { message: error.message, status: null, fields: null }
				console.error(`Error fetching ${type}/${id}:`, error)
				return null
			} finally {
				this.loading[type] = false
			}
		},

		async saveObject(type, objectData) {
			this.loading[type] = true
			this.errors[type] = null

			try {
				const isUpdate = !!objectData.id
				const url = isUpdate ? this._buildUrl(type, objectData.id) : this._buildUrl(type)
				const method = isUpdate ? 'PUT' : 'POST'

				const response = await fetch(url, {
					method,
					headers: this._getHeaders(),
					body: JSON.stringify(objectData),
				})

				if (!response.ok) {
					this.errors[type] = await this._parseResponseError(
						response,
						`Failed to ${isUpdate ? 'update' : 'create'} ${type}`,
					)
					console.error(`Error saving ${type}:`, this.errors[type])
					return null
				}

				const data = await response.json()

				if (!this.objects[type]) {
					this.objects[type] = {}
				}
				const savedId = data.id || objectData.id
				this.objects[type][savedId] = data

				return data
			} catch (error) {
				this.errors[type] = error.name === 'TypeError'
					? this._networkError()
					: { message: error.message, status: null, fields: null }
				console.error(`Error saving ${type}:`, error)
				return null
			} finally {
				this.loading[type] = false
			}
		},

		async deleteObject(type, id) {
			this.loading[type] = true
			this.errors[type] = null

			try {
				const url = this._buildUrl(type, id)

				const response = await fetch(url, {
					method: 'DELETE',
					headers: this._getHeaders(),
				})

				if (!response.ok) {
					this.errors[type] = await this._parseResponseError(response, `Failed to delete ${type}/${id}`)
					console.error(`Error deleting ${type}/${id}:`, this.errors[type])
					return false
				}

				if (this.objects[type]) {
					delete this.objects[type][id]
				}
				if (this.collections[type]) {
					this.collections[type] = this.collections[type].filter(obj => obj.id !== id)
				}

				return true
			} catch (error) {
				this.errors[type] = error.name === 'TypeError'
					? this._networkError()
					: { message: error.message, status: null, fields: null }
				console.error(`Error deleting ${type}/${id}:`, error)
				return false
			} finally {
				this.loading[type] = false
			}
		},

		async resolveReferences(type, ids) {
			if (!ids || ids.length === 0) return {}

			const uniqueIds = [...new Set(ids.filter(Boolean))]
			const result = {}
			const toFetch = []

			for (const id of uniqueIds) {
				const cached = this.objects[type]?.[id]
				if (cached) {
					result[id] = cached
				} else {
					toFetch.push(id)
				}
			}

			if (toFetch.length > 0) {
				const fetches = toFetch.map(async (id) => {
					try {
						const url = this._buildUrl(type, id)
						const response = await fetch(url, {
							method: 'GET',
							headers: this._getHeaders(),
						})
						if (response.ok) {
							const data = await response.json()
							if (!this.objects[type]) this.objects[type] = {}
							this.objects[type][id] = data
							result[id] = data
						}
					} catch {
						// Non-blocking — leave unresolved
					}
				})
				await Promise.all(fetches)
			}

			return result
		},

		setSearchTerm(type, term) {
			this.searchTerms[type] = term
		},

		clearSearchTerm(type) {
			this.searchTerms[type] = ''
		},
	},
})
