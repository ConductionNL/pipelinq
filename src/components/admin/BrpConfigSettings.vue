<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'BRP Configuration')"
		:description="t('pipelinq', 'HaalCentraal Personen (BRP) connection settings. Secrets are encrypted at rest and never returned to the browser.')">
		<NcLoadingIcon v-if="loading" />

		<div v-else class="brp-config">
			<div class="brp-config__field">
				<NcTextField :value.sync="form['brp.oauth_endpoint']" :label="t('pipelinq', 'OAuth2 endpoint URL')" />
			</div>
			<div class="brp-config__field">
				<NcTextField :value.sync="form['brp.personen_endpoint']" :label="t('pipelinq', 'Personen API endpoint URL')" />
			</div>
			<div class="brp-config__field">
				<NcTextField :value.sync="form['brp.client_id']" :label="t('pipelinq', 'Client ID')" />
			</div>
			<div class="brp-config__field">
				<NcPasswordField
					:value.sync="clientSecret"
					:label="t('pipelinq', 'Client secret')"
					:placeholder="clientSecretSet ? t('pipelinq', '•••••••• (set — leave blank to keep)') : ''" />
			</div>
			<div class="brp-config__field">
				<NcTextField :value.sync="form['brp.cert_path']" :label="t('pipelinq', 'mTLS certificate path (PKIoverheid)')" />
			</div>
			<div class="brp-config__field">
				<NcTextField :value.sync="form['brp.key_path']" :label="t('pipelinq', 'mTLS private key path')" />
			</div>
			<div class="brp-config__field">
				<NcTextField :value.sync="form['brp.ca_bundle']" :label="t('pipelinq', 'CA bundle path')" />
			</div>

			<div class="brp-config__row">
				<div class="brp-config__field">
					<NcTextField :value.sync="form['brp.cache_ttl_hours']" type="number" :label="t('pipelinq', 'Cache TTL (hours)')" />
				</div>
				<div class="brp-config__field">
					<NcTextField :value.sync="form['brp.retention_days']" type="number" :label="t('pipelinq', 'Retention period (days)')" />
				</div>
				<div class="brp-config__field">
					<NcTextField :value.sync="form['brp.health_check_timezone']" :label="t('pipelinq', 'Health check timezone')" />
				</div>
			</div>

			<div class="brp-config__row">
				<div class="brp-config__field">
					<NcTextField :value.sync="form['brp.role_group_burgerzaken']" :label="t('pipelinq', 'Authorized group — Burgerzaken')" />
				</div>
				<div class="brp-config__field">
					<NcTextField :value.sync="form['brp.role_group_avg']" :label="t('pipelinq', 'Authorized group — AVG')" />
				</div>
			</div>

			<div class="brp-config__webhook">
				<span>
					{{ t('pipelinq', 'Mutation webhook secret') }}:
					<strong>{{ webhookSecretSet ? t('pipelinq', 'configured') : t('pipelinq', 'not configured') }}</strong>
				</span>
				<NcCheckboxRadioSwitch :checked.sync="resetWebhook">
					{{ t('pipelinq', 'Regenerate webhook secret on save') }}
				</NcCheckboxRadioSwitch>
			</div>

			<p v-if="message" class="brp-config__message" :class="{ 'brp-config__message--error': isError }">
				{{ message }}
			</p>

			<div class="brp-config__actions">
				<NcButton type="primary" :disabled="saving" @click="save">
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="20" />
					</template>
					{{ t('pipelinq', 'Save BRP configuration') }}
				</NcButton>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection, NcLoadingIcon, NcButton, NcTextField, NcPasswordField, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'BrpConfigSettings',
	components: {
		NcSettingsSection,
		NcLoadingIcon,
		NcButton,
		NcTextField,
		NcPasswordField,
		NcCheckboxRadioSwitch,
	},
	data() {
		return {
			loading: true,
			saving: false,
			message: '',
			isError: false,
			clientSecret: '',
			resetWebhook: false,
			clientSecretSet: false,
			webhookSecretSet: false,
			form: {
				'brp.oauth_endpoint': '',
				'brp.personen_endpoint': '',
				'brp.client_id': '',
				'brp.cert_path': '',
				'brp.key_path': '',
				'brp.ca_bundle': '',
				'brp.cache_ttl_hours': '24',
				'brp.retention_days': '7',
				'brp.health_check_timezone': 'UTC',
				'brp.role_group_burgerzaken': 'behandelaar-burgerzaken',
				'brp.role_group_avg': 'behandelaar-avg',
			},
		}
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/**
		 * Load the current configuration (presence flags only for secrets).
		 */
		async load() {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/pipelinq/api/brp/config'), {
					headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' },
				})
				const data = await response.json().catch(() => ({}))
				if (response.ok) {
					this.applyConfig(data)
				}
			} finally {
				this.loading = false
			}
		},
		/**
		 * Apply a config payload onto the form and presence flags.
		 *
		 * @param {object} data The config payload.
		 */
		applyConfig(data) {
			for (const key of Object.keys(this.form)) {
				if (data[key] !== undefined) {
					this.form[key] = String(data[key])
				}
			}
			this.clientSecretSet = !!data['brp.client_secret_set']
			this.webhookSecretSet = !!data['brp.webhook_secret_set']
		},
		/**
		 * Persist the configuration; the client secret is only sent when typed.
		 */
		async save() {
			this.saving = true
			this.message = ''
			this.isError = false
			try {
				const body = { ...this.form }
				if (this.clientSecret) {
					body['brp.client_secret'] = this.clientSecret
				}
				if (this.resetWebhook) {
					body['brp.reset_webhook_secret'] = true
				}
				const response = await fetch(generateUrl('/apps/pipelinq/api/brp/config'), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(body),
				})
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					this.isError = true
					this.message = data.error || t('pipelinq', 'Could not save the BRP configuration')
					return
				}
				this.applyConfig(data)
				this.clientSecret = ''
				this.resetWebhook = false
				this.message = t('pipelinq', 'BRP configuration saved')
			} catch (e) {
				this.isError = true
				this.message = e.message || t('pipelinq', 'Could not save the BRP configuration')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.brp-config {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 720px;
}

.brp-config__row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}

.brp-config__row .brp-config__field {
	flex: 1;
	min-width: 180px;
}

.brp-config__webhook {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 10px 0;
}

.brp-config__message {
	font-size: 13px;
	color: var(--color-success);
}

.brp-config__message--error {
	color: var(--color-error);
}
</style>
