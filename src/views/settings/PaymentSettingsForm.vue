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
			:description="
				t(
					'pipelinq',
					'Who processes the money: the payment service providers used for in-store and online payments (Mollie, CCV, Adyen, Stripe). Credentials are stored encrypted. Distinct from POS tender types below, which are how a customer can pay at the till.',
				)
			">
			<div v-if="loading" class="payment-settings__loading">
				<NcLoadingIcon :size="24" />
				<span>{{ t('pipelinq', 'Loading providers…') }}</span>
			</div>
			<div v-else class="payment-settings__cards">
				<div
					v-for="provider in providers"
					:key="provider.name"
					class="payment-settings__card">
					<header class="payment-settings__card-header">
						<h3>{{ provider.displayName }}</h3>
						<span class="payment-settings__type">{{
							providerTypeLabel(provider.type)
						}}</span>
					</header>

					<NcCheckboxRadioSwitch v-model="provider.isActive" type="switch">
						{{ t('pipelinq', 'Enable this provider') }}
					</NcCheckboxRadioSwitch>

					<NcSelect
						v-model="provider.environment"
						:options="environmentOptions"
						:inputLabel="t('pipelinq', 'Environment')"
						label="label"
						:reduce="(o) => o.value" />

					<!--
						The API-key field is gone. Pipelinq no longer holds the key that moves
						the money: it picks a credential from the broker, and OpenRegister
						injects the secret server-side on every call.
					-->
					<NcSelect
						v-model="provider.credential"
						:options="credentialsFor(provider.name)"
						:inputLabel="t('pipelinq', 'API credential')"
						:loading="loadingCredentials"
						:placeholder="t('pipelinq', 'Select a credential')"
						label="label"
						@update:modelValue="
							(v) => onCredentialChange(provider, v)
						" />

					<p class="payment-settings__hint">
						<template
							v-if="
								!loadingCredentials
								&& !credentialsFor(provider.name).length
							">
							{{
								t(
									'pipelinq',
									'No {provider} credential yet. Add one under Personal settings → Additional settings, then reopen this page.',
									{ provider: displayName(provider.name) },
								)
							}}
						</template>
						<template v-else>
							{{
								t(
									'pipelinq',
									'The key stays in your credential vault. Pipelinq sends only the request it wants made, and the broker injects the key and refuses anything outside the allowed calls.',
								)
							}}
						</template>
					</p>

					<!--
						The webhook secret STAYS app-held. It verifies an HMAC on an INBOUND
						webhook — a local verify operation, not an outbound request header — so
						a constrained HTTP proxy cannot carry it.
					-->
					<NcTextField
						:modelValue="
							provider.webhookSecret === MASK
								? ''
								: provider.webhookSecret
						"
						:label="t('pipelinq', 'Webhook secret')"
						:placeholder="
							provider.webhookSecret === MASK
								? t('pipelinq', '(saved — leave empty to keep)')
								: ''
						"
						type="password"
						@update:modelValue="
							(v) => onSecretChange(provider, 'webhookSecret', v)
						" />

					<NcTextField
						v-if="provider.name === 'ccv'"
						v-model="provider.config.terminalId"
						:label="t('pipelinq', 'Terminal ID')"
						:placeholder="t('pipelinq', 'register-01')" />

					<NcTextField
						v-if="provider.name === 'adyen'"
						v-model="provider.config.merchantAccount"
						:label="t('pipelinq', 'Merchant account')"
						:placeholder="t('pipelinq', 'PipelinqPOS')" />

					<NcCheckboxRadioSwitch v-model="provider.testMode" type="switch">
						{{ t('pipelinq', 'Test mode (do not charge live)') }}
					</NcCheckboxRadioSwitch>

					<div class="payment-settings__actions">
						<NcButton
							variant="secondary"
							:disabled="testingProvider === provider.name"
							@click="onTest(provider)">
							{{
								testingProvider === provider.name
									? t('pipelinq', 'Testing…')
									: t('pipelinq', 'Test connection')
							}}
						</NcButton>
						<NcButton
							variant="primary"
							:disabled="savingProvider === provider.name"
							@click="onSave(provider)">
							{{
								savingProvider === provider.name
									? t('pipelinq', 'Saving…')
									: t('pipelinq', 'Save')
							}}
						</NcButton>
					</div>

					<p
						v-if="provider.testResult && provider.testResult.status"
						class="payment-settings__test-result"
						:class="{
							'payment-settings__test-result--ok':
								provider.testResult.status === 'ok',
							'payment-settings__test-result--error':
								provider.testResult.status === 'error',
						}">
						{{ provider.testResult.message }}
						<span
							v-if="provider.lastTestedAt"
							class="payment-settings__timestamp">
							{{
								t('pipelinq', 'Last tested at {time}', {
									time: provider.lastTestedAt,
								})
							}}
						</span>
					</p>
				</div>
			</div>
		</NcSettingsSection>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcSelect,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'
import {
	listProviders,
	testConnection,
	updateProvider,
} from '../../services/posPaymentApi.js'

const MASK = '***SET***'

/**
 * Pipelinq provider name → the broker provider identifiers that can serve it.
 *
 * Live and test are SEPARATE broker entries, not a flag: the catalogue host-locks each
 * provider to a base URL, so `checkout-live.adyen.com` and `checkout-test.adyen.com`
 * cannot share one entry (and the credentials differ anyway).
 */
