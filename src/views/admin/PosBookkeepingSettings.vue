<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->

<!--
  Admin settings panel for POS end-of-day bookkeeping configuration.

  Allows administrators to configure:
  - Daily Z-report generation time (HH:MM)
  - Shillinq API endpoint (URL)
  - Bearer token (password input with show/hide)
  - Alert email for failed submissions
  - GL account mapping per tax rate

  Includes a Test Connection button and form validation.

  @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#4.1
-->
<template>
	<div class="pos-bookkeeping-settings">
		<h2>{{ t('pipelinq', 'Boekhoudkundige Afhandeling - Instellingen') }}</h2>
		<p class="pos-bookkeeping-settings__description">
			{{ t('pipelinq', 'Configureer de dagelijkse Z-rapport generatie en de Shillinq-koppeling voor automatische dagboekposten.') }}
		</p>

		<form class="pos-bookkeeping-settings__form" @submit.prevent="saveSettings">
			<!-- Z-report generation time -->
			<section class="pos-bookkeeping-settings__section">
				<h3>{{ t('pipelinq', 'Z-Rapport Generatie') }}</h3>
				<NcTextField
					v-model="form.zReportTime"
					:label="t('pipelinq', 'Generatietijdstip (UU:MM)')"
					:placeholder="'23:59'"
					:error="!!errors.zReportTime"
					:helper-text="errors.zReportTime || t('pipelinq', 'Tijdstip waarop het dagelijkse Z-rapport automatisch wordt gegenereerd (UTC).')"
					pattern="\d{2}:\d{2}"
					required />
			</section>

			<!-- Shillinq connection -->
			<section class="pos-bookkeeping-settings__section">
				<h3>{{ t('pipelinq', 'Shillinq Koppeling') }}</h3>
				<NcTextField
					v-model="form.shillinqEndpoint"
					:label="t('pipelinq', 'API Endpoint')"
					:placeholder="'https://shillinq.example.org'"
					:error="!!errors.shillinqEndpoint"
					:helper-text="errors.shillinqEndpoint || t('pipelinq', 'Basis-URL van de Shillinq API, bijv. https://shillinq.example.org')"
					type="url" />

				<div class="pos-bookkeeping-settings__token-field">
					<NcTextField
						v-model="form.shillinqToken"
						:label="t('pipelinq', 'Bearer Token')"
						:placeholder="form.shillinqToken === '***' ? t('pipelinq', '(opgeslagen)') : ''"
						:type="showToken ? 'text' : 'password'"
						:helper-text="t('pipelinq', 'Laat leeg om het huidige token te bewaren.')" />
					<NcButton
						type="tertiary"
						class="pos-bookkeeping-settings__token-toggle"
						@click="showToken = !showToken">
						{{ showToken ? t('pipelinq', 'Verbergen') : t('pipelinq', 'Tonen') }}
					</NcButton>
				</div>

				<NcTextField
					v-model="form.alertEmail"
					:label="t('pipelinq', 'E-mail bij mislukte boeking')"
					:placeholder="'boekhouding@example.org'"
					:error="!!errors.alertEmail"
					:helper-text="errors.alertEmail || t('pipelinq', 'E-mailadres voor foutmeldingen bij mislukte Shillinq-indieningen.')"
					type="email" />

				<div class="pos-bookkeeping-settings__test-connection">
					<NcButton
						type="secondary"
						:disabled="!form.shillinqEndpoint || testingConnection"
						@click="testConnection">
						<template #icon>
							<span v-if="testingConnection">...</span>
						</template>
						{{ t('pipelinq', 'Verbinding testen') }}
					</NcButton>
					<span
						v-if="connectionTestResult"
						:class="['pos-bookkeeping-settings__test-result', connectionTestResult.success ? 'success' : 'error']">
						{{
							connectionTestResult.success
								? t('pipelinq', 'Verbinding geslaagd')
								: t('pipelinq', 'Verbinding mislukt: ') + connectionTestResult.error
						}}
					</span>
				</div>
			</section>

			<!-- GL account mapping -->
			<section class="pos-bookkeeping-settings__section">
				<h3>{{ t('pipelinq', 'GB-Rekeningkoppeling') }}</h3>
				<p class="pos-bookkeeping-settings__hint">
					{{ t('pipelinq', 'Koppel btw-tarieven aan de juiste debet- en creditrekeningen in uw grootboek.') }}
				</p>

				<table class="pos-bookkeeping-settings__gl-table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'BTW-tarief (%)') }}</th>
							<th>{{ t('pipelinq', 'Debetrekening') }}</th>
							<th>{{ t('pipelinq', 'Creditrekening') }}</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<tr v-for="(mapping, index) in form.taxRateMappings" :key="index">
							<td>
								<NcTextField
									v-model.number="mapping.taxRate"
									:label="t('pipelinq', 'Tarief')"
									:hide-label="true"
									type="number"
									min="0"
									max="100"
									step="1" />
							</td>
							<td>
								<NcTextField
									v-model="mapping.debitAccount"
									:label="t('pipelinq', 'Debetrekening')"
									:hide-label="true"
									:placeholder="'1200'" />
							</td>
							<td>
								<NcTextField
									v-model="mapping.creditAccount"
									:label="t('pipelinq', 'Creditrekening')"
									:hide-label="true"
									:placeholder="'5000'" />
							</td>
							<td>
								<NcButton
									type="tertiary-no-background"
									:aria-label="t('pipelinq', 'Regel verwijderen')"
									@click="removeTaxRateMapping(index)">
									✕
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>

				<NcButton type="secondary" @click="addTaxRateMapping">
					{{ t('pipelinq', '+ Tariefregel toevoegen') }}
				</NcButton>

				<div class="pos-bookkeeping-settings__bank-account">
					<NcTextField
						v-model="form.bankAccount"
						:label="t('pipelinq', 'Bank/Kas Verrekeningsrekening')"
						:placeholder="'1000'"
						:helper-text="t('pipelinq', 'GB-rekening voor bank- of kasclearings.')" />
				</div>
			</section>

			<!-- Form actions -->
			<div class="pos-bookkeeping-settings__actions">
				<NcButton
					type="primary"
					native-type="submit"
					:disabled="saving">
					{{ saving ? t('pipelinq', 'Opslaan...') : t('pipelinq', 'Instellingen opslaan') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script>
/**
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#4.1
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { NcButton, NcTextField } from '@nextcloud/vue'

export default {
	name: 'PosBookkeepingSettings',

	components: {
		NcButton,
		NcTextField,
	},

	data() {
		return {
			/** @type {boolean} Saving in progress. */
			saving: false,
			/** @type {boolean} Connection test in progress. */
			testingConnection: false,
			/** @type {boolean} Show bearer token. */
			showToken: false,
			/** @type {{success: boolean, error?: string}|null} Connection test result. */
			connectionTestResult: null,
			/** @type {object} Validation errors keyed by field. */
			errors: {},

			/** @type {object} Form data. */
			form: {
				zReportTime: '23:59',
				shillinqEndpoint: '',
				shillinqToken: '',
				alertEmail: '',
				taxRateMappings: [
					{ taxRate: 0, debitAccount: '1200', creditAccount: '5100' },
					{ taxRate: 9, debitAccount: '1200', creditAccount: '5010' },
					{ taxRate: 21, debitAccount: '1200', creditAccount: '5000' },
				],
				bankAccount: '1000',
			},
		}
	},

	async created() {
		await this.loadSettings()
	},

	methods: {
		/**
		 * Load current configuration from the API.
		 */
		async loadSettings() {
			try {
				const response = await axios.get(generateUrl('/apps/pipelinq/api/admin/pos-bookkeeping/config'))
				const data = response.data
				this.form.zReportTime = data.zReportTime ?? '23:59'
				this.form.shillinqEndpoint = data.shillinqEndpoint ?? ''
				this.form.shillinqToken = data.shillinqToken ?? ''
				this.form.alertEmail = data.alertEmail ?? ''
			} catch (e) {
				// Non-fatal — use defaults.
			}
		},

		/**
		 * Validate the form and save settings to the API.
		 */
		async saveSettings() {
			this.errors = {}

			// Validate zReportTime.
			if (!/^\d{2}:\d{2}$/.test(this.form.zReportTime)) {
				this.errors.zReportTime = this.t('pipelinq', 'Voer een geldig tijdstip in (UU:MM)')
				return
			}

			// Validate URL.
			if (this.form.shillinqEndpoint) {
				try {
					new URL(this.form.shillinqEndpoint)
				} catch {
					this.errors.shillinqEndpoint = this.t('pipelinq', 'Voer een geldige URL in')
					return
				}
			}

			// Validate email.
			if (this.form.alertEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.form.alertEmail)) {
				this.errors.alertEmail = this.t('pipelinq', 'Voer een geldig e-mailadres in')
				return
			}

			this.saving = true
			try {
				await axios.post(
					generateUrl('/apps/pipelinq/api/admin/pos-bookkeeping/config'),
					{
						zReportTime: this.form.zReportTime,
						shillinqEndpoint: this.form.shillinqEndpoint,
						shillinqToken: this.form.shillinqToken,
						alertEmail: this.form.alertEmail,
						glAccountMapping: {
							taxRateMappings: this.form.taxRateMappings,
							bankAccount: this.form.bankAccount,
						},
					}
				)
				showSuccess(this.t('pipelinq', 'Instellingen opgeslagen'))
			} catch (e) {
				const msg = e.response?.data?.error ?? this.t('pipelinq', 'Opslaan mislukt')
				showError(msg)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Test the Shillinq connection.
		 */
		async testConnection() {
			this.testingConnection = true
			this.connectionTestResult = null
			try {
				const response = await axios.post(
					generateUrl('/apps/pipelinq/api/admin/pos-bookkeeping/config/test')
				)
				this.connectionTestResult = { success: true }
			} catch (e) {
				const error = e.response?.data?.error ?? this.t('pipelinq', 'Onbekende fout')
				this.connectionTestResult = { success: false, error }
			} finally {
				this.testingConnection = false
			}
		},

		/**
		 * Add a new empty tax rate mapping row.
		 */
		addTaxRateMapping() {
			this.form.taxRateMappings.push({ taxRate: 0, debitAccount: '', creditAccount: '' })
		},

		/**
		 * Remove a tax rate mapping row at the given index.
		 *
		 * @param {number} index The row index.
		 */
		removeTaxRateMapping(index) {
			this.form.taxRateMappings.splice(index, 1)
		},
	},
}
</script>

