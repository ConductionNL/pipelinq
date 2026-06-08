<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="admin-settings-export-page">
		<ExportConfigurationSettings
			:config="config"
			@saved="onSaved" />
	</div>
</template>

<script>
import ExportConfigurationSettings from './settings/ExportConfigurationSettings.vue'
import { useSettingsStore } from '../store/modules/settings.js'

export default {
	name: 'AdminSettingsExportPage',
	components: {
		ExportConfigurationSettings,
	},
	data() {
		return {
			config: {},
		}
	},
	computed: {
		/**
		 * The shared settings store.
		 *
		 * @return {object} The store instance.
		 */
		settingsStore() {
			return useSettingsStore()
		},
	},
	async mounted() {
		const data = await this.settingsStore.fetchSettings()
		if (data) {
			this.config = data.config || {}
		}
	},
	methods: {
		/**
		 * Reflect saved config back into the store + local view state.
		 *
		 * @param {object} updated The updated config returned by the controller.
		 */
		onSaved(updated) {
			this.config = updated
			if (this.settingsStore && this.settingsStore.config) {
				this.settingsStore.config = { ...this.settingsStore.config, ...updated }
			}
		},
	},
}
</script>

<style scoped>
.admin-settings-export-page {
	max-width: 720px;
}
</style>