const BROKER_PROVIDERS = {
	mollie: ['mollie'],
	stripe: ['stripe'],
	adyen: ['adyen', 'adyen-test'],
	ccv: ['ccv', 'ccv-sandbox'],
}

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
			// The user's broker credentials. Pipelinq only ever learns their UUIDs —
			// never the keys behind them.
			credentials: [],
			loadingCredentials: false,
			MASK,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		environmentOptions() {
			return [
				{ value: 'sandbox', label: t('pipelinq', 'Sandbox') },
				{ value: 'live', label: t('pipelinq', 'Production') },
			]
		},
	},

	async mounted() {
		// Credentials first: refresh() preselects each provider's saved credential from
		// this list, so it has to be populated before the providers land.
		await this.fetchCredentials()
		await this.refresh()
	},

	methods: {
		/**
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		async refresh() {
			this.loading = true
			try {
				const providers = await listProviders()
				this.providers = providers.map((p) => this.normalizeProvider(p))
				// Reflect the stored credentialId back into the picker.
				for (const provider of this.providers) {
					provider.credential =
						this.credentialsFor(provider.name).find(
							(o) => o.value === provider.credentialId,
						) || null
				}
			} catch (e) {
				showError(
					t('pipelinq', 'Could not load providers: {error}', {
						error: e.message || 'netwerkfout',
					}),
				)
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
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		normalizeProvider(p) {
			const config = { ...(p.config || {}) }
			if (p.name === 'ccv' && config.terminalId == null) config.terminalId = ''
			if (p.name === 'adyen' && config.merchantAccount == null)
				config.merchantAccount = ''
			return {
				...p,
				// `credentialId` is a reference, not a secret, so it comes back unmasked.
				credentialId: p.credentialId ?? '',
				credential: null,
				webhookSecret: p.webhookSecret ?? '',
				config,
			}
		},

		/**
		 * The broker credentials this provider can use.
		 *
		 * A provider name maps to one or more broker provider identifiers: the catalogue
		 * host-locks live and test to separate entries (adyen / adyen-test, ccv /
		 * ccv-sandbox), because a base URL is the host-lock and the two cannot share one.
		 *
		 * @param {string} name The Pipelinq provider name.
		 * @return {Array} NcSelect options.
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		credentialsFor(name) {
			const wanted = BROKER_PROVIDERS[name] || [name]
			return this.credentials
				.filter((c) => wanted.includes(c.provider))
				.map((c) => ({ label: c.name || c.id, value: c.id }))
		},

		displayName(name) {
			const p = this.providers.find((x) => x.name === name)
			return (p && p.displayName) || name
		},

		/**
		 * Load the user's broker credentials.
		 *
		 * The endpoint already scopes to the caller's own credentials and the response
		 * carries no secrets — only names, providers and UUIDs.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		async fetchCredentials() {
			this.loadingCredentials = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/credentials'),
				)
				this.credentials = data.results || []
			} catch (e) {
				this.credentials = []
			} finally {
				this.loadingCredentials = false
			}
		},

		/**
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		onCredentialChange(provider, option) {
			provider.credentialId = option ? option.value : ''
		},

		providerTypeLabel(type) {
			return type === 'terminal'
				? t('pipelinq', 'PIN-terminal')
				: t('pipelinq', 'Online')
		},

		onSecretChange(provider, field, value) {
			// Replacing the MASK with a typed value flags it as a real edit;
			// leaving it as MASK keeps the stored value (server ignores both
			// MASK and empty strings on update).
			provider[field] = value
		},

		/**
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		async onSave(provider) {
			this.savingProvider = provider.name
			try {
				const payload = {
					isActive: provider.isActive,
					environment: provider.environment,
					testMode: provider.testMode,
					config: provider.config || {},
					// A broker credential UUID — a reference, not a secret.
					credentialId: provider.credentialId || '',
				}
				// The webhook secret is the only secret left in this form: it verifies an
				// HMAC on an inbound webhook, which the broker cannot do for us. Ship it
				// only when the admin actually typed something.
				if (provider.webhookSecret && provider.webhookSecret !== MASK) {
					payload.webhookSecret = provider.webhookSecret
				}
				const saved = await updateProvider(provider.name, payload)
				if (saved) {
					Object.assign(provider, this.normalizeProvider(saved))
				}
				showSuccess(
					t('pipelinq', 'Provider {name} saved', {
						name: provider.displayName,
					}),
				)
			} catch (e) {
				showError(
					t('pipelinq', 'Save failed: {error}', {
						error: e.message || 'unknown',
					}),
				)
			} finally {
				this.savingProvider = null
			}
		},

		/**
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		async onTest(provider) {
			this.testingProvider = provider.name
			try {
				const result = await testConnection(provider.name)
				provider.testResult = result
				provider.lastTestedAt = new Date().toISOString()
				if (result.status === 'ok') {
					showSuccess(
						t('pipelinq', 'Connection to {name} successful', {
							name: provider.displayName,
						}),
					)
				} else {
					showError(
						t('pipelinq', 'Test failed: {message}', {
							message: result.message,
						}),
					)
				}
			} catch (e) {
				provider.testResult = {
					status: 'error',
					message: e.message || 'unknown',
				}
				showError(
					t('pipelinq', 'Test failed: {error}', {
						error: e.message || 'netwerkfout',
					}),
				)
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
