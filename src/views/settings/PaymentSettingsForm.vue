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
					'Who processes the money: the payment providers for payments in the shop and online. Keys are stored encrypted.',
				)
			">
			<div v-if="loading" class="payment-settings__loading">
				<NcLoadingIcon :size="24" />
				<span>{{ t('pipelinq', 'Loading providers…') }}</span>
			</div>
			<template v-else>
				<!-- One compact row per provider: who it is, how it takes money,
				     which environment, and whether it is on. The switch saves at
				     once; the provider's settings open below, one at a time. -->
				<ul class="payment-settings__providers">
					<li
						v-for="provider in providers"
						:key="provider.name"
						class="payment-settings__provider"
						:class="{ 'payment-settings__provider--selected': provider.name === selectedName }"
						data-testid="payment-provider-row">
						<button
							type="button"
							class="payment-settings__provider-select"
							data-testid="payment-provider-select"
							:aria-pressed="provider.name === selectedName ? 'true' : 'false'"
							@click="selectedName = provider.name">
							<strong>{{ provider.displayName }}</strong>
							<span class="payment-settings__provider-meta">
								{{ providerTypeLabel(provider.type) }} · {{ environmentLabel(provider.environment) }}
							</span>
						</button>
						<NcCheckboxRadioSwitch
							:modelValue="provider.isActive"
							type="switch"
							:aria-label="t('pipelinq', 'Enable {provider}', { provider: provider.displayName })"
							:disabled="savingProvider === provider.name"
							@update:modelValue="(on) => onToggle(provider, on)">
							<span class="hidden-visually">
								{{ t('pipelinq', 'Enable {provider}', { provider: provider.displayName }) }}
							</span>
						</NcCheckboxRadioSwitch>
					</li>
				</ul>

				<div
					v-if="selected"
					:key="selected.name"
					class="payment-settings__form"
					data-testid="payment-provider-form">
					<h3>{{ selected.displayName }}</h3>

					<div class="payment-settings__fields">
						<NcSelect
							v-model="selected.environment"
							:options="environmentOptions"
							:inputLabel="t('pipelinq', 'Environment')"
							label="label"
							:reduce="(o) => o.value" />

						<!--
							Pipelinq holds no key that moves the money: it picks a
							credential from the broker, and OpenRegister injects the
							secret server-side on every call.
						-->
						<NcSelect
							v-model="selected.credential"
							:options="credentialsFor(selected.name)"
							:inputLabel="t('pipelinq', 'API credential')"
							:loading="loadingCredentials"
							:placeholder="t('pipelinq', 'Select a credential')"
							label="label"
							@update:modelValue="
								(v) => onCredentialChange(selected, v)
							" />

						<NcTextField
							v-if="selected.name === 'ccv'"
							v-model="selected.config.terminalId"
							:label="t('pipelinq', 'Terminal ID')"
							:placeholder="t('pipelinq', 'register-01')" />

						<NcTextField
							v-if="selected.name === 'adyen'"
							v-model="selected.config.merchantAccount"
							:label="t('pipelinq', 'Merchant account')"
							:placeholder="t('pipelinq', 'PipelinqPOS')" />

						<!--
							The webhook secret stays app-held: it verifies an HMAC on an
							inbound webhook, which the broker cannot do for us.
						-->
						<NcTextField
							:modelValue="
								selected.webhookSecret === MASK
									? ''
									: selected.webhookSecret
							"
							:label="t('pipelinq', 'Webhook secret')"
							:placeholder="
								selected.webhookSecret === MASK
									? t('pipelinq', '(saved — leave empty to keep)')
									: ''
							"
							type="password"
							@update:modelValue="
								(v) => onSecretChange(selected, 'webhookSecret', v)
							" />
					</div>

					<NcCheckboxRadioSwitch v-model="selected.testMode">
						{{ t('pipelinq', 'Test mode (do not charge live)') }}
					</NcCheckboxRadioSwitch>

					<p class="payment-settings__hint" role="status">
						<template
							v-if="
								!loadingCredentials
								&& !credentialsFor(selected.name).length
							">
							{{
								t(
									'pipelinq',
									'No {provider} credential yet. Add one under Personal settings → Additional settings, then reopen this page.',
									{ provider: selected.displayName },
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

					<div class="payment-settings__actions">
						<NcButton
							variant="secondary"
							:disabled="testingProvider === selected.name"
							@click="onTest(selected)">
							<template #icon>
								<Refresh :size="20" />
							</template>
							{{
								testingProvider === selected.name
									? t('pipelinq', 'Testing…')
									: t('pipelinq', 'Test connection')
							}}
						</NcButton>
						<NcButton
							variant="primary"
							:disabled="savingProvider === selected.name"
							@click="onSave(selected)">
							{{
								savingProvider === selected.name
									? t('pipelinq', 'Saving…')
									: t('pipelinq', 'Save')
							}}
						</NcButton>
					</div>

					<p
						v-if="selected.testResult && selected.testResult.status"
						class="payment-settings__test-result"
						:class="{
							'payment-settings__test-result--ok':
								selected.testResult.status === 'ok',
							'payment-settings__test-result--error':
								selected.testResult.status === 'error',
						}">
						{{ selected.testResult.message }}
					</p>
					<span v-if="selected.lastTestedAt" class="payment-settings__timestamp">
						{{
							t('pipelinq', 'Last tested at {time}', {
								time: formatMoment(selected.lastTestedAt),
							})
						}}
					</span>
				</div>
			</template>
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
import Refresh from 'vue-material-design-icons/Refresh.vue'
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
		Refresh,
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
			// The provider whose settings show below the list.
			selectedName: '',
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

		/**
		 * The provider whose settings form shows.
		 *
		 * @return {object|null} The provider, or null before any loaded.
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		selected() {
			return this.providers.find((p) => p.name === this.selectedName) || null
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
				if (!this.providers.some((p) => p.name === this.selectedName)) {
					const first = this.providers.find((p) => p.isActive) || this.providers[0]
					this.selectedName = first ? first.name : ''
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
			} catch {
				this.credentials = []
			} finally {
				this.loadingCredentials = false
			}
		},

		/**
		 * @param {object} provider The payment provider being configured.
		 * @param {object} option The credential field that changed.
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		onCredentialChange(provider, option) {
			provider.credentialId = option ? option.value : ''
		},

		/**
		 * @param {string} environment The stored environment.
		 * @return {string} Its label.
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		environmentLabel(environment) {
			const option = this.environmentOptions.find((o) => o.value === environment)
			return option ? option.label : environment || ''
		},

		/**
		 * Switch a provider on or off from its row, and save it at once.
		 *
		 * @param {object} provider The provider.
		 * @param {boolean} on Whether it is on.
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		async onToggle(provider, on) {
			provider.isActive = on === true
			await this.onSave(provider)
		},

		/**
		 * @param {string} iso A moment as ISO 8601.
		 * @return {string} Day, month and time in the reader's language.
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
		 */
		formatMoment(iso) {
			const date = new Date(iso)
			if (Number.isNaN(date.getTime())) {
				return String(iso)
			}
			return date.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
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
		 * @param {object} provider The payment provider to save.
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
		 * @param {object} provider The payment provider to probe.
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

.payment-settings__providers {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
}

.payment-settings__provider {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.payment-settings__provider-select {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	min-width: 0;
	margin: 0;
	padding: 4px 0;
	border: 0;
	background: transparent;
	color: var(--color-main-text);
	font-size: 15px;
	text-align: start;
	cursor: pointer;
}

.payment-settings__provider--selected .payment-settings__provider-select strong {
	color: var(--color-primary-element);
}

.payment-settings__provider-meta {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.payment-settings__form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin-top: 16px;
}

.payment-settings__form h3 {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}

.payment-settings__fields {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 12px 16px;
}

.payment-settings__hint {
	margin: 0;
	padding: 10px 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
	font-size: 14px;
}

.payment-settings__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
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
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
