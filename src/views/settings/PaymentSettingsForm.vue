<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PaymentSettingsForm — admin page used to configure the POS payment
  - provider adapters (Mollie, CCV, Adyen, Stripe). Reads and writes
  - /api/payment-providers/{name}. Credentials are encrypted server-side
  - via ICrypto and never returned in API responses; this view shows
  - ***SET*** for fields that already have a stored value.
  -
  - @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
  -->
<template>
	<div class="payment-settings">
		<!-- Named "payment providers", never "betaalmethoden": a provider is WHO
		     processes the money (Mollie, CCV, Adyen, Stripe). A tender type is HOW
		     the customer pays at the till (cash, pin, voucher) and lives in its own
		     section below. The old nav had both as "Betalingsmethoden" and "POS
		     betaalmethoden", which read as the same thing twice. -->
		<NcSettingsSection
			:name="t('pipelinq', 'Payment providers (PSP)')"
			:description="t('pipelinq', 'Who processes the money: the payment service providers used for in-store and online payments (Mollie, CCV, Adyen, Stripe). Credentials are stored encrypted. Distinct from POS tender types below, which are how a customer can pay at the till.')">
			<div v-if="loading" class="payment-settings__loading">
				<NcLoadingIcon :size="24" />
				<span>{{ t('pipelinq', 'Loading providers…') }}</span>
			</div>
			<div v-else class="payment-settings__cards">
				<div v-for="provider in providers" :key="provider.name" class="payment-settings__card">
					<header class="payment-settings__card-header">
						<h3>{{ provider.displayName }}</h3>
						<span class="payment-settings__type">{{ providerTypeLabel(provider.type) }}</span>
					</header>

					<NcCheckboxRadioSwitch
						v-model="provider.isActive"
						type="switch">
						{{ t('pipelinq', 'Enable this provider') }}
					</NcCheckboxRadioSwitch>

					<NcSelect
						v-model="provider.environment"
						:options="environmentOptions"
						:input-label="t('pipelinq', 'Environment')"
						label="label"
						:reduce="(o) => o.value" />

					<NcTextField
						:value="provider.apiKey === MASK ? '' : provider.apiKey"
						:label="apiKeyLabel(provider.name)"
						:placeholder="provider.apiKey === MASK ? t('pipelinq', '(opgeslagen — laat leeg om te behouden)') : ''"
						type="password"
						@update:value="(v) => onSecretChange(provider, 'apiKey', v)" />

					<NcTextField
						v-if="hasApiSecret(provider.name)"
						:value="provider.apiSecret === MASK ? '' : provider.apiSecret"
						:label="t('pipelinq', 'API secret')"
						:placeholder="provider.apiSecret === MASK ? t('pipelinq', '(opgeslagen — laat leeg om te behouden)') : ''"
						type="password"
						@update:value="(v) => onSecretChange(provider, 'apiSecret', v)" />

					<NcTextField
						:value="provider.webhookSecret === MASK ? '' : provider.webhookSecret"
						:label="t('pipelinq', 'Webhook secret')"
						:placeholder="provider.webhookSecret === MASK ? t('pipelinq', '(opgeslagen — laat leeg om te behouden)') : ''"
						type="password"
						@update:value="(v) => onSecretChange(provider, 'webhookSecret', v)" />

					<NcTextField
						v-if="provider.name === 'ccv'"
						v-model="provider.config.terminalId"
						:label="t('pipelinq', 'Terminal ID')"
						:placeholder="t('pipelinq', 'kassa-01')" />

					<NcTextField
						v-if="provider.name === 'adyen'"
						v-model="provider.config.merchantAccount"
						:label="t('pipelinq', 'Merchant account')"
						:placeholder="t('pipelinq', 'PipelinqPOS')" />

					<NcCheckboxRadioSwitch
						v-model="provider.testMode"
						type="switch">
						{{ t('pipelinq', 'Test mode (do not charge live)') }}
					</NcCheckboxRadioSwitch>

					<div class="payment-settings__actions">
						<NcButton
							type="secondary"
							:disabled="testingProvider === provider.name"
							@click="onTest(provider)">
							{{ testingProvider === provider.name ? t('pipelinq', 'Testen…') : t('pipelinq', 'Verbinding testen') }}
						</NcButton>
						<NcButton
							type="primary"
							:disabled="savingProvider === provider.name"
							@click="onSave(provider)">
							{{ savingProvider === provider.name ? t('pipelinq', 'Opslaan…') : t('pipelinq', 'Opslaan') }}
						</NcButton>
					</div>

					<p v-if="provider.testResult && provider.testResult.status"
						class="payment-settings__test-result"
						:class="{ 'payment-settings__test-result--ok': provider.testResult.status === 'ok', 'payment-settings__test-result--error': provider.testResult.status === 'error' }">
						{{ provider.testResult.message }}
						<span v-if="provider.lastTestedAt" class="payment-settings__timestamp">
							{{ t('pipelinq', 'Laatst getest op {time}', { time: provider.lastTestedAt }) }}
						</span>
					</p>
				</div>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	listProviders,
	updateProvider,
	testConnection,
} from '../../services/posPaymentApi.js'

