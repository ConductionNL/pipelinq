import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

const API_BASE = generateUrl('/apps/pipelinq/api/settings/request-channels')

/**
 *
 */
function headers() {
	return {
		'Content-Type': 'application/json',
		requesttoken: OC.requestToken,
		'OCS-APIREQUEST': 'true',
	}
}

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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-40
		 */
		async fetchChannels() {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(API_BASE, { headers: headers() })
				if (!response.ok) {
					throw new Error(
						`Failed to fetch request channels (${response.status})`,
					)
				}
				const data = await response.json()
				this.tags = data.tags || []
			} catch (error) {
				this.error = error.message
				console.error('Error fetching request channels:', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Create a request channel.
		 *
		 * @param {string} name The channel name to add.
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-39
		 */
		async addChannel(name) {
			try {
				const response = await fetch(API_BASE, {
					method: 'POST',
					headers: headers(),
					body: JSON.stringify({ name }),
				})
				if (!response.ok) {
					throw new Error(`Failed to add channel (${response.status})`)
				}
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

		/**
		 * @param {string} id Identifier of the request channel.
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-41
		 */
		async removeChannel(id) {
			try {
				const response = await fetch(`${API_BASE}/${id}`, {
					method: 'DELETE',
					headers: headers(),
				})
				if (!response.ok) {
					throw new Error(`Failed to remove channel (${response.status})`)
				}
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

		/**
		 * @param {string} id Identifier of the request channel.
		 * @param {string} name The new name.
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-42
		 */
		async renameChannel(id, name) {
			try {
				const response = await fetch(`${API_BASE}/${id}`, {
					method: 'PUT',
					headers: headers(),
					body: JSON.stringify({ name }),
				})
				if (!response.ok) {
					throw new Error(`Failed to rename channel (${response.status})`)
				}
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
