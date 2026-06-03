<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Unified cross-module analytics panel. Renders KPI cards (CnStatsBlock inside
  - CnKpiGrid) and trend charts (CnChartWidget) from the server-aggregated
  - /api/analytics endpoints. All aggregation happens server-side; this widget
  - only renders the pre-computed numbers.
  -->
<template>
	<div class="analytics-panel">
		<div class="analytics-toolbar">
			<NcSelect
				:value="selectedPeriodOption"
				:options="periodOptions"
				:clearable="false"
				:input-label="t('pipelinq', 'Period')"
				label="label"
				class="analytics-period"
				@input="onPeriodChange" />
		</div>
		<p v-if="error" class="analytics-error">
			{{ error }}
		</p>
		<CnKpiGrid :columns="4">
			<CnStatsBlock
				:title="t('pipelinq', 'Lead conversion rate')"
				:count="conversionDisplay"
				:count-label="conversionTrendLabel"
				:icon="TrendingUp"
				variant="primary"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Avg. request resolution')"
				:count="resolutionDisplay"
				:count-label="t('pipelinq', 'hours')"
				:icon="ClockOutline"
				variant="info"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Contact moments')"
				:count="contactMomentDisplay"
				:count-label="contactMomentTrendLabel"
				:icon="AccountVoice"
				variant="success"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Satisfaction score')"
				:count="satisfactionDisplay"
				:count-label="t('pipelinq', 'out of 5')"
				:icon="EmoticonHappyOutline"
				variant="warning"
				horizontal />
		</CnKpiGrid>
		<div class="analytics-charts">
			<div class="analytics-chart">
				<h3 class="analytics-chart__title">
					{{ t('pipelinq', 'Leads over time') }}
				</h3>
				<CnChartWidget
					type="line"
					:series="leadChartSeries"
					:labels="leadTrend.labels"
					:height="240"
					:unavailable-label="t('pipelinq', 'No lead data for this period')" />
			</div>
			<div class="analytics-chart">
				<h3 class="analytics-chart__title">
					{{ t('pipelinq', 'Requests by category') }}
				</h3>
				<CnChartWidget
					type="bar"
					:series="requestChartSeries"
					:labels="requestsByCategory.labels"
					:height="240"
					:unavailable-label="t('pipelinq', 'No request data for this period')" />
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'
import { CnChartWidget, CnKpiGrid, CnStatsBlock } from '@conduction/nextcloud-vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import AccountVoice from 'vue-material-design-icons/AccountVoice.vue'
import EmoticonHappyOutline from 'vue-material-design-icons/EmoticonHappyOutline.vue'

