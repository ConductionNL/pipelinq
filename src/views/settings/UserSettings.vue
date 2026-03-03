<template>
	<NcAppSettingsDialog
		:open.sync="open"
		:show-navigation="true"
		:name="t('pipelinq', 'Pipelinq settings')">
		<NcAppSettingsSection
			id="notifications"
			:name="t('pipelinq', 'Notifications')">
			<template #icon>
				<Bell :size="20" />
			</template>

			<p class="section-description">
				{{ t('pipelinq', 'Choose which notifications you want to receive.') }}
			</p>

			<NcCheckboxRadioSwitch
				:checked="settings.notify_assignments"
				:loading="saving.notify_assignments"
				type="switch"
				@update:checked="v => updateSetting('notify_assignments', v)">
				{{ t('pipelinq', 'Lead & request assignments') }}
			</NcCheckboxRadioSwitch>
			<p class="setting-hint">
				{{ t('pipelinq', 'Get notified when a lead or request is assigned to you.') }}
			</p>

			<NcCheckboxRadioSwitch
				:checked="settings.notify_stage_status"
				:loading="saving.notify_stage_status"
				type="switch"
				@update:checked="v => updateSetting('notify_stage_status', v)">
				{{ t('pipelinq', 'Pipeline stage & status changes') }}
			</NcCheckboxRadioSwitch>
			<p class="setting-hint">
				{{ t('pipelinq', 'Get notified when the stage or status changes on items assigned to you.') }}
			</p>

			<NcCheckboxRadioSwitch
				:checked="settings.notify_notes"
				:loading="saving.notify_notes"
				type="switch"
				@update:checked="v => updateSetting('notify_notes', v)">
				{{ t('pipelinq', 'Notes & comments') }}
			</NcCheckboxRadioSwitch>
			<p class="setting-hint">
				{{ t('pipelinq', 'Get notified when someone adds a note to items assigned to you.') }}
			</p>
		</NcAppSettingsSection>
	</NcAppSettingsDialog>
</template>

<script>
import { NcAppSettingsDialog, NcAppSettingsSection, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Bell from 'vue-material-design-icons/Bell.vue'

export default {
	name: 'UserSettings',
	components: {
		NcAppSettingsDialog,
		NcAppSettingsSection,
		NcCheckboxRadioSwitch,
		Bell,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			settings: {
				notify_assignments: true,
				notify_stage_status: true,
				notify_notes: true,
			},
			saving: {
				notify_assignments: false,
				notify_stage_status: false,
				notify_notes: false,
			},
		}
	},
	watch: {
		open(newVal) {
			if (newVal === true) {
				this.fetchSettings()
			}
		},
	},
	mounted() {
		if (this.open === true) {
			this.fetchSettings()
		}
	},
	methods: {
		async fetchSettings() {
			try {
				const response = await fetch('/apps/pipelinq/api/user/settings', {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})
				if (response.ok === true) {
					const data = await response.json()
					this.settings = { ...this.settings, ...data }
				}
			} catch (error) {
				console.error('Failed to fetch user settings', error)
			}
		},
		async updateSetting(key, value) {
			this.saving[key] = true
			this.settings[key] = value

			try {
				const response = await fetch('/apps/pipelinq/api/user/settings', {
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
					this.settings = { ...this.settings, ...data }
				}
			} catch (error) {
				console.error('Failed to update setting', error)
				this.settings[key] = !value
			} finally {
				this.saving[key] = false
			}
		},
	},
}
</script>

<style scoped>
.section-description {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.setting-hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0 0 16px 36px;
}
</style>
