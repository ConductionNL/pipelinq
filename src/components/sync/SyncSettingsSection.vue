<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  SyncSettingsSection

  Per-user email sync configuration section. Rendered inside
  NcAppSettingsDialog (user settings modal) — NOT a routed page.

  @spec openspec/changes/email-calendar-sync/tasks.md#task-3.2
-->
<template>
	<div class="sync-settings-section">
		<div class="sync-settings-section__row">
			<NcCheckboxRadioSwitch
				:checked.sync="enabled"
				type="switch"
				@update:checked="onToggleEnabled">
				{{ t('pipelinq', 'Enable email sync') }}
			</NcCheckboxRadioSwitch>
		</div>

		<template v-if="enabled">
			<div class="sync-settings-section__field">
				<label class="sync-settings-section__label" for="sync-account">
					{{ t('pipelinq', 'Mail account') }}
				</label>
				<NcSelect
					v-model="selectedAccount"
					input-id="sync-account"
					:input-label="t('pipelinq', 'Mail account')"
					:options="accountOptions"
					:placeholder="t('pipelinq', 'Select mail account')"
					track-by="value"
					label="label" />
			</div>

			<div class="sync-settings-section__field">
				<label class="sync-settings-section__label" for="sync-excluded">
					{{ t('pipelinq', 'Excluded email addresses') }}
				</label>
				<input
					id="sync-excluded"
					v-model="excludedRaw"
					class="sync-settings-section__input"
					type="text"
					:placeholder="t('pipelinq', 'Comma-separated email addresses to exclude')" />
			</div>

			<div class="sync-settings-section__status">
				<span class="sync-settings-section__status-label">{{ t('pipelinq', 'Last synced') }}:</span>
				<span class="sync-settings-section__status-value">{{ lastSyncDisplay }}</span>

				<span v-if="lastCount > 0" class="sync-settings-section__status-count">
					{{ t('pipelinq', 'Emails linked') }}: {{ lastCount }}
				</span>

				<span v-if="lastError" class="sync-settings-section__status-error">
					{{ lastError }}
				</span>
			</div>

			<div class="sync-settings-section__actions">
				<NcButton
					:disabled="saving"
					@click="onSave">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
				<NcButton
					type="secondary"
					:disabled="syncing"
					@click="onSyncNow">
					{{ syncing ? t('pipelinq', 'Syncing...') : t('pipelinq', 'Sync now') }}
				</NcButton>
			</div>
		</template>

		<div v-else class="sync-settings-section__actions">
			<NcButton
				:disabled="saving"
				@click="onSave">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { NcButton, NcCheckboxRadioSwitch, NcSelect } from '@conduction/nextcloud-vue'

export default {
	name: 'SyncSettingsSection',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcSelect,
	},

	data() {
		return {
			/** @type {boolean} Whether email sync is enabled for the current user. */
			enabled: false,
			/** @type {Array} List of mail account option objects for NcSelect. */
			accountOptions: [],
			/** @type {object|null} Currently selected mail account. */
			selectedAccount: null,
			/** @type {string} Comma-separated excluded addresses (raw input). */
			excludedRaw: '',
			/** @type {string|null} ISO timestamp of last sync. */
			lastSync: null,
			/** @type {number} Count of emails linked in last sync. */
			lastCount: 0,
			/** @type {string|null} Last sync error message. */
			lastError: null,
			/** @type {boolean} Saving state. */
			saving: false,
			/** @type {boolean} Syncing state. */
			syncing: false,
		}
	},

	computed: {
		/**
		 * Human-readable last-sync timestamp.
		 *
		 * @return {string}
		 * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.2
		 */
		lastSyncDisplay() {
			if (!this.lastSync) {
				return this.t('pipelinq', 'Never')
			}
			return new Date(this.lastSync).toLocaleString()
		},
	},

	created() {
		this.loadSettings()
		this.loadStatus()
	},

	methods: {
		/**
		 * Load sync settings from the backend.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.2
		 */
		async loadSettings() {
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/sync/email/settings'))
				this.enabled = data.enabled === true
				this.excludedRaw = (data.excludedAddresses || []).join(', ')
				if (data.accounts && data.accounts.length > 0) {
					this.selectedAccount = { value: data.accounts[0], label: data.accounts[0] }
				}
			} catch (error) {
				showError(this.t('pipelinq', 'Failed to load sync settings'))
			}
		},

		/**
		 * Load sync status from the backend.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.2
		 */
		async loadStatus() {
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/sync/email/status'))
				this.lastSync = data.lastRun
				this.lastCount = data.linked || 0
				this.lastError = data.lastError || null
			} catch (error) {
				// Status load failure is non-critical.
			}
		},

		/**
		 * Toggle sync enabled state.
		 *
		 * @param {boolean} checked The new checked state.
		 * @return {void}
		 * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.2
		 */
		onToggleEnabled(checked) {
			this.enabled = checked
		},

		/**
		 * Save sync settings to the backend.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.2
		 */
		async onSave() {
			this.saving = true
			try {
				const accounts = this.selectedAccount ? [this.selectedAccount.value] : []
				const excluded = this.excludedRaw
					.split(',')
					.map((a) => a.trim())
					.filter((a) => a.length > 0)

				await axios.post(generateUrl('/apps/pipelinq/api/sync/email/settings'), {
					enabled: this.enabled,
					accounts,
					excludedAddresses: excluded,
				})

				showSuccess(this.t('pipelinq', 'Sync settings saved'))
			} catch (error) {
				showError(this.t('pipelinq', 'Failed to save sync settings'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Manually trigger an email sync run.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/email-calendar-sync/tasks.md#task-3.2
		 */
		async onSyncNow() {
			this.syncing = true
			try {
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/sync/email/trigger'))
				showSuccess(this.t('pipelinq', 'Email sync triggered successfully'))
				this.lastCount = data.linked || 0
				await this.loadStatus()
			} catch (error) {
				showError(this.t('pipelinq', 'Failed to trigger email sync'))
			} finally {
				this.syncing = false
			}
		},
	},
}
</script>

<style scoped>
.sync-settings-section {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.sync-settings-section__row {
	display: flex;
	align-items: center;
}

.sync-settings-section__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.sync-settings-section__label {
	font-weight: 600;
	font-size: 0.9em;
	color: var(--color-text-lighter);
}

.sync-settings-section__input {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.sync-settings-section__status {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	font-size: 0.85em;
	color: var(--color-text-lighter);
}

.sync-settings-section__status-label {
	font-weight: 600;
}

.sync-settings-section__status-error {
	color: var(--color-error);
}

.sync-settings-section__actions {
	display: flex;
	gap: 8px;
}
</style>
