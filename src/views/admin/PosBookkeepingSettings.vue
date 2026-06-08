<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS bookkeeping admin settings panel — daily Z-report generation time,
  - Shillinq endpoint + bearer token, alert email and max retry attempts.
  - The bearer token is stored isSensitive=true and never returned by the
  - GET endpoint; the form shows a "token configured" indicator instead.
  -
  - @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#4.1
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'POS bookkeeping')"
		:description="t('pipelinq', 'Configure the daily Z-report generation time, the Shillinq endpoint that receives the journal entries, the bearer token, the alert e-mail and the retry policy.')">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else class="pos-bookkeeping-settings">
			<div class="pos-bookkeeping-settings__field">
				<label for="pos-eod-time">{{ t('pipelinq', 'Daily Z-report generation time (HH:MM, UTC)') }}</label>
				<input
					id="pos-eod-time"
					v-model="form.zReportTime"
					type="time"
					data-testid="pos-eod-time">
			</div>

			<div class="pos-bookkeeping-settings__field">
				<label for="pos-eod-endpoint">{{ t('pipelinq', 'Shillinq endpoint (https URL)') }}</label>
				<input
					id="pos-eod-endpoint"
					v-model="form.shillinqEndpoint"
					type="url"
					placeholder="https://shillinq.example.org"
					data-testid="pos-eod-endpoint">
			</div>

			<div class="pos-bookkeeping-settings__field">
				<label for="pos-eod-token">
					{{ t('pipelinq', 'Bearer token') }}
					<span v-if="tokenConfigured" class="pos-bookkeeping-settings__hint">
						{{ t('pipelinq', 'A token is currently configured — leave empty to keep it; type a new value to replace it.') }}
					</span>
				</label>
				<input
					id="pos-eod-token"
					v-model="form.shillinqToken"
					:type="showToken ? 'text' : 'password'"
					autocomplete="off"
					data-testid="pos-eod-token">
				<NcButton
					type="tertiary"
					data-testid="pos-eod-token-toggle"
					@click="showToken = !showToken">
					{{ showToken ? t('pipelinq', 'Hide') : t('pipelinq', 'Show') }}
				</NcButton>
			</div>

			<div class="pos-bookkeeping-settings__field">
				<label for="pos-eod-email">{{ t('pipelinq', 'Alert e-mail for failed submissions') }}</label>
				<input
					id="pos-eod-email"
					v-model="form.alertEmail"
					type="email"
					placeholder="accounting@example.org"
					data-testid="pos-eod-email">
			</div>

			<div class="pos-bookkeeping-settings__field">
				<label for="pos-eod-max">{{ t('pipelinq', 'Max retry attempts (1 - 10)') }}</label>
				<input
					id="pos-eod-max"
					v-model.number="form.maxRetryAttempts"
					type="number"
					min="1"
					max="10"
					data-testid="pos-eod-max">
			</div>

			<div class="pos-bookkeeping-settings__actions">
				<NcButton
					type="primary"
					:disabled="saving"
					data-testid="pos-eod-save"
					@click="save">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
				<p
					v-if="statusMessage"
					class="pos-bookkeeping-settings__status"
					:class="{ 'pos-bookkeeping-settings__status--error': statusError }"
					role="status">
					{{ statusMessage }}
				</p>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSettingsSection } from '@nextcloud/vue'
import { getBookkeepingConfig, updateBookkeepingConfig } from '../../services/posBookkeepingApi.js'

export default {
	name: 'PosBookkeepingSettings',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSettingsSection,
	},
	data() {
		return {
			form: {
				zReportTime: '23:59',
				shillinqEndpoint: '',
				shillinqToken: '',
				alertEmail: '',
				maxRetryAttempts: 5,
			},
			showToken: false,
			tokenConfigured: false,
			loading: false,
			saving: false,
			statusMessage: '',
			statusError: false,
		}
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/**
		 * Load the persisted settings.
		 */
		async load() {
			this.loading = true
			try {
				const settings = await getBookkeepingConfig()
				this.form = {
					zReportTime: settings.zReportTime || '23:59',
					shillinqEndpoint: settings.shillinqEndpoint || '',
					shillinqToken: '',
					alertEmail: settings.alertEmail || '',
					maxRetryAttempts: Number.isFinite(Number(settings.maxRetryAttempts))
						? Number(settings.maxRetryAttempts)
						: 5,
				}
				this.tokenConfigured = settings.tokenConfigured === true
			} catch {
				this.statusMessage = t('pipelinq', 'Could not load bookkeeping settings.')
				this.statusError = true
			} finally {
				this.loading = false
			}
		},
		/**
		 * Persist the form.
		 */
		async save() {
			this.saving = true
			this.statusMessage = ''
			this.statusError = false
			try {
				const payload = {
					zReportTime: this.form.zReportTime,
					shillinqEndpoint: this.form.shillinqEndpoint,
					alertEmail: this.form.alertEmail,
					maxRetryAttempts: this.form.maxRetryAttempts,
				}
				// Only send the token when the operator typed a new value, so
				// leaving the field empty preserves the previously stored one.
				if (this.form.shillinqToken && this.form.shillinqToken.length > 0) {
					payload.shillinqToken = this.form.shillinqToken
				}

				const settings = await updateBookkeepingConfig(payload)
				this.tokenConfigured = settings.tokenConfigured === true
				this.form.shillinqToken = ''
				this.statusMessage = t('pipelinq', 'Bookkeeping settings saved.')
			} catch (err) {
				this.statusMessage = err?.response?.data?.error || t('pipelinq', 'Could not save bookkeeping settings.')
				this.statusError = true
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.pos-bookkeeping-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 600px;
}

.pos-bookkeeping-settings__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.pos-bookkeeping-settings__field input {
	padding: 6px 8px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.pos-bookkeeping-settings__hint {
	display: block;
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.pos-bookkeeping-settings__actions {
	display: flex;
	align-items: center;
	gap: 12px;
}

.pos-bookkeeping-settings__status {
	margin: 0;
	color: var(--color-success);
}

.pos-bookkeeping-settings__status--error {
	color: var(--color-error);
}
</style>
