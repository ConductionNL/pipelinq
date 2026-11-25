<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Admin: website chat channels (requests-web-chat-and-bot D2). List, add,
  switch on, and copy the line to paste into the website. A new channel is off.

  @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-1.2
-->
<template>
	<NcSettingsSection
:name="t('pipelinq', 'Web chat')"
		:description="t('pipelinq', 'A chat on your website. Visitors need no account; a chat they hand over becomes a ticket in the Queue.')">
		<ul class="web-chat-settings__list">
			<li v-for="channel in channels" :key="idOf(channel)" class="web-chat-settings__channel">
				<strong>{{ channel.name }}</strong>
				<NcCheckboxRadioSwitch
:modelValue="channel.active === true"
					type="switch"
					:disabled="busy"
					@update:modelValue="toggle(channel, $event)">
					{{ t('pipelinq', 'Switched on') }}
				</NcCheckboxRadioSwitch>
				<p class="web-chat-settings__origins">
					{{ (channel.allowedOrigins || []).join(', ') || t('pipelinq', 'No websites yet: the chat runs nowhere.') }}
				</p>
				<label :for="'embed-' + idOf(channel)">{{ t('pipelinq', 'Paste this line into the website') }}</label>
				<textarea
:id="'embed-' + idOf(channel)"
					class="web-chat-settings__embed"
					readonly
					rows="3"
					:value="embedOf(channel)" />
				<NcButton variant="secondary" @click="copy(channel)">
					{{ t('pipelinq', 'Copy') }}
				</NcButton>
			</li>
		</ul>
		<form class="web-chat-settings__add" @submit.prevent="add">
			<NcTextField v-model="draft.name" :label="t('pipelinq', 'Name')" />
			<NcTextArea v-model="draft.origins" :label="t('pipelinq', 'Websites, one per line, such as https://www.example.org')" />
			<NcTextField v-model="draft.greeting" :label="t('pipelinq', 'Greeting')" />
			<NcTextField v-model="draft.offlineText" :label="t('pipelinq', 'Text when nobody is available')" />
			<NcCheckboxRadioSwitch v-model="draft.assistantEnabled" type="switch">
				{{ t('pipelinq', 'The assistant answers first') }}
			</NcCheckboxRadioSwitch>
			<NcButton variant="primary" type="submit" :disabled="busy || draft.name.trim() === ''">
				{{ t('pipelinq', 'Add channel') }}
			</NcButton>
		</form>
	</NcSettingsSection>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcCheckboxRadioSwitch, NcSettingsSection, NcTextArea, NcTextField } from '@nextcloud/vue'
import { channelEmbedLine, fetchChannels, parseOrigins, saveChannel } from '../../services/webChatChannels.js'

/**
 * An empty draft channel.
 *
 * @return {object}
 */
function emptyDraft() {
	return { name: '', origins: '', greeting: '', offlineText: '', assistantEnabled: false }
}

export default {
	name: 'WebChatSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcSettingsSection, NcTextArea, NcTextField },
	data() {
		return { channels: [], draft: emptyDraft(), busy: false }
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * The id of a channel row.
		 *
		 * @param {object} channel The channel.
		 * @return {string}
		 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-1.2
		 */
		idOf(channel) {
			return channel.id || channel?.['@self']?.id || channel.channelToken
		},

		/**
		 * The embed line a website pastes for this channel.
		 *
		 * @param {object} channel The channel.
		 * @return {string}
		 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-1.2
		 */
		embedOf(channel) {
			return channelEmbedLine(channel)
		},

		/**
		 * Read the channels again.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-1.2
		 */
		async load() {
			try {
				this.channels = await fetchChannels()
			} catch {
				this.channels = []
			}
		},

		/**
		 * Save the draft as a new channel, off until switched on.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-1.2
		 */
		async add() {
			this.busy = true
			try {
				await saveChannel({
					name: this.draft.name.trim(),
					allowedOrigins: parseOrigins(this.draft.origins),
					greeting: this.draft.greeting.trim(),
					offlineText: this.draft.offlineText.trim(),
					assistantEnabled: this.draft.assistantEnabled === true,
				})
				this.draft = emptyDraft()
				await this.load()
			} catch {
				showError(t('pipelinq', 'The channel could not be saved.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Switch a channel on or off.
		 *
		 * @param {object} channel The channel.
		 * @param {boolean} on Whether it is on.
		 * @return {Promise<void>}
		 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-1.2
		 */
		async toggle(channel, on) {
			this.busy = true
			try {
				await saveChannel({ ...channel, active: on === true })
				await this.load()
			} catch {
				showError(t('pipelinq', 'The channel could not be saved.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Copy the channel's embed line to the clipboard.
		 *
		 * @param {object} channel The channel.
		 * @return {Promise<void>}
		 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-1.2
		 */
		async copy(channel) {
			try {
				await navigator.clipboard.writeText(this.embedOf(channel))
				showSuccess(t('pipelinq', 'Copied'))
			} catch {
				showError(t('pipelinq', 'Copy the line by hand.'))
			}
		},
	},
}
</script>

<style scoped>
.web-chat-settings__list {
	margin: 0 0 16px;
	padding: 0;
	list-style: none;
}

.web-chat-settings__channel {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.web-chat-settings__origins {
	color: var(--color-text-maxcontrast);
}

.web-chat-settings__embed {
	width: 100%;
	font-family: monospace;
}

.web-chat-settings__add {
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 600px;
}
</style>