export default {
	name: 'AnalyticsDashboard',
	components: {
		NcSelect,
		CnChartWidget,
		CnKpiGrid,
		CnStatsBlock,
	},
	data() {
		return {
			TrendingUp,
			ClockOutline,
			AccountVoice,
			EmoticonHappyOutline,
			period: 'month',
			overview: null,
			leadTrend: { labels: [], values: [] },
			requestsByCategory: { labels: [], values: [] },
			loading: false,
			error: '',
		}
	},
	computed: {
		/**
		 * @return {Array} The period dropdown options.
		 */
		periodOptions() {
			return [
				{ id: 'week', label: this.t('pipelinq', 'This week') },
				{ id: 'month', label: this.t('pipelinq', 'This month') },
				{ id: 'quarter', label: this.t('pipelinq', 'This quarter') },
				{ id: 'year', label: this.t('pipelinq', 'This year') },
			]
		},
		/**
		 * @return {object} The currently selected period option.
		 */
		selectedPeriodOption() {
			return this.periodOptions.find((opt) => opt.id === this.period) || this.periodOptions[1]
		},
		/**
		 * @return {string} The conversion rate formatted for display.
		 */
		conversionDisplay() {
			if (!this.overview) {
				return '—'
			}
			return this.overview.leadConversionRate + '%'
		},
		/**
		 * @return {string} The contact moment volume formatted for display.
		 */
		contactMomentDisplay() {
			return this.overview ? String(this.overview.contactMomentVolume) : '—'
		},
		/**
		 * @return {string} The resolution time formatted for display.
		 */
		resolutionDisplay() {
			if (!this.overview || this.overview.avgRequestResolutionTime === null) {
				return this.t('pipelinq', 'N/A')
			}
			return String(this.overview.avgRequestResolutionTime)
		},
		/**
		 * @return {string} The satisfaction score formatted for display.
		 */
		satisfactionDisplay() {
			if (!this.overview || this.overview.customerSatisfactionScore === null) {
				return this.t('pipelinq', 'N/A')
			}
			return String(this.overview.customerSatisfactionScore)
		},
		/**
		 * @return {string} Trend label comparing conversion to previous period.
		 */
		conversionTrendLabel() {
			return this.trendLabel('leadConversionRate')
		},
		/**
		 * @return {string} Trend label comparing contact moments to previous period.
		 */
		contactMomentTrendLabel() {
			return this.trendLabel('contactMomentVolume')
		},
		/**
		 * @return {Array} The lead trend chart series.
		 */
		leadChartSeries() {
			return [{ name: this.t('pipelinq', 'Leads'), data: this.leadTrend.values }]
		},
		/**
		 * @return {Array} The requests-by-category chart series.
		 */
		requestChartSeries() {
			return [{ name: this.t('pipelinq', 'Requests'), data: this.requestsByCategory.values }]
		},
	},
	mounted() {
		this.fetchAll()
	},
	methods: {
		/**
		 * Fetch the overview and both trend charts in parallel.
		 */
		async fetchAll() {
			this.loading = true
			this.error = ''
			try {
				const [overview, leads, requests] = await Promise.all([
					axios.get(generateUrl('/apps/pipelinq/api/analytics/overview'), { params: { period: this.period } }),
					axios.get(generateUrl('/apps/pipelinq/api/analytics/trends'), { params: { metric: 'leads', period: this.period } }),
					axios.get(generateUrl('/apps/pipelinq/api/analytics/trends'), { params: { metric: 'requests-by-category', period: this.period } }),
				])
				this.overview = overview.data
				this.leadTrend = this.toChart(leads.data)
				this.requestsByCategory = this.toChart(requests.data)
			} catch (err) {
				this.error = this.t('pipelinq', 'Could not load analytics. Please try again later.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Re-fetch all data when the period changes.
		 *
		 * @param {object} option - The newly selected period option.
		 */
		onPeriodChange(option) {
			if (!option || option.id === this.period) {
				return
			}
			this.period = option.id
			this.fetchAll()
		},
		/**
		 * Convert a trend API payload into { labels, values } arrays.
		 *
		 * @param {object} payload - The trend API response.
		 * @return {object} The labels/values arrays for charting.
		 */
		toChart(payload) {
			const series = (payload && payload.series) || []
			return {
				labels: series.map((point) => point.date),
				values: series.map((point) => point.value),
			}
		},
		/**
		 * Build a human-readable trend label for a KPI vs. the previous period.
		 *
		 * @param {string} field - The KPI field name.
		 * @return {string} The trend label (e.g. "+12% vs previous").
		 */
		trendLabel(field) {
			if (!this.overview || !this.overview.previousPeriod) {
				return ''
			}
			const current = Number(this.overview[field]) || 0
			const previous = Number(this.overview.previousPeriod[field]) || 0
			if (previous === 0) {
				return ''
			}
			const delta = Math.round(((current - previous) / previous) * 100)
			const arrow = delta >= 0 ? '▲' : '▼'
			return arrow + ' ' + Math.abs(delta) + '% ' + this.t('pipelinq', 'vs previous')
		},
	},
}
</script>

<style scoped>
.analytics-panel {
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 16px;
	height: 100%;
	overflow-y: auto;
}

.analytics-toolbar {
	display: flex;
	justify-content: flex-end;
}

.analytics-period {
	min-width: 180px;
}

.analytics-error {
	color: var(--color-error);
	font-size: 14px;
}

.analytics-charts {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.analytics-chart__title {
	font-size: 14px;
	font-weight: 600;
	margin: 0 0 8px;
}

@media (max-width: 900px) {
	.analytics-charts {
		grid-template-columns: 1fr;
	}
}
</style>
