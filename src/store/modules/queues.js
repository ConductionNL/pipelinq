/**
 * Queues store for Pipelinq — manages queue CRUD via OpenRegister API.
 */
import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'

export const useQueuesStore = defineStore('queues', {
	state: () => ({
		queues: [],
		currentQueue: null,
		queueItems: [],
		loading: false,
		error: null,
	}),
	getters: {
		activeQueues: (state) => state.queues.filter(q => q.isActive !== false),
		getQueueById: (state) => (id) => state.queues.find(q => q.id === id),
	},
	actions: {
		async fetchQueues() {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObjects('queue', { _limit: 100, _order: 'sortOrder' })
				this.queues = result || []
			} catch (error) {
				this.error = error.message
				console.error('Error fetching queues:', error)
			} finally {
				this.loading = false
			}
		},

		async fetchQueue(id) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObject('queue', id)
				this.currentQueue = result
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error fetching queue:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		async saveQueue(data) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject('queue', data)
				if (result) {
					await this.fetchQueues()
				}
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error saving queue:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		async deleteQueue(id) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const success = await objectStore.deleteObject('queue', id)
				if (success) {
					this.queues = this.queues.filter(q => q.id !== id)
				}
				return success
			} catch (error) {
				this.error = error.message
				console.error('Error deleting queue:', error)
				return false
			} finally {
				this.loading = false
			}
		},

		async fetchQueueItems(queueId) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const config = objectStore.objectTypeRegistry.request
				if (!config) {
					this.queueItems = []
					return []
				}

				const url = `/apps/openregister/api/objects/${config.register}/${config.schema}?queue=${queueId}&_limit=200`
				const response = await fetch(url, {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})

				if (!response.ok) throw new Error('Failed to fetch queue items')
				const data = await response.json()
				this.queueItems = data.results || data || []
				return this.queueItems
			} catch (error) {
				this.error = error.message
				console.error('Error fetching queue items:', error)
				this.queueItems = []
				return []
			} finally {
				this.loading = false
			}
		},
	},
})
