import { defineStore } from 'pinia'

const API_BASE = '/apps/pipelinq/api/settings/lead-sources'

const headers = () => ({
	'Content-Type': 'application/json',
	requesttoken: OC.requestToken,
	'OCS-APIREQUEST': 'true',
})

export const useLeadSourcesStore = defineStore('leadSources', {
	state: () => ({
		tags: [],
		loading: false,
		error: null,
	}),
	getters: {
		sourceNames: (state) => state.tags.map((t) => t.name),
	},
	actions: {
		async fetchSources() {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(API_BASE, { headers: headers() })
				const data = await response.json()
				this.tags = data.tags || []
			} catch (error) {
				this.error = error.message
				console.error('Error fetching lead sources:', error)
			} finally {
				this.loading = false
			}
		},

		async addSource(name) {
			try {
				const response = await fetch(API_BASE, {
					method: 'POST',
					headers: headers(),
					body: JSON.stringify({ name }),
				})
				const data = await response.json()

				if (!data.success) {
					throw new Error(data.message || 'Failed to add source')
				}

				this.tags.push(data.tag)
				return data.tag
			} catch (error) {
				this.error = error.message
				throw error
			}
		},

		async removeSource(id) {
			try {
				const response = await fetch(`${API_BASE}/${id}`, {
					method: 'DELETE',
					headers: headers(),
				})
				const data = await response.json()

				if (!data.success) {
					throw new Error(data.message || 'Failed to remove source')
				}

				this.tags = this.tags.filter((t) => t.id !== id)
			} catch (error) {
				this.error = error.message
				throw error
			}
		},

		async renameSource(id, name) {
			try {
				const response = await fetch(`${API_BASE}/${id}`, {
					method: 'PUT',
					headers: headers(),
					body: JSON.stringify({ name }),
				})
				const data = await response.json()

				if (!data.success) {
					throw new Error(data.message || 'Failed to rename source')
				}

				const index = this.tags.findIndex((t) => t.id === id)
				if (index !== -1) {
					this.tags[index] = data.tag
				}
				return data.tag
			} catch (error) {
				this.error = error.message
				throw error
			}
		},
	},
})