const MASK = '***SET***'

export default {
	name: 'PaymentSettingsForm',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		NcSettingsSection,
		NcTextField,
	},
	data() {
		return {
			providers: [],
			loading: true,
			savingProvider: null,
			testingProvider: null,
			MASK,
		}
	},
	computed: {
		environmentOptions() {
			return [
				{ value: 'sandbox', label: t('pipelinq', 'Sandbox') },
				{ value: 'live', label: t('pipelinq', 'Productie') },
			]
		},
	},
	async mounted() {
		await this.refresh()
	},
	methods: {
		async refresh() {
			this.loading = true
			try {
				const providers = await listProviders()
				this.providers = providers.map((p) => this.normalizeProvider(p))
			} catch (e) {
				showError(t('pipelinq', 'Kon providers niet laden: {error}', { error: e.message || 'netwerkfout' }))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Normalize a provider from the API so every field bound to an
		 * NcTextField is always a string. The secrets come back as the MASK
		 * sentinel, undefined or null; the per-provider config keys (ccv
		 * terminalId, adyen merchantAccount) must be pre-declared so their
		 * v-model is reactive in Vue 2 and NcTextField never gets `undefined`
		 * (which triggers its "Missing required prop: value" warning).
		 *
		 * @param {object} p Raw provider from the API.
		 * @return {object} Provider with string-safe, reactive fields.
		 */
		normalizeProvider(p) {
			const config = { ...(p.config || {}) }
			if (p.name === 'ccv' && config.terminalId == null) config.terminalId = ''
			if (p.name === 'adyen' && config.merchantAccount == null) config.merchantAccount = ''
			return {
				...p,
				apiKey: p.apiKey ?? '',
				apiSecret: p.apiSecret ?? '',
				webhookSecret: p.webhookSecret ?? '',
				config,
			}
		},
		hasApiSecret(name) {
			return name === 'ccv' || name === 'stripe'
		},
		apiKeyLabel(name) {
			if (name === 'stripe') {
				return t('pipelinq', 'Stripe publishable key')
			}
			return t('pipelinq', 'API key')
		},
		providerTypeLabel(type) {
			return type === 'terminal' ? t('pipelinq', 'PIN-terminal') : t('pipelinq', 'Online')
		},
		onSecretChange(provider, field, value) {
			// Replacing the MASK with a typed value flags it as a real edit;
			// leaving it as MASK keeps the stored value (server ignores both
			// MASK and empty strings on update).
			provider[field] = value
		},
		async onSave(provider) {
			this.savingProvider = provider.name
			try {
				const payload = {
					isActive: provider.isActive,
					environment: provider.environment,
					testMode: provider.testMode,
					config: provider.config || {},
				}
				// Only ship secret fields when the admin actually typed something.
				for (const field of ['apiKey', 'apiSecret', 'webhookSecret']) {
					if (provider[field] && provider[field] !== MASK) {
						payload[field] = provider[field]
					}
				}
				const saved = await updateProvider(provider.name, payload)
				if (saved) {
					Object.assign(provider, this.normalizeProvider(saved))
				}
				showSuccess(t('pipelinq', 'Provider {name} opgeslagen', { name: provider.displayName }))
			} catch (e) {
				showError(t('pipelinq', 'Opslaan mislukt: {error}', { error: e.message || 'onbekend' }))
			} finally {
				this.savingProvider = null
			}
		},
		async onTest(provider) {
			this.testingProvider = provider.name
			try {
				const result = await testConnection(provider.name)
				provider.testResult = result
				provider.lastTestedAt = new Date().toISOString()
				if (result.status === 'ok') {
					showSuccess(t('pipelinq', 'Verbinding met {name} succesvol', { name: provider.displayName }))
				} else {
					showError(t('pipelinq', 'Test mislukt: {message}', { message: result.message }))
				}
			} catch (e) {
				provider.testResult = { status: 'error', message: e.message || 'unknown' }
				showError(t('pipelinq', 'Test mislukt: {error}', { error: e.message || 'netwerkfout' }))
			} finally {
				this.testingProvider = null
			}
		},
	},
}
</script>

<style scoped>
.payment-settings {
	max-width: 1080px;
}
.payment-settings__loading {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 24px;
}
.payment-settings__cards {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
	gap: 16px;
}
.payment-settings__card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	background-color: var(--color-main-background);
}
.payment-settings__card-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}
.payment-settings__card-header h3 {
	margin: 0;
	font-size: 1.1em;
}
.payment-settings__type {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	background-color: var(--color-background-hover);
	padding: 2px 8px;
	border-radius: var(--border-radius);
}
.payment-settings__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}
.payment-settings__test-result {
	font-size: 0.9em;
	margin: 0;
	padding: 8px;
	border-radius: var(--border-radius);
}
.payment-settings__test-result--ok {
	background-color: var(--color-success);
	color: var(--color-main-background);
}
.payment-settings__test-result--error {
	background-color: var(--color-error);
	color: var(--color-main-background);
}
.payment-settings__timestamp {
	display: block;
	font-size: 0.8em;
	margin-top: 4px;
	opacity: 0.85;
}
</style>
