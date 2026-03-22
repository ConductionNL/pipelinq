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
	},
	actions: {
		async fetchSettings() {
			this.loading = true
			this.error = null

			try {
				const response = await fetch('/apps/pipelinq/api/settings', {
					method: 'GET',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})

				if (!response.ok) {
					throw new Error(`Failed to fetch settings: ${response.statusText}`)
				}

				const data = await response.json()
				this.config = data.config || data
				this.openRegisters = data.openRegisters ?? false
				this.isAdmin = data.isAdmin ?? false
				this.initialized = true

				return this.config
			} catch (error) {
				this.error = error.message
				console.error('Error fetching Pipelinq settings:', error)
				return null
			} finally {
				this.loading = false
			}
		},

		async saveSettings(settingsData) {
			this.loading = true
			this.error = null

			try {
				const response = await fetch('/apps/pipelinq/api/settings', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(settingsData),
				})

				if (!response.ok) {
					throw new Error(`Failed to save settings: ${response.statusText}`)
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
