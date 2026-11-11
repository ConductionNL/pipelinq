import { generateUrl } from '@nextcloud/router'
/**
 * Prospect store — fetches prospect discovery data from the Pipelinq API.
 */
import { defineStore } from 'pinia'

/**
 * Every prospect: the page paginates them and the widget shows the top 10.
 *
 * @param {boolean} refresh Bypass the cache.
 * @return {string} The prospects URL.
 */
function prospectsUrl(refresh) {
	return generateUrl(
		`/apps/pipelinq/api/prospects?limit=0${refresh ? '&refresh=true' : ''}`,
	)
}

/**
 * A refused fetch: say why, and show no prospects.
 *
 * @param {object} store The prospect store.
 * @param {object} data The refusal.
 * @return {void}
 */
function showRefusal(store, data) {
	store.error =
		data.message || data.error || 'Failed to fetch prospects'
	store.prospects = []
	store.total = 0
}

/**
 * @param {object} store The prospect store.
 * @param {object} data The answer.
 * @return {void}
 */
function showProspects(store, data) {
	store.prospects = data.prospects || []
	store.total = data.total || 0
	store.displayed = data.displayed || 0
	showCacheState(store, data)
}

/**
 * @param {object} store The prospect store.
 * @param {object} data The answer.
 * @return {void}
 */
function showCacheState(store, data) {
	store.cachedAt = data.cachedAt || null
	store.icpHash = data.icpHash || null
}

export const useProspectStore = defineStore('prospect', {
	state: () => ({
		prospects: [],
		total: 0,
		displayed: 0,
		cachedAt: null,
		icpHash: null,
		// The profile the prospects are matched on; also set when none is configured.
		icp: null,
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
				const response = await fetch(prospectsUrl(refresh), {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})

				const data = await response.json()
				this.icp = data.icp || null

				if (!response.ok) {
					showRefusal(this, data)
					return null
				}

				showProspects(this, data)
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
