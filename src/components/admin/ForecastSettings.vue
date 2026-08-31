<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Forecast configuration admin section.
  -
  - Reads and persists the forecast feature configuration (commit threshold,
  - generation schedule, accuracy bands, at-risk thresholds, reporting currency
  - and the manager/team groups) via the admin-only /api/settings/forecast
  - endpoint. All forecast math stays server-authoritative (ADR-005); this panel
  - only edits configuration values.
-->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Forecast configuration')"
		:description="
			t(
				'pipelinq',
				'Configure the sales forecast: commit threshold, weekly snapshot schedule, accuracy bands, at-risk warnings and the reporting currency.',
			)
		">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else class="forecast-settings">
			<div class="forecast-row">
				<div class="forecast-field">
					<label for="forecast-commit-threshold">{{
						t('pipelinq', 'Commit threshold (in reporting currency)')
					}}</label>
					<input
						id="forecast-commit-threshold"
						v-model.number="form.commit_threshold"
						type="number"
						min="0" />
				</div>
				<div class="forecast-field">
					<label for="forecast-currency">{{
						t('pipelinq', 'Reporting currency')
					}}</label>
					<input
						id="forecast-currency"
						v-model="form.reporting_currency"
						type="text"
						maxlength="3" />
				</div>
			</div>

			<div class="forecast-row">
				<div class="forecast-field">
					<label for="forecast-timezone">{{
						t('pipelinq', 'Forecast generation timezone')
					}}</label>
					<input
						id="forecast-timezone"
						v-model="form.generation_timezone"
						type="text" />
				</div>
				<div class="forecast-field">
					<label for="forecast-day">{{
						t('pipelinq', 'Generation day (1 = Monday)')
					}}</label>
					<input
						id="forecast-day"
						v-model.number="form.generation_day"
						type="number"
						min="1"
						max="7" />
				</div>
				<div class="forecast-field">
					<label for="forecast-hour">{{
						t('pipelinq', 'Generation hour (0-23)')
					}}</label>
					<input
						id="forecast-hour"
						v-model.number="form.generation_hour"
						type="number"
						min="0"
						max="23" />
				</div>
			</div>

			<div class="forecast-row">
				<div class="forecast-field">
					<label for="forecast-green">{{
						t('pipelinq', 'Accuracy green threshold (0-1)')
					}}</label>
					<input
						id="forecast-green"
						v-model="form.accuracy_green"
						type="number"
						min="0"
						max="1"
						step="0.01" />
				</div>
				<div class="forecast-field">
					<label for="forecast-amber">{{
						t('pipelinq', 'Accuracy amber threshold (0-1)')
					}}</label>
					<input
						id="forecast-amber"
						v-model="form.accuracy_amber"
						type="number"
						min="0"
						max="1"
						step="0.01" />
				</div>
			</div>

			<div class="forecast-row">
				<div class="forecast-field">
					<label for="forecast-at-risk-percent">{{
						t('pipelinq', 'At-risk attainment threshold (%)')
					}}</label>
					<input
						id="forecast-at-risk-percent"
						v-model.number="form.at_risk_percent"
						type="number"
						min="0"
						max="100" />
				</div>
				<div class="forecast-field">
					<label for="forecast-at-risk-days">{{
						t('pipelinq', 'At-risk days remaining')
					}}</label>
					<input
						id="forecast-at-risk-days"
						v-model.number="form.at_risk_days"
						type="number"
						min="0" />
				</div>
			</div>

			<div class="forecast-row">
				<div class="forecast-field">
					<label for="forecast-manager-group">{{
						t('pipelinq', 'Forecast manager group')
					}}</label>
					<input
						id="forecast-manager-group"
						v-model="form.manager_group"
						type="text" />
				</div>
				<div class="forecast-field">
					<label for="forecast-team-groups">{{
						t('pipelinq', 'Forecast team groups (comma-separated)')
					}}</label>
					<input
						id="forecast-team-groups"
						v-model="form.team_groups"
						type="text" />
				</div>
			</div>

			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>

			<div class="forecast-actions">
				<NcButton variant="primary" :disabled="saving" @click="save">
					<template #icon>
						<NcLoadingIcon v-if="saving" :size="20" />
					</template>
					{{ saving ? t('pipelinq', 'Saving…') : t('pipelinq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSettingsSection,
} from '@nextcloud/vue'

export default {
	name: 'ForecastSettings',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
	},

	data() {
		return {
			loading: true,
			saving: false,
			message: '',
			messageType: 'success',
			form: {
				commit_threshold: 50000,
				generation_timezone: 'UTC',
				generation_day: 1,
				generation_hour: 6,
				accuracy_green: '0.9',
				accuracy_amber: '0.75',
				at_risk_percent: 90,
				at_risk_days: 30,
				reporting_currency: 'EUR',
				manager_group: '',
				team_groups: '',
			},
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the current forecast configuration.
		 */
		async load() {
			this.loading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/pipelinq/api/settings/forecast'),
				)
				this.form = { ...this.form, ...response.data }
			} catch {
				this.message = t(
					'pipelinq',
					'Could not load the forecast configuration.',
				)
				this.messageType = 'error'
			} finally {
				this.loading = false
			}
		},

		/**
		 * Persist the forecast configuration.
		 */
		async save() {
			this.saving = true
			this.message = ''
			try {
				await axios.put(
					generateUrl('/apps/pipelinq/api/settings/forecast'),
					this.form,
				)
				this.message = t('pipelinq', 'Forecast configuration saved.')
				this.messageType = 'success'
			} catch {
				this.message = t(
					'pipelinq',
					'Could not save the forecast configuration.',
				)
				this.messageType = 'error'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.forecast-settings {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 720px;
}

.forecast-row {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
}

.forecast-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1 1 200px;
}

.forecast-field label {
	font-weight: 600;
	font-size: 13px;
}

.forecast-field input {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.forecast-actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 8px;
}
</style>