<style scoped>
.pos-bookkeeping-settings {
	max-width: 800px;
	padding: calc(var(--default-grid-baseline, 4px) * 6);
}

.pos-bookkeeping-settings__description {
	color: var(--color-text-lighter, #888);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
}

.pos-bookkeeping-settings__section {
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 8);
}

.pos-bookkeeping-settings__section h3 {
	font-weight: 600;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 3);
	border-bottom: 1px solid var(--color-border, #eee);
	padding-bottom: calc(var(--default-grid-baseline, 4px) * 2);
}

.pos-bookkeeping-settings__hint {
	color: var(--color-text-lighter, #888);
	font-size: 0.9em;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 2);
}

.pos-bookkeeping-settings__token-field {
	display: flex;
	align-items: flex-end;
	gap: var(--default-grid-baseline, 4px);
}

.pos-bookkeeping-settings__token-field .pos-bookkeeping-settings__token-toggle {
	flex-shrink: 0;
	margin-bottom: 2px;
}

.pos-bookkeeping-settings__test-connection {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
}

.pos-bookkeeping-settings__test-result.success {
	color: var(--color-success, #46ba61);
}

.pos-bookkeeping-settings__test-result.error {
	color: var(--color-error, #e9322d);
}

.pos-bookkeeping-settings__gl-table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 2);
}

.pos-bookkeeping-settings__gl-table th,
.pos-bookkeeping-settings__gl-table td {
	padding: calc(var(--default-grid-baseline, 4px) * 1.5) calc(var(--default-grid-baseline, 4px) * 2);
	text-align: left;
}

.pos-bookkeeping-settings__gl-table th {
	background: var(--color-background-dark, #f5f5f5);
	font-weight: 600;
	font-size: 0.9em;
}

.pos-bookkeeping-settings__bank-account {
	margin-top: calc(var(--default-grid-baseline, 4px) * 4);
	max-width: 300px;
}

.pos-bookkeeping-settings__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	padding-top: calc(var(--default-grid-baseline, 4px) * 4);
	border-top: 1px solid var(--color-border, #eee);
}
</style>
