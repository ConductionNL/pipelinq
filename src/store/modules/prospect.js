import { generateUrl } from '@nextcloud/router'
/**
 * Prospect store — fetches prospect discovery data from the Pipelinq API.
 */
import { defineStore } from 'pinia'

export const useProspectStore = defineStore('prospect', {
	state: () => ({
		prospects: [],
		total: 0,
		displayed: 0,
		cachedAt: null,
		icpHash: null,
		loading: false,
		error: null,
	}),
	actions: {
		/**
		 * @param {boolean} refresh Bypass the cache and refetch.
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-33
		 */
		async fetchProspects(refresh = false) {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl(
					`/apps/pipelinq/api/prospects${refresh ? '?refresh=true' : ''}`,
				)
				const response = await fetch(url, {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})

				const data = await response.json()

				if (!response.ok) {
					this.error =
						data.message || data.error || 'Failed to fetch prospects'
					return null
				}

				this.prospects = data.prospects || []
				this.total = data.total || 0
				this.displayed = data.displayed || 0
				this.cachedAt = data.cachedAt || null
				this.icpHash = data.icpHash || null

				return data
			} catch (err) {
				this.error = err.message || 'Failed to fetch prospects'
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * Drop one prospect from the in-memory list.
		 *
		 * Called after it has been added as a client: discovery already excludes
		 * companies that match an existing client by name, so a stale row would
		 * invite adding the same company a second time until the next refresh.
		 *
		 * @param {string} kvkNumber The prospect's KVK number, its identity here.
		 * @return {void}
		 * @spec openspec/specs/prospect-discovery/spec.md#requirement-existing-client-exclusion
		 */
		removeProspect(kvkNumber) {
			this.prospects = this.prospects.filter((p) => p.kvkNumber !== kvkNumber)
		},
	},
})
