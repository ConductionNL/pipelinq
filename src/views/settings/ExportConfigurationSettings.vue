<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'BI Export Configuration')"
		:description="t('pipelinq', 'Defaults for the BI export and data-warehouse sink: retention, compression and failure alerts.')">
		<div class="export-config">
			<NcTextField
				v-model="form.retention_days"
				type="number"
				:label="t('pipelinq', 'Retention (days to keep runs)')"
				:helper-text="t('pipelinq', 'How long export-run audit records are kept. Default 365 days.')" />
			<NcSelect
				:model-value="selectedCompression"
				:options="compressionOptions"
				:input-label="t('pipelinq', 'Default compression')"
				label="label"
				:clearable="false"
				:helper-text="t('pipelinq', 'Used when a destination does not specify its own compression.')"
				@update:model-value="(o) => form.default_compression = o ? o.value : 'none'" />
			<NcTextField
				v-model="form.failure_notification_email"
				:label="t('pipelinq', 'Failure notification email')"
				:helper-text="t('pipelinq', 'Address to notify when an export run fails. Leave empty to disable.')"
				placeholder="alerts@example.com" />
			<NcTextField
				v-model="form.at_risk_warning_hours"
				type="number"
				:label="t('pipelinq', 'At-risk warning (hours without a successful run)')"
				:helper-text="t('pipelinq', 'Triggers an at-risk warning if no run has succeeded in this many hours.')" />
		</div>
		<NcButton
			variant="primary"
			:disabled="busy || invalid"
			class="export-config__save"
			@click="save">
			<template #icon>
				<NcLoadingIcon v-if="busy" :size="16" />
			</template>
			{{ t('pipelinq', 'Save Export Configuration') }}
		</NcButton>
		<NcNoteCard v-if="message" :type="messageType">
			{{ message }}
		</NcNoteCard>
	</NcSettingsSection>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

const COMPRESSION_VALUES = ['none', 'gzip', 'snappy', 'zstd']

export default {
	name: 'ExportConfigurationSettings',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcSettingsSection,
		NcTextField,
	},
	props: {
		/**
		 * The current pipelinq settings config object (from SettingsService::getSettings()).
		 * Provides initial values for the form on mount.
		 */
		config: {
			type: Object,
			default: () => ({}),
		},
	},
	emits: ['saved'],
	data() {
		return {
			busy: false,
			message: '',
			messageType: 'success',
			form: {
				retention_days: '365',
				default_compression: 'none',
				failure_notification_email: '',
				at_risk_warning_hours: '24',
			},
		}
	},
	computed: {
		/**
		 * Compression dropdown options.
		 *
		 * @return {Array<{value: string, label: string}>} The options.
		 */
		compressionOptions() {
			return COMPRESSION_VALUES.map((value) => ({ value, label: value }))
		},
		/**
		 * The currently selected compression option.
		 *
		 * @return {{value: string, label: string}|null} The option.
		 */
		selectedCompression() {
			return this.compressionOptions.find((o) => o.value === this.form.default_compression) || null
		},
		/**
		 * Whether the form fails basic validation (negative numbers / malformed email).
		 *
		 * @return {boolean} True when the save action must be disabled.
		 */
		invalid() {
			const days = Number(this.form.retention_days)
			const hours = Number(this.form.at_risk_warning_hours)
			if (Number.isNaN(days) || days < 1) {
				return true
			}
			if (Number.isNaN(hours) || hours < 1) {
				return true
			}
			const email = (this.form.failure_notification_email || '').trim()
			if (email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
				return true
			}
			return false
		},
	},
	watch: {
		config: {
			immediate: true,
			handler(value) {
				if (!value) {
					return
				}
				this.form.retention_days = String(value['export.retention_days'] ?? '365')
				this.form.default_compression = String(value['export.default_compression'] ?? 'none')
				this.form.failure_notification_email = String(value['export.failure_notification_email'] ?? '')
				this.form.at_risk_warning_hours = String(value['export.at_risk_warning_hours'] ?? '24')
			},
		},
	},
	methods: {
		/**
		 * Persist the form via the admin-gated settings endpoint.
		 *
		 * Only sends the four export keys to avoid trampling the rest of the
		 * tenant config; the backend ignores keys it does not recognise.
		 */
		async save() {
			if (this.invalid) {
				return
			}
			this.busy = true
			this.message = ''
			try {
				const payload = {
					'export.retention_days': String(this.form.retention_days),
					'export.default_compression': String(this.form.default_compression),
					'export.failure_notification_email': (this.form.failure_notification_email || '').trim(),
					'export.at_risk_warning_hours': String(this.form.at_risk_warning_hours),
				}
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/settings'), payload)
				if (data && data.success === true) {
					this.message = t('pipelinq', 'Export configuration saved.')
					this.messageType = 'success'
					this.$emit('saved', data.config || {})
				} else {
					this.message = (data && data.message) || t('pipelinq', 'Failed to save export configuration.')
					this.messageType = 'error'
				}
			} catch (e) {
				this.message = e.response?.data?.message || t('pipelinq', 'Failed to save export configuration.')
				this.messageType = 'error'
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.export-config {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 560px;
	margin-bottom: 16px;
}

.export-config__save {
	margin-bottom: 12px;
}
</style>
