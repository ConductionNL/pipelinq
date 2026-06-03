<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
-->
<template>
	<NcSettingsSection :name="t('pipelinq', 'Email & Calendar Sync')"
		:description="t('pipelinq', 'Automatically link emails to your CRM contacts and clients. Emails are matched by address and shown on the Email tab of each record. Calendar events are linked through the Calendar integration on each record.')">
		<div class="sync-settings">
			<NcCheckboxRadioSwitch type="switch"
				:checked.sync="enabled"
				@update:checked="onToggle">
				{{ t('pipelinq', 'Enable email matching') }}
			</NcCheckboxRadioSwitch>

			<template v-if="enabled">
				<div class="sync-settings__field">
					<NcSelect v-model="account"
						:options="accountOptions"
						:input-label="t('pipelinq', 'Mail account to index')"
						:placeholder="t('pipelinq', 'Select a mail account')"
						label="label"
						track-by="id" />
					<p v-if="accountOptions.length === 0" class="sync-settings__hint">
						{{ t('pipelinq', 'No mail accounts found. Configure an account in Nextcloud Mail first.') }}
					</p>
				</div>

				<div class="sync-settings__field">
					<NcTextArea :value.sync="excludedText"
						:label="t('pipelinq', 'Excluded addresses (one per line)')"
						placeholder="noreply@example.com" />
				</div>

				<div class="sync-settings__actions">
					<NcButton type="primary" :disabled="saving" @click="save">
						{{ t('pipelinq', 'Save') }}
					</NcButton>
					<NcButton type="secondary" :disabled="syncing" @click="syncNow">
						<template #icon>
							<NcLoadingIcon v-if="syncing" :size="20" />
						</template>
						{{ t('pipelinq', 'Sync now') }}
					</NcButton>
				</div>

				<div class="sync-settings__status">
					<h4>{{ t('pipelinq', 'Sync status') }}</h4>
					<p>
						{{ t('pipelinq', 'Last synced') }}:
						<strong>{{ lastRunLabel }}</strong>
					</p>
					<p>
						{{ t('pipelinq', 'Emails linked') }}:
						<strong>{{ status.linked }}</strong>
					</p>
					<NcNoteCard v-if="status.error" type="error">
						{{ status.error }}
					</NcNoteCard>
				</div>
			</template>
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection, NcCheckboxRadioSwitch, NcSelect, NcTextArea, NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'SyncSettings',
	components: {
		NcSettingsSection,
		NcCheckboxRadioSwitch,
		NcSelect,
		NcTextArea,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},
	data() {
		return {
			enabled: false,
			account: null,
			accountOptions: [],
			excludedText: '',
			saving: false,
			syncing: false,
			status: {
				lastRun: null,
				linked: 0,
				error: null,
			},
		}
	},
	computed: {
		lastRunLabel() {
			if (!this.status.lastRun) {
				return this.t('pipelinq', 'Never')
			}
			return new Date(this.status.lastRun).toLocaleString()
		},
	},
	created() {
		this.loadSettings()
		this.loadStatus()
	},
	methods: {
		async loadSettings() {
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/sync/email/settings'))
				this.enabled = data.enabled === true
				this.account = this.accountFromId(data.account)
				this.excludedText = (data.excludedAddresses || []).join('\n')
			} catch (error) {
				showError(this.t('pipelinq', 'Could not load sync settings'))
			}
		},
		async loadStatus() {
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/sync/email/status'))
				this.status = data
			} catch (error) {
				// Status is non-critical; leave defaults.
			}
		},
		accountFromId(id) {
			if (id === null || id === undefined) {
				return null
			}
			return this.accountOptions.find(option => option.id === id) || { id, label: String(id) }
		},
		onToggle(value) {
			this.enabled = value
		},
		async save() {
			this.saving = true
			try {
				const excludedAddresses = this.excludedText
					.split('\n')
					.map(line => line.trim())
					.filter(line => line.length > 0)
				await axios.post(generateUrl('/apps/pipelinq/api/sync/email/settings'), {
					enabled: this.enabled,
					account: this.account ? this.account.id : null,
					excludedAddresses,
				})
				showSuccess(this.t('pipelinq', 'Sync settings saved'))
			} catch (error) {
				showError(this.t('pipelinq', 'Could not save sync settings'))
			} finally {
				this.saving = false
			}
		},
		async syncNow() {
			this.syncing = true
			try {
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/sync/email/trigger'))
				this.status = data
				showSuccess(this.t('pipelinq', 'Sync started'))
			} catch (error) {
				showError(this.t('pipelinq', 'Could not run the sync'))
			} finally {
				this.syncing = false
			}
		},
	},
}
</script>

<style scoped>
.sync-settings__field {
	margin: 16px 0;
	max-width: 480px;
}

.sync-settings__hint {
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.sync-settings__actions {
	display: flex;
	gap: 8px;
	margin: 16px 0;
}

.sync-settings__status {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.sync-settings__status h4 {
	margin-bottom: 8px;
	font-weight: 600;
}
</style>
