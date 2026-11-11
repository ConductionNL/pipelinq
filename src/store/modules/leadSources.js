import { defineStore } from 'pinia'
import { keepingError, loadSettingsTags, replaceTag, sendSettingsTagChange, settingsTagsUrl } from './settingsTags.js'

const API_BASE = settingsTagsUrl('lead-sources')

export const useLeadSourcesStore = defineStore('leadSources', {
	state: () => ({
		tags: [],
		loading: false,
		error: null,
	}),
	getters: {
		sourceNames: (state) => state.tags.map((t) => t.name),
	},
	actions: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-29
		 */
		async fetchSources() {
			await loadSettingsTags(this, { url: API_BASE, what: 'lead sources' })
		},

		/**
		 * Create a lead source.
		 *
		 * @param {string} name The source name to add.
		 * @return {Promise<void>}
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-28
		 */
		async addSource(name) {
			return keepingError(this, async () => {
				const data = await sendSettingsTagChange(API_BASE, {
					method: 'POST',
					body: { name },
					failure: 'Failed to add source',
				})
				this.tags.push(data.tag)
				return data.tag
			})
		},

		/**
		 * @param {string} id Identifier of the lead source.
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-30
		 */
		async removeSource(id) {
			await keepingError(this, async () => {
				await sendSettingsTagChange(`${API_BASE}/${id}`, {
					method: 'DELETE',
					failure: 'Failed to remove source',
				})
				this.tags = this.tags.filter((t) => t.id !== id)
			})
		},

		/**
		 * @param {string} id Identifier of the lead source.
		 * @param {string} name The new name.
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-31
		 */
		async renameSource(id, name) {
			return keepingError(this, async () => {
				const data = await sendSettingsTagChange(`${API_BASE}/${id}`, {
					method: 'PUT',
					body: { name },
					failure: 'Failed to rename source',
				})
				replaceTag(this, { id, tag: data.tag })
				return data.tag
			})
		},
	},
})
