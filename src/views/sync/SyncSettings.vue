<template>
	<div class="sync-settings">
		<h2>{{ t('pipelinq', 'Email & Calendar Sync') }}</h2>

		<div class="sync-settings__section">
			<h3>{{ t('pipelinq', 'Email Sync') }}</h3>
			<p class="sync-settings__description">
				{{ t('pipelinq', 'When enabled, emails from selected accounts are automatically linked to CRM contacts by matching email addresses.') }}
			</p>

			<label class="toggle-row">
				<input v-model="emailSyncEnabled" type="checkbox" @change="updateSyncEnabled">
				{{ t('pipelinq', 'Enable email sync') }}
			</label>

			<template v-if="emailSyncEnabled">
				<div class="sync-settings__accounts">
					<h4>{{ t('pipelinq', 'Mail accounts to sync') }}</h4>
					<p v-if="mailAccounts.length === 0" class="empty-hint">
						{{ t('pipelinq', 'No mail accounts found. Configure email accounts in Nextcloud Mail first.') }}
					</p>
					<div v-for="account in mailAccounts" :key="account.id" class="account-row">
						<label>
							<input
								v-model="selectedAccounts"
								type="checkbox"
								:value="account.id"
								@change="updateAccounts">
							{{ account.name }} ({{ account.email }})
						</label>
					</div>
				</div>

				<div class="sync-settings__status">
					<h4>{{ t('pipelinq', 'Sync Status') }}</h4>
					<div class="status-info">
						<span>{{ t('pipelinq', 'Last synced') }}: {{ lastSyncTime || t('pipelinq', 'Never') }}</span>
						<span>{{ t('pipelinq', 'Emails linked') }}: {{ emailsLinked }}</span>
					</div>
				</div>

				<div class="sync-settings__privacy">
					<h4>{{ t('pipelinq', 'Privacy') }}</h4>
					<p class="privacy-notice">
						{{ t('pipelinq', 'Email metadata (subject, sender, date) is stored in the CRM. Full email content is accessed on-demand from Nextcloud Mail and is only visible to the syncing user by default.') }}
					</p>
				</div>
			</template>
		</div>

		<div class="sync-settings__section">
			<h3>{{ t('pipelinq', 'Calendar Sync') }}</h3>
			<p class="sync-settings__description">
				{{ t('pipelinq', 'Follow-ups and meetings created in Pipelinq appear in your Nextcloud Calendar. Calendar events with known contacts are linked to their CRM profile.') }}
			</p>

			<label class="toggle-row">
				<input v-model="calendarSyncEnabled" type="checkbox" @change="updateCalendarSync">
				{{ t('pipelinq', 'Enable calendar sync') }}
			</label>

			<div v-if="calendarSyncEnabled" class="sync-settings__calendar-info">
				<p>{{ t('pipelinq', 'Events will be created in a dedicated "Pipelinq" calendar in your Nextcloud Calendar.') }}</p>
			</div>
		</div>
	</div>
</template>

<script>
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'SyncSettings',
	data() {
		return {
			emailSyncEnabled: false,
			calendarSyncEnabled: false,
			selectedAccounts: [],
			mailAccounts: [],
			lastSyncTime: null,
			emailsLinked: 0,
		}
	},
	mounted() {
		this.loadSettings()
	},
	methods: {
		async loadSettings() {
			// Load from user config
		},
		async updateSyncEnabled() {
			try {
				showSuccess(
					this.emailSyncEnabled
						? t('pipelinq', 'Email sync enabled')
						: t('pipelinq', 'Email sync disabled'),
				)
			} catch (error) {
				showError(t('pipelinq', 'Failed to update sync settings'))
			}
		},
		async updateAccounts() {
			// Save selected accounts
		},
		async updateCalendarSync() {
			try {
				showSuccess(
					this.calendarSyncEnabled
						? t('pipelinq', 'Calendar sync enabled')
						: t('pipelinq', 'Calendar sync disabled'),
				)
			} catch (error) {
				showError(t('pipelinq', 'Failed to update sync settings'))
			}
		},
	},
}
</script>

<style scoped>
.sync-settings { padding: 20px; max-width: 700px; margin: 0 auto; }
.sync-settings__section { margin-bottom: 32px; padding-bottom: 24px; border-bottom: 1px solid var(--color-border); }
.sync-settings__description { color: var(--color-text-lighter); margin-bottom: 16px; }
.toggle-row { display: flex; align-items: center; gap: 8px; padding: 8px 0; font-weight: 600; cursor: pointer; }
.sync-settings__accounts { margin-top: 16px; }
.account-row { padding: 6px 0; }
.account-row label { display: flex; align-items: center; gap: 8px; cursor: pointer; }
.sync-settings__status { margin-top: 16px; }
.status-info { display: flex; gap: 24px; font-size: 0.9em; color: var(--color-text-lighter); }
.sync-settings__privacy { margin-top: 16px; }
.privacy-notice { font-size: 0.85em; color: var(--color-text-lighter); background: var(--color-background-dark); padding: 12px; border-radius: var(--border-radius); }
.empty-hint { font-size: 0.9em; color: var(--color-text-lighter); font-style: italic; }
.sync-settings__calendar-info { margin-top: 12px; font-size: 0.9em; color: var(--color-text-lighter); }
</style>
