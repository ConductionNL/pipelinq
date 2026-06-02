<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<template>
	<div class="rapportage-dashboard">
		<div class="rapportage-dashboard__header">
			<h2>{{ t('pipelinq', 'Analytics') }}</h2>
			<div class="rapportage-dashboard__actions">
				<NcButton type="secondary" :disabled="loading" @click="fetchData">
					{{ t('pipelinq', 'Refresh') }}
				</NcButton>
				<span class="last-updated">
					{{ t('pipelinq', 'Last updated') }}: {{ lastUpdated }}
				</span>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<div v-else-if="error" class="rapportage-dashboard__error">
			{{ error }}
		</div>

		<template v-else>
			<!-- Win/Loss KPI cards -->
			<div class="kpi-grid">
				<div class="kpi-card">
					<div class="kpi-card__value">
						{{ winLoss.winRate }}%
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Win rate') }}
					</div>
				</div>
				<div class="kpi-card">
					<div class="kpi-card__value">
						{{ winLoss.wonCount }}
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Won') }}
					</div>
				</div>
				<div class="kpi-card">
					<div class="kpi-card__value">
						{{ winLoss.lostCount }}
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Lost') }}
					</div>
				</div>
				<div class="kpi-card">
					<div class="kpi-card__value">
						{{ formatCurrency(winLoss.avgWonValue) }}
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Avg won deal value') }}
					</div>
				</div>
				<div class="kpi-card">
					<div class="kpi-card__value">
						{{ winLoss.avgDaysToClose != null ? winLoss.avgDaysToClose + 'd' : '—' }}
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Avg days to close') }}
					</div>
				</div>
			</div>

			<!-- Pipeline value per stage -->
			<div class="report-section">
				<h3>{{ t('pipelinq', 'Pipeline value per stage') }}</h3>
				<div v-if="stageValues.length === 0" class="empty-message">
					{{ t('pipelinq', 'No open leads') }}
				</div>
				<div v-else class="bar-list">
					<div
						v-for="row in stageValues"
						:key="row.stage"
						class="bar-row">
						<div class="bar-row__label">
							{{ row.stage }} ({{ row.count }})
						</div>
						<div class="bar-row__track">
							<div
								class="bar-row__fill bar-row__fill--total"
								:style="{ width: barWidth(row.totalValue, maxStageValue) }" />
							<div
								class="bar-row__fill bar-row__fill--weighted"
								:style="{ width: barWidth(row.weightedValue, maxStageValue) }" />
						</div>
						<div class="bar-row__value">
							{{ formatCurrency(row.totalValue) }}
							<span class="bar-row__weighted">{{ formatCurrency(row.weightedValue) }}</span>
						</div>
					</div>
					<div class="bar-legend">
						<span class="legend-swatch legend-swatch--total" /> {{ t('pipelinq', 'Total value') }}
						<span class="legend-swatch legend-swatch--weighted" /> {{ t('pipelinq', 'Weighted value') }}
					</div>
				</div>
			</div>

			<!-- Source performance -->
			<div class="report-section">
				<h3>{{ t('pipelinq', 'Source performance') }}</h3>
				<div v-if="sourcePerformance.length === 0" class="empty-message">
					{{ t('pipelinq', 'No lead source data') }}
				</div>
				<table v-else class="report-table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Source') }}</th>
							<th>{{ t('pipelinq', 'Total leads') }}</th>
							<th>{{ t('pipelinq', 'Won') }}</th>
							<th>{{ t('pipelinq', 'Conversion rate') }}</th>
							<th>{{ t('pipelinq', 'Avg deal value') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in sourcePerformance" :key="row.source">
							<td>{{ row.source }}</td>
							<td>{{ row.total }}</td>
							<td>{{ row.won }}</td>
							<td>{{ row.conversionRate }}%</td>
							<td>{{ row.avgWonValue != null ? formatCurrency(row.avgWonValue) : '—' }}</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Lead aging distribution -->
			<div class="report-section">
				<h3>{{ t('pipelinq', 'Lead aging') }}</h3>
				<div v-if="agingTotal === 0" class="empty-message">
					{{ t('pipelinq', 'No open leads') }}
				</div>
				<div v-else class="bar-list">
					<div
						v-for="bucket in agingBuckets"
						:key="bucket.bucket"
						class="bar-row">
						<div class="bar-row__label">
							{{ bucket.bucket }} ({{ bucket.count }})
						</div>
						<div class="bar-row__track">
							<div
								class="bar-row__fill bar-row__fill--total"
								:style="{ width: barWidth(bucket.count, maxAgingCount) }" />
						</div>
						<div class="bar-row__value">
							{{ formatCurrency(bucket.totalValue) }}
						</div>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatNumber } from '../../services/localeUtils.js'

export default {
	name: 'RapportageDashboard',
	components: { NcButton, NcLoadingIcon },
	data() {
		return {
			loading: false,
			error: null,
			lastUpdated: new Date().toLocaleTimeString('nl-NL'),
			stageValues: [],
			sourcePerformance: [],
			agingBuckets: [],
			winLoss: {
				wonCount: 0,
				lostCount: 0,
				winRate: 0,
				avgWonValue: null,
				avgLostValue: null,
				avgDaysToClose: null,
			},
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.1
		 */
		maxStageValue() {
			return this.stageValues.reduce((max, r) => Math.max(max, r.totalValue || 0), 0)
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.4
		 */
		maxAgingCount() {
			return this.agingBuckets.reduce((max, b) => Math.max(max, b.count || 0), 0)
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.4
		 */
		agingTotal() {
			return this.agingBuckets.reduce((sum, b) => sum + (b.count || 0), 0)
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		/**
		 * Fetch the server-side aggregated pipeline analytics.
		 *
		 * @spec openspec/changes/lead-management/tasks.md#7.1
		 * @return {Promise<void>}
		 */
		async fetchData() {
			this.loading = true
			this.error = null
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/rapportage/pipeline-stats'))
				this.stageValues = data.stageValues || []
				this.sourcePerformance = data.sourcePerformance || []
				this.agingBuckets = data.agingBuckets || []
				this.winLoss = { ...this.winLoss, ...(data.winLoss || {}) }
				this.lastUpdated = new Date().toLocaleTimeString('nl-NL')
			} catch (e) {
				this.error = this.t('pipelinq', 'Could not load analytics. Please try again.')
				console.error('Pipelinq: failed to load pipeline analytics', e)
			} finally {
				this.loading = false
			}
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.2
		 * @param {number} value The numerator value.
		 * @param {number} max The maximum value across the series.
		 * @return {string} A CSS width percentage.
		 */
		barWidth(value, max) {
			if (!max || max <= 0) return '0%'
			const pct = Math.round((Math.max(value, 0) / max) * 100)
			return `${pct}%`
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.5
		 * @param {number|null} value The amount in EUR.
		 * @return {string} A formatted currency string.
		 */
		formatCurrency(value) {
			if (value == null) return '—'
			return '€ ' + formatNumber(value)
		},
	},
}
</script>

<style scoped>
.rapportage-dashboard { padding: 20px; max-width: 1200px; margin: 0 auto; }

.rapportage-dashboard__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }

.rapportage-dashboard__actions { display: flex; gap: 12px; align-items: center; }

.rapportage-dashboard__error { padding: 16px; border-radius: var(--border-radius-large); background: var(--color-error); color: var(--color-primary-element-text); }

.last-updated { font-size: 0.85em; color: var(--color-text-lighter); }

.kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }

.kpi-card { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 20px; text-align: center; }

.kpi-card__value { font-size: 1.8em; font-weight: 700; }

.kpi-card__label { font-size: 0.85em; color: var(--color-text-lighter); margin-top: 4px; }

.report-section { margin-bottom: 28px; }

.report-section h3 { margin-bottom: 12px; }

.bar-list { display: flex; flex-direction: column; gap: 8px; }

.bar-row { display: flex; align-items: center; gap: 12px; }

.bar-row__label { width: 180px; font-weight: 600; font-size: 0.9em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.bar-row__track { flex: 1; display: flex; flex-direction: column; gap: 2px; }

.bar-row__fill { height: 10px; border-radius: 5px; min-width: 2px; transition: width 0.3s; }

.bar-row__fill--total { background: var(--color-primary-element); }

.bar-row__fill--weighted { background: var(--color-success); }

.bar-row__value { width: 200px; text-align: right; font-size: 0.85em; color: var(--color-text-lighter); }

.bar-row__weighted { color: var(--color-success); margin-left: 6px; }

.bar-legend { display: flex; align-items: center; gap: 6px; font-size: 0.8em; color: var(--color-text-lighter); margin-top: 6px; }

.legend-swatch { display: inline-block; width: 12px; height: 12px; border-radius: 3px; }

.legend-swatch--total { background: var(--color-primary-element); }

.legend-swatch--weighted { background: var(--color-success); margin-left: 10px; }

.report-table { width: 100%; border-collapse: collapse; }

.report-table th, .report-table td { text-align: left; padding: 8px 12px; border-bottom: 1px solid var(--color-border); font-size: 0.9em; }

.report-table th { color: var(--color-text-lighter); font-weight: 600; }

.empty-message { padding: 20px; text-align: center; color: var(--color-text-lighter); }
</style>
