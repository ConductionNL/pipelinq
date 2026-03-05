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
		async fetchProspects(refresh = false) {
			this.loading = true
			this.error = null

			try {
				const url = `/apps/pipelinq/api/prospects${refresh ? '?refresh=true' : ''}`
				const response = await fetch(url, {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})

				const data = await response.json()

				if (!response.ok) {
					this.error = data.message || data.error || 'Failed to fetch prospects'
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

		async createLeadFromProspect(prospectData) {
			try {
				const response = await fetch('/apps/pipelinq/api/prospects/create-lead', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(prospectData),
				})

				const data = await response.json()

				if (!response.ok) {
					return { error: data.error || 'Failed to create lead' }
				}

				return data
			} catch (err) {
				return { error: err.message || 'Failed to create lead' }
			}
		},
	},
})
