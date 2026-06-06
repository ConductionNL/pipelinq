import { defineStore } from 'pinia'
import { generateUrl } from '@nextcloud/router'

const API_BASE = generateUrl('/apps/pipelinq/api/settings/lead-sources')

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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-29
		 */
		async fetchSources() {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(API_BASE, { headers: headers() })
				if (!response.ok) {
					throw new Error(`Failed to fetch lead sources (${response.status})`)
				}
				const data = await response.json()
				this.tags = data.tags || []
			} catch (error) {
				this.error = error.message
				console.error('Error fetching lead sources:', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param name
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-28
		 */
		async addSource(name) {
			try {
				const response = await fetch(API_BASE, {
					method: 'POST',
					headers: headers(),
					body: JSON.stringify({ name }),
				})
				if (!response.ok) {
					throw new Error(`Failed to add source (${response.status})`)
				}
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

		/**
		 * @param id
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-30
		 */
		async removeSource(id) {
			try {
				const response = await fetch(`${API_BASE}/${id}`, {
					method: 'DELETE',
					headers: headers(),
				})
				if (!response.ok) {
					throw new Error(`Failed to remove source (${response.status})`)
				}
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

		/**
		 * @param id
		 * @param name
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-31
		 */
		async renameSource(id, name) {
			try {
				const response = await fetch(`${API_BASE}/${id}`, {
					method: 'PUT',
					headers: headers(),
					body: JSON.stringify({ name }),
				})
				if (!response.ok) {
					throw new Error(`Failed to rename source (${response.status})`)
				}
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
