<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - CtiSettings is the admin page used to configure the CTI screen-pop /
  - click-to-dial adapter. Reads and writes /api/cti/config.
  -
  - @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.5
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'CTI integration')"
		:description="t('pipelinq', 'Configure the telephony platform that powers screen-pop and click-to-dial.')">
		<form class="cti-settings" @submit.prevent="save">
			<NcSelect
				v-model="config.platform"
				:options="platformOptions"
				:input-label="t('pipelinq', 'Platform')"
				label="label"
				:reduce="(o) => o.value" />
			<NcTextField
				v-model="config.api_base_url"
				:label="t('pipelinq', 'API base URL')"
				:placeholder="t('pipelinq', 'https://api.example.com/v2')" />
			<NcSelect
				v-model="config.auth_method"
				:options="authMethodOptions"
				:input-label="t('pipelinq', 'Auth method')"
				label="label"
				:reduce="(o) => o.value" />
			<NcTextField
				v-model="config.credentials_ref"
				:label="t('pipelinq', 'OpenConnector credential reference')"
				:placeholder="t('pipelinq', 'Source UUID / slug')" />
			<NcTextField
				v-model="config.default_outbound_caller_id"
				:label="t('pipelinq', 'Default outbound caller ID')"
				:placeholder="t('pipelinq', '+31303033000')" />
			<NcTextField
				v-model.number="config.screen_pop_delay_ms"
				:label="t('pipelinq', 'Screen-pop delay (ms)')"
				type="number"
				min="0"
				max="5000" />
			<NcTextField
				v-model="config.default_country_code"
				:label="t('pipelinq', 'Default country code (ISO-3166)')"
				placeholder="NL" />
			<NcCheckboxRadioSwitch
				v-model="config.screen_pop_enabled"
				type="switch">
				{{ t('pipelinq', 'Enable inbound screen-pop') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch
				v-model="config.click_to_dial_enabled"
				type="switch">
				{{ t('pipelinq', 'Enable outbound click-to-dial') }}
			</NcCheckboxRadioSwitch>
			<div class="cti-settings__actions">
				<NcButton variant="secondary" :disabled="testing" @click="test">
					{{ testing ? t('pipelinq', 'Testing…') : t('pipelinq', 'Test connection') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ saving ? t('pipelinq', 'Saving…') : t('pipelinq', 'Save') }}
				</NcButton>
			</div>
			<p v-if="status" class="cti-settings__status">
				{{ status }}
			</p>
		</form>
	</NcSettingsSection>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { getConfig, testConnection, updateConfig } from '../../services/ctiApi.js'

export default {
	name: 'CtiSettings',
	components: { NcButton, NcCheckboxRadioSwitch, NcSelect, NcSettingsSection, NcTextField },
	data() {
		return {
			config: {
				platform: 'callvoip',
				api_base_url: '',
				auth_method: 'webhook-secret',
				credentials_ref: '',
				screen_pop_enabled: true,
				screen_pop_delay_ms: 0,
				click_to_dial_enabled: true,
				default_outbound_caller_id: '',
				default_country_code: 'NL',
			},
			saving: false,
			testing: false,
			status: '',
		}
	},
	computed: {
		platformOptions() {
			return [
				{ value: 'callvoip', label: 'CallVoip' },
				{ value: 'ringcentral', label: 'RingCentral' },
				{ value: 'asterisk', label: 'Asterisk' },
				{ value: 'other', label: t('pipelinq', 'Other') },
			]
		},
		authMethodOptions() {
			return [
				{ value: 'basic', label: 'Basic' },
				{ value: 'oauth', label: 'OAuth 2.0' },
				{ value: 'api_key', label: 'API key' },
				{ value: 'webhook-secret', label: t('pipelinq', 'Webhook shared secret') },
			]
		},
	},
	async mounted() {
		try {
			const config = await getConfig()
			this.config = { ...this.config, ...(config || {}) }
		} catch (e) {
			showError(t('pipelinq', 'Failed to load CTI config: {error}', { error: e.message || 'network error' }))
		}
	},
	methods: {
		async save() {
			this.saving = true
			try {
				const saved = await updateConfig(this.config)
				this.config = { ...this.config, ...saved }
				showSuccess(t('pipelinq', 'CTI configuration saved.'))
			} catch (e) {
				showError(t('pipelinq', 'Failed to save CTI config: {error}', { error: e.message || 'network error' }))
			} finally {
				this.saving = false
			}
		},
		async test() {
			this.testing = true
			this.status = ''
			try {
				const result = await testConnection()
				if (result.ok) {
					this.status = t('pipelinq', 'Connection OK ({platform})', { platform: result.platform })
				} else {
					this.status = t('pipelinq', 'Connection failed: {message}', { message: result.message })
				}
			} catch (e) {
				this.status = t('pipelinq', 'Connection test errored: {error}', { error: e.message || 'unknown' })
			} finally {
				this.testing = false
			}
		},
	},
}
</script>

<style scoped>
.cti-settings {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 720px;
}
.cti-settings__actions {
	display: flex;
	gap: 12px;
	justify-content: flex-end;
}
.cti-settings__status {
	margin-top: 8px;
	font-style: italic;
}
</style>
