import { defineStore } from 'pinia'

const API_BASE = '/apps/pipelinq/api/settings/request-channels'

const headers = () => ({
	'Content-Type': 'application/json',
	requesttoken: OC.requestToken,
	'OCS-APIREQUEST': 'true',
})

export const useRequestChannelsStore = defineStore('requestChannels', {
	state: () => ({
		tags: [],
		loading: false,
		error: null,
	}),
	getters: {
		channelNames: (state) => state.tags.map((t) => t.name),
	},
	actions: {
		async fetchChannels() {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(API_BASE, { headers: headers() })
				const data = await response.json()
				this.tags = data.tags || []
			} catch (error) {
				this.error = error.message
				console.error('Error fetching request channels:', error)
			} finally {
				this.loading = false
			}
		},

		async addChannel(name) {
			try {
				const response = await fetch(API_BASE, {
					method: 'POST',
					headers: headers(),
					body: JSON.stringify({ name }),
				})
				const data = await response.json()

				if (!data.success) {
					throw new Error(data.message || 'Failed to add channel')
				}

				this.tags.push(data.tag)
				return data.tag
			} catch (error) {
				this.error = error.message
				throw error
			}
		},

		async removeChannel(id) {
			try {
				const response = await fetch(`${API_BASE}/${id}`, {
					method: 'DELETE',
					headers: headers(),
				})
				const data = await response.json()

				if (!data.success) {
					throw new Error(data.message || 'Failed to remove channel')
				}

				this.tags = this.tags.filter((t) => t.id !== id)
			} catch (error) {
				this.error = error.message
				throw error
			}
		},

		async renameChannel(id, name) {
			try {
				const response = await fetch(`${API_BASE}/${id}`, {
					method: 'PUT',
					headers: headers(),
					body: JSON.stringify({ name }),
				})
				const data = await response.json()

				if (!data.success) {
					throw new Error(data.message || 'Failed to rename channel')
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
