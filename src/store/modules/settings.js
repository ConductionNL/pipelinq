import { generateUrl } from '@nextcloud/router'
import { defineStore } from 'pinia'

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		config: null,
		openRegisters: false,
		isAdmin: false,
		loading: false,
		error: null,
		initialized: false,
	}),
	getters: {
		isLoading: (state) => state.loading,
		getError: (state) => state.error,
		isInitialized: (state) => state.initialized,
		getConfig: (state) => state.config,
		hasOpenRegisters: (state) => state.openRegisters,
		getIsAdmin: (state) => state.isAdmin,
		/**
		 * Get configured SLA hours for a complaint category.
		 *
		 * @param {object} state The store state.
		 * @return {function(string): number}
		 */
		getComplaintSlaHours: (state) => (category) => {
			if (!state.config) return 0
			const key = 'complaint_sla_' + category
			const value = parseInt(state.config[key], 10)
			return isNaN(value) ? 0 : value
		},
		/**
		 * Days a lead can stay untouched before it is flagged as stale.
		 * Drives the kanban stale badge + the LeadList stale filter.
		 *
		 * @param {object} state Store state.
		 * @return {number}
		 * @spec openspec/specs/lead-management/spec.md
		 */
		getLeadStaleThresholdDays: (state) => {
			if (!state.config) return 14
			const value = parseInt(state.config.lead_stale_threshold_days, 10)
			if (!Number.isFinite(value) || value <= 0) return 14
			return value
		},
	},
	actions: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-43
		 */
		async fetchSettings() {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/settings'),
					{
						method: 'GET',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					},
				)

				if (!response.ok) {
					throw new Error(
						`Failed to fetch settings: ${response.statusText}`,
					)
				}

				const data = await response.json()
				this.config = data.config || data
				this.openRegisters = data.openRegisters ?? false
				this.isAdmin = data.isAdmin ?? false
				this.initialized = true

				return data
			} catch (error) {
				this.error = error.message
				console.error('Error fetching Pipelinq settings:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} settingsData The settings payload to persist.
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-44
		 */
		async saveSettings(settingsData) {
			this.loading = true
			this.error = null

			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/settings'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify(settingsData),
					},
				)

				if (!response.ok) {
					throw new Error(
						`Failed to save settings: ${response.statusText}`,
					)
				}

				const data = await response.json()
				this.config = data.config || data

				return this.config
			} catch (error) {
				this.error = error.message
				console.error('Error saving Pipelinq settings:', error)
				return null
			} finally {
				this.loading = false
			}
		},
	},
})
