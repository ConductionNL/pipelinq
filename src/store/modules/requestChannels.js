import { defineStore } from 'pinia'
import { keepingError, loadSettingsTags, replaceTag, sendSettingsTagChange, settingsTagsUrl } from './settingsTags.js'

const API_BASE = settingsTagsUrl('request-channels')

export const useRequestChannelsStore = defineStore('requestChannels', {
	state: () => ({
		tags: [],
		loading: false,
		error: null,
	}),
	getters: {
		channelNames: (state) => state.tags.map((t) => t.name),
	},
	actions: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-40
		 */
		async fetchChannels() {
			await loadSettingsTags(this, { url: API_BASE, what: 'request channels' })
		},

		/**
		 * Create a request channel.
		 *
		 * @param {string} name The channel name to add.
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-39
		 */
		async addChannel(name) {
			return keepingError(this, async () => {
				const data = await sendSettingsTagChange(API_BASE, {
					method: 'POST',
					body: { name },
					failure: 'Failed to add channel',
				})
				this.tags.push(data.tag)
				return data.tag
			})
		},

		/**
		 * @param {string} id Identifier of the request channel.
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-41
		 */
		async removeChannel(id) {
			await keepingError(this, async () => {
				await sendSettingsTagChange(`${API_BASE}/${id}`, {
					method: 'DELETE',
					failure: 'Failed to remove channel',
				})
				this.tags = this.tags.filter((t) => t.id !== id)
			})
		},

		/**
		 * @param {string} id Identifier of the request channel.
		 * @param {string} name The new name.
		 * @spec openspec/changes/reverse-2026-05-26-fe-store/tasks.md#task-42
		 */
		async renameChannel(id, name) {
			return keepingError(this, async () => {
				const data = await sendSettingsTagChange(`${API_BASE}/${id}`, {
					method: 'PUT',
					body: { name },
					failure: 'Failed to rename channel',
				})
				replaceTag(this, { id, tag: data.tag })
				return data.tag
			})
		},
	},
})
