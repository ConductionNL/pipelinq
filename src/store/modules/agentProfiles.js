import { generateUrl } from '@nextcloud/router'
/**
 * Agent Profiles store for Pipelinq — manages agent skill profiles via OpenRegister API.
 */
import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'

/**
 * GET an OpenRegister path as JSON.
 *
 * @param {string} path The path, with its query.
 * @return {Promise<(object|null)>} The answer, or null when the server refused.
 */
async function getObjectsJson(path) {
	const response = await fetch(generateUrl(path), {
		headers: {
			'Content-Type': 'application/json',
			requesttoken: OC.requestToken,
			'OCS-APIREQUEST': 'true',
		},
	})
	return response.ok ? await response.json() : null
}

/**
 * The open requests assigned to an agent. A request is a `ticket` with
 * ticketType 'request' (unify-ticket-supertype), so the count must be
 * narrowed by ticketType or complaints and contactmomenten would leak into
 * the agent's workload. A failed count is 0.
 *
 * @param {object} store The agent profiles store.
 * @param {{config: (object|undefined), userId: string}} scope The ticket type config and the agent.
 * @return {Promise<number>} The count.
 */
async function countAssignedRequests(store, scope) {
	const { config, userId } = scope
	if (!config) {
		return 0
	}
	try {
		const data = await getObjectsJson(
			`/apps/openregister/api/objects/${config.register}/${config.schema}?ticketType=request&assignee=${encodeURIComponent(userId)}&_limit=1`,
		)
		if (data === null) {
			return 0
		}
		// Filter out terminal statuses client-side for accurate count
		const results = data.results || data || []
		return results.filter
			? await store._countOpenRequests(config, userId)
			: data.total || 0
	} catch {
		// Silently continue
		return 0
	}
}

/**
 * The open leads assigned to an agent; a failed count is 0.
 *
 * @param {(object|undefined)} config The lead type config.
 * @param {string} userId The agent.
 * @return {Promise<number>} The count.
 */
async function countOpenLeads(config, userId) {
	if (!config) {
		return 0
	}
	try {
		const data = await getObjectsJson(
			`/apps/openregister/api/objects/${config.register}/${config.schema}?assignee=${encodeURIComponent(userId)}&status=open&_limit=1`,
		)
		return data === null ? 0 : data.total || 0
	} catch {
		// Silently continue
		return 0
	}
}

export const useAgentProfilesStore = defineStore('agentProfiles', {
	state: () => ({
		profiles: [],
		loading: false,
		error: null,
	}),
	getters: {
		availableProfiles: (state) =>
			state.profiles.filter((p) => p.isAvailable !== false),
		getProfileByUserId: (state) => (userId) =>
			state.profiles.find((p) => p.userId === userId),
	},
	actions: {
		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-store/tasks.md#task-3
		 */
		async fetchProfiles() {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchCollection('agentProfile', {
					_limit: 200,
				})
				this.profiles = result || []
			} catch (error) {
				this.error = error.message
				console.error('Error fetching agent profiles:', error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} data The agent profile to persist; an `id` makes it an update.
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-store/tasks.md#task-5
		 */
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

		/**
		 * @param {string} id Identifier of the agent profile to delete.
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-store/tasks.md#task-2
		 */
		async deleteProfile(id) {
			this.loading = true
			this.error = null
			try {
				const objectStore = useObjectStore()
				const success = await objectStore.deleteObject('agentProfile', id)
				if (success) {
					this.profiles = this.profiles.filter((p) => p.id !== id)
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
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-store/tasks.md#task-4
		 */
		async getWorkload(userId) {
			const registry = useObjectStore().objectTypeRegistry
			let count = 0
			count += await countAssignedRequests(this, { config: registry.ticket, userId })
			count += await countOpenLeads(registry.lead, userId)
			return count
		},

		/**
		 * Count open (non-terminal) requests for a user.
		 *
		 * @param {object} config Ticket type config (register/schema of the `ticket` supertype)
		 * @param {string} userId User ID
		 * @return {Promise<number>}
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-store/tasks.md#task-1
		 */
		async _countOpenRequests(config, userId) {
			const terminalStatuses = ['completed', 'rejected', 'converted']
			try {
				const data = await getObjectsJson(
					`/apps/openregister/api/objects/${config.register}/${config.schema}?ticketType=request&assignee=${encodeURIComponent(userId)}&_limit=200`,
				)
				if (data === null) return 0
				const results = data.results || data || []
				return results.filter((r) => !terminalStatuses.includes(r.status))
					.length
			} catch {
				return 0
			}
		},
	},
})
