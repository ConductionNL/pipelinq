<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2024 Conduction B.V.
-->

<template>
	<div class="payment-settings">
		<h2>{{ t('pipelinq', 'Payment methods') }}</h2>
		<p class="payment-settings__intro">
			{{ t('pipelinq', 'Configure the payment providers used at the point of sale. Secrets are stored encrypted and are never shown again after saving.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcSettingsSection
			v-for="provider in providers"
			v-else
			:key="provider.name"
			:name="provider.displayName"
			:description="providerDescription(provider)">
			<div class="payment-settings__provider">
				<NcCheckboxRadioSwitch
					:checked.sync="provider.isActive"
					type="switch">
					{{ t('pipelinq', 'Enable this provider') }}
				</NcCheckboxRadioSwitch>

				<div class="payment-settings__env">
					<NcCheckboxRadioSwitch
						:checked.sync="provider.environment"
						value="sandbox"
						name="env-{{ provider.name }}"
						type="radio">
						{{ t('pipelinq', 'Sandbox') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked.sync="provider.environment"
						value="live"
						name="env-{{ provider.name }}"
						type="radio">
						{{ t('pipelinq', 'Production') }}
					</NcCheckboxRadioSwitch>
				</div>

				<NcTextField
					:value.sync="provider.apiKey"
					type="password"
					autocomplete="off"
					:label="t('pipelinq', 'API key')"
					:placeholder="secretPlaceholder(provider)" />

				<NcTextField
					v-if="provider.name === 'stripe' || provider.name === 'ccv'"
					:value.sync="provider.apiSecret"
					type="password"
					autocomplete="off"
					:label="t('pipelinq', 'API secret')"
					:placeholder="secretPlaceholder(provider)" />

				<NcTextField
					:value.sync="provider.webhookSecret"
					type="password"
					autocomplete="off"
					:label="t('pipelinq', 'Webhook secret')"
					:placeholder="secretPlaceholder(provider)" />

				<NcTextField
					v-if="provider.name === 'ccv'"
					:value.sync="provider.terminalId"
					:label="t('pipelinq', 'Terminal ID')"
					placeholder="kassa-01" />

				<NcTextField
					v-if="provider.name === 'adyen'"
					:value.sync="provider.merchantAccount"
					:label="t('pipelinq', 'Merchant account')" />

				<p v-if="provider.lastTestedAt" class="payment-settings__tested">
					{{ t('pipelinq', 'Last tested at') }}: {{ formatDate(provider.lastTestedAt) }}
					<span :class="testResultClass(provider)">{{ testResultLabel(provider) }}</span>
				</p>

				<div class="payment-settings__actions">
					<NcButton type="primary" :disabled="busy" @click="save(provider)">
						{{ t('pipelinq', 'Save') }}
					</NcButton>
					<NcButton :disabled="busy" @click="test(provider)">
						{{ t('pipelinq', 'Test connection') }}
					</NcButton>
				</div>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'PaymentSettingsForm',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSettingsSection,
		NcTextField,
	},
	data() {
		return {
			loading: true,
			busy: false,
			providers: [],
		}
	},
	mounted() {
		this.load()
	},
	methods: {
		/**
		 * Load the configured providers (credentials masked) from the API.
		 *
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		async load() {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/pipelinq/api/payment-providers'), {
					headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' },
				})
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					showError(data.error || t('pipelinq', 'Could not load payment providers.'))
					return
				}
				this.providers = (data.providers || []).map((provider) => this.toForm(provider))
			} catch (e) {
				showError(t('pipelinq', 'Could not load payment providers.'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Map a masked provider config to the editable form shape.
		 *
		 * @param {object} provider The masked provider config.
		 * @return {object} The form model.
		 */
		toForm(provider) {
			const config = provider.config || {}
			return {
				name: provider.name,
				displayName: provider.displayName || provider.name,
				type: provider.type || 'online',
				isActive: !!provider.isActive,
				environment: provider.environment || 'sandbox',
				credentialsConfigured: !!provider.credentialsConfigured,
				lastTestedAt: provider.lastTestedAt || null,
				testResult: provider.testResult || null,
				terminalId: config.terminalId || '',
				merchantAccount: config.merchantAccount || '',
				apiKey: '',
				apiSecret: '',
				webhookSecret: '',
			}
		},
		/**
		 * Persist a provider's configuration and credentials.
		 *
		 * @param {object} provider The form model.
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-002
		 */
		async save(provider) {
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/payment-providers/${provider.name}`),
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify(this.toPayload(provider)),
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					showError(data.error || t('pipelinq', 'Saving failed.'))
					return
				}
				showSuccess(t('pipelinq', 'Payment provider saved.'))
				// Clear the secret inputs after a successful save (never re-shown).
				provider.apiKey = ''
				provider.apiSecret = ''
				provider.webhookSecret = ''
				if (data.provider) {
					provider.credentialsConfigured = !!data.provider.credentialsConfigured
				}
			} catch (e) {
				showError(t('pipelinq', 'Saving failed.'))
			} finally {
				this.busy = false
			}
		},
		/**
		 * Build the PUT payload from the form model.
		 *
		 * @param {object} provider The form model.
		 * @return {object} The request body.
		 */
		toPayload(provider) {
			const config = {}
			if (provider.name === 'ccv') {
				config.terminalId = provider.terminalId
			}
			if (provider.name === 'adyen') {
				config.merchantAccount = provider.merchantAccount
			}
			const payload = {
				isActive: provider.isActive,
				environment: provider.environment,
				config,
			}
			if (provider.apiKey) {
				payload.apiKey = provider.apiKey
			}
			if (provider.apiSecret) {
				payload.apiSecret = provider.apiSecret
			}
			if (provider.webhookSecret) {
				payload.webhookSecret = provider.webhookSecret
			}
			return payload
		},
		/**
		 * Test a provider connection without charging.
		 *
		 * @param {object} provider The form model.
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		async test(provider) {
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/payment-providers/${provider.name}/test`),
					{
						method: 'POST',
						headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' },
					},
				)
				const data = await response.json().catch(() => ({}))
				const result = data.result || {}
				provider.testResult = result
				provider.lastTestedAt = new Date().toISOString()
				if (result.status === 'ok') {
					showSuccess(result.message || t('pipelinq', 'Connection successful.'))
				} else {
					showError(result.message || t('pipelinq', 'Connection failed.'))
				}
			} catch (e) {
				showError(t('pipelinq', 'Connection failed.'))
			} finally {
				this.busy = false
			}
		},
		providerDescription(provider) {
			if (provider.type === 'terminal') {
				return t('pipelinq', 'In-store PIN terminal provider.')
			}
			return t('pipelinq', 'Online / hosted payment provider.')
		},
		secretPlaceholder(provider) {
			return provider.credentialsConfigured
				? t('pipelinq', 'Stored — leave empty to keep')
				: t('pipelinq', 'Not configured')
		},
		testResultClass(provider) {
			return provider.testResult && provider.testResult.status === 'ok'
				? 'payment-settings__ok'
				: 'payment-settings__error'
		},
		testResultLabel(provider) {
			if (!provider.testResult) {
				return ''
			}
			return provider.testResult.status === 'ok'
				? t('pipelinq', 'OK')
				: t('pipelinq', 'Error')
		},
		formatDate(value) {
			try {
				return new Date(value).toLocaleString()
			} catch (e) {
				return value
			}
		},
	},
}
</script>

<style scoped>
.payment-settings { padding: 20px; max-width: 760px; }
.payment-settings__intro { color: var(--color-text-lighter); margin-bottom: 16px; }
.payment-settings__provider { display: flex; flex-direction: column; gap: 12px; max-width: 520px; }
.payment-settings__env { display: flex; gap: 16px; }
.payment-settings__actions { display: flex; gap: 8px; margin-top: 8px; }
.payment-settings__tested { font-size: 0.9em; color: var(--color-text-lighter); }
.payment-settings__ok { color: var(--color-success); font-weight: 600; margin-left: 6px; }
.payment-settings__error { color: var(--color-error); font-weight: 600; margin-left: 6px; }
</style>
