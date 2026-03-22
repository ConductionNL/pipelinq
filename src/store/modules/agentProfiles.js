/**
 * Agent Profiles store for Pipelinq — manages agent skill profiles via OpenRegister API.
 */
import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'

export const useAgentProfilesStore = defineStore('agentProfiles', {
	state: () => ({
		profiles: [],
		loading: false,
		error: null,
	}),
	getters: {
		availableProfiles: (state) => state.profiles.filter(p => p.isAvailable !== false),
		getProfileByUserId: (state) => (userId) => state.profiles.find(p => p.userId === userId),
	},
	actions: {
		async fetchProfiles() {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObjects('agentProfile', { _limit: 200 })
				this.profiles = result || []
			} catch (error) {
				this.error = error.message
				console.error('Error fetching agent profiles:', error)
			} finally {
				this.loading = false
			}
		},

		async saveProfile(data) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.saveObject('agentProfile', data)
				if (result) {
					await this.fetchProfiles()
				}
				return result
			} catch (error) {
				this.error = error.message
				console.error('Error saving agent profile:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		async deleteProfile(id) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const success = await objectStore.deleteObject('agentProfile', id)
				if (success) {
					this.profiles = this.profiles.filter(p => p.id !== id)
				}
				return success
			} catch (error) {
				this.error = error.message
				console.error('Error deleting agent profile:', error)
				return false
			} finally {
				this.loading = false
			}
		},

		/**
		 * Calculate current workload for an agent (count of open assigned items).
		 *
		 * @param {string} userId Nextcloud user UID
		 * @return {Promise<number>} Open item count
		 */
		async getWorkload(userId) {
			const objectStore = useObjectStore()
			let count = 0

			// Count open requests
			const requestConfig = objectStore.objectTypeRegistry.request
			if (requestConfig) {
				try {
					const url = `/apps/openregister/api/objects/${requestConfig.register}/${requestConfig.schema}?assignee=${encodeURIComponent(userId)}&_limit=1`
					const response = await fetch(url, {
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					})
					if (response.ok) {
						const data = await response.json()
						// Filter out terminal statuses client-side for accurate count
						const results = data.results || data || []
						count += results.filter
							? (await this._countOpenRequests(requestConfig, userId))
							: (data.total || 0)
					}
				} catch {
					// Silently continue
				}
			}

			// Count open leads
			const leadConfig = objectStore.objectTypeRegistry.lead
			if (leadConfig) {
				try {
					const url = `/apps/openregister/api/objects/${leadConfig.register}/${leadConfig.schema}?assignee=${encodeURIComponent(userId)}&status=open&_limit=1`
					const response = await fetch(url, {
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					})
					if (response.ok) {
						const data = await response.json()
						count += data.total || 0
					}
				} catch {
					// Silently continue
				}
			}

			return count
		},

		/**
		 * Count open (non-terminal) requests for a user.
		 *
		 * @param {object} config Request type config
		 * @param {string} userId User ID
		 * @return {Promise<number>}
		 */
		async _countOpenRequests(config, userId) {
			const terminalStatuses = ['completed', 'rejected', 'converted']
			try {
				const url = `/apps/openregister/api/objects/${config.register}/${config.schema}?assignee=${encodeURIComponent(userId)}&_limit=200`
				const response = await fetch(url, {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})
				if (!response.ok) return 0
				const data = await response.json()
				const results = data.results || data || []
				return results.filter(r => !terminalStatuses.includes(r.status)).length
			} catch {
				return 0
			}
		},
	},
})
