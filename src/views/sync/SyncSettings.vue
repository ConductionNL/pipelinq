<template>
	<div class="sync-settings">
		<h2>{{ t('pipelinq', 'Email and Calendar Sync') }}</h2>
		<p class="section-description">
			{{ t('pipelinq', 'Configure how emails and calendar events are synced with your CRM entities.') }}
		</p>

		<div class="settings-section">
			<h3>{{ t('pipelinq', 'Email Sync') }}</h3>
			<NcCheckboxRadioSwitch
				:checked="syncSettings.email_sync_enabled"
				:loading="saving.email_sync_enabled"
				type="switch"
				@update:checked="v => updateSetting('email_sync_enabled', v)">
				{{ t('pipelinq', 'Enable email synchronization') }}
			</NcCheckboxRadioSwitch>
			<p class="setting-hint">
				{{ t('pipelinq', 'Automatically link incoming and outgoing emails to clients, contacts, leads, and requests.') }}
			</p>

			<label>{{ t('pipelinq', 'Mail Account') }}</label>
			<NcSelect
				v-model="syncSettings.mail_account"
				:options="mailAccounts"
				:placeholder="t('pipelinq', 'Select a mail account')"
				:loading="loadingAccounts"
				@input="v => updateSetting('mail_account', v)" />
			<p class="setting-hint">
				{{ t('pipelinq', 'Choose which mail account to sync emails from.') }}
			</p>
		</div>

		<div class="settings-section">
			<h3>{{ t('pipelinq', 'Calendar Sync') }}</h3>
			<NcCheckboxRadioSwitch
				:checked="syncSettings.calendar_sync_enabled"
				:loading="saving.calendar_sync_enabled"
				type="switch"
				@update:checked="v => updateSetting('calendar_sync_enabled', v)">
				{{ t('pipelinq', 'Enable calendar synchronization') }}
			</NcCheckboxRadioSwitch>
			<p class="setting-hint">
				{{ t('pipelinq', 'Create and link calendar follow-up events to CRM entities.') }}
			</p>

			<label>{{ t('pipelinq', 'Default Calendar') }}</label>
			<NcSelect
				v-model="syncSettings.default_calendar"
				:options="calendars"
				:placeholder="t('pipelinq', 'Select a calendar')"
				:loading="loadingCalendars"
				@input="v => updateSetting('default_calendar', v)" />
			<p class="setting-hint">
				{{ t('pipelinq', 'Choose which calendar to use for creating follow-up events.') }}
			</p>
		</div>

		<div class="settings-section">
			<h3>{{ t('pipelinq', 'Privacy') }}</h3>
			<NcCheckboxRadioSwitch
				:checked="syncSettings.exclude_personal_emails"
				:loading="saving.exclude_personal_emails"
				type="switch"
				@update:checked="v => updateSetting('exclude_personal_emails', v)">
				{{ t('pipelinq', 'Exclude personal email domains') }}
			</NcCheckboxRadioSwitch>
			<p class="setting-hint">
				{{ t('pipelinq', 'Do not sync emails from personal domains (gmail, yahoo, etc.).') }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'

export default {
	name: 'SyncSettings',
	components: {
		NcCheckboxRadioSwitch,
		NcSelect,
	},
	data() {
		return {
			syncSettings: {
				email_sync_enabled: false,
				calendar_sync_enabled: false,
				mail_account: null,
				default_calendar: null,
				exclude_personal_emails: true,
			},
			saving: {
				email_sync_enabled: false,
				calendar_sync_enabled: false,
				mail_account: false,
				default_calendar: false,
				exclude_personal_emails: false,
			},
			mailAccounts: [],
			calendars: [],
			loadingAccounts: false,
			loadingCalendars: false,
		}
	},
	mounted() {
		this.fetchSettings()
		this.fetchMailAccounts()
		this.fetchCalendars()
	},
	methods: {
		async fetchSettings() {
			try {
				const response = await fetch('/apps/pipelinq/api/sync/settings', {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})
				if (response.ok === true) {
					const data = await response.json()
					this.syncSettings = { ...this.syncSettings, ...data }
				}
			} catch (error) {
				console.error('Failed to fetch sync settings', error)
			}
		},
		async fetchMailAccounts() {
			this.loadingAccounts = true
			try {
				// In a real implementation, this would fetch from the Mail app
				this.mailAccounts = [
					{ label: 'Default Account', value: 'default' },
				]
			} catch (error) {
				console.error('Failed to fetch mail accounts', error)
			} finally {
				this.loadingAccounts = false
			}
		},
		async fetchCalendars() {
			this.loadingCalendars = true
			try {
				// In a real implementation, this would fetch from the Calendar app
				this.calendars = [
					{ label: 'Personal', value: 'personal' },
				]
			} catch (error) {
				console.error('Failed to fetch calendars', error)
			} finally {
				this.loadingCalendars = false
			}
		},
		async updateSetting(key, value) {
			this.saving[key] = true
			const oldValue = this.syncSettings[key]
			this.syncSettings[key] = value

			try {
				const response = await fetch('/apps/pipelinq/api/sync/settings', {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify({ [key]: value }),
				})
				if (response.ok === true) {
					const data = await response.json()
					this.syncSettings = { ...this.syncSettings, ...data }
				} else {
					this.syncSettings[key] = oldValue
				}
			} catch (error) {
				console.error('Failed to update setting', error)
				this.syncSettings[key] = oldValue
			} finally {
				this.saving[key] = false
			}
		},
	},
}
</script>

<style scoped>
.sync-settings {
	padding: 20px;
	max-width: 600px;
}

h2 {
	margin-bottom: 10px;
	font-size: 1.5em;
	color: var(--color-main-text);
}

.section-description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 20px;
	font-size: 0.95em;
}

.settings-section {
	margin-bottom: 30px;
	padding-bottom: 20px;
	border-bottom: 1px solid var(--color-border);
}

.settings-section:last-child {
	border-bottom: none;
}

h3 {
	margin-bottom: 15px;
	font-size: 1.1em;
	color: var(--color-main-text);
}

label {
	display: block;
	margin-top: 15px;
	margin-bottom: 5px;
	font-weight: 600;
	color: var(--color-main-text);
}

.setting-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 5px 0 15px 0;
	line-height: 1.4;
}
</style>
