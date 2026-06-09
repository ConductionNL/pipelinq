<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Unified Analytics dashboard widget (openspec/changes/dashboard,
  REQ-DASH-010 / REQ-DASH-011).

  Renders 4 cross-module KPIs (lead conversion rate, average request
  resolution time, contactmoment volume, customer satisfaction score)
  plus a leads-over-time line chart and a requests-by-category bar
  chart. Period selector lives in the widget header (NcSelect) so the
  user can switch between week/month/quarter/year.

  All 3 endpoints are fetched in parallel via Promise.all.
-->
<template>
	<div class="unified-analytics">
		<div class="unified-analytics__header">
			<h3 class="unified-analytics__title">
				{{ t('pipelinq', 'Unified Analytics') }}
			</h3>
			<NcSelect
				v-model="selectedPeriod"
				:input-label="t('pipelinq', 'Period')"
				:options="periodOptions"
				label="label"
				track-by="value"
				:reduce="opt => opt.value"
				:clearable="false"
				class="unified-analytics__period"
				@input="onPeriodChange" />
		</div>

		<div v-if="error" class="unified-analytics__error">
			{{ error }}
		</div>

		<CnKpiGrid class="unified-analytics__kpis">
			<CnStatsBlock
				:title="t('pipelinq', 'Lead Conversion Rate')"
				:count="formatPercent(overview.leadConversionRate)"
				:count-label="trendLabel('leadConversionRate')"
				:icon="ChartLine"
				variant="primary"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Avg Request Resolution')"
				:count="formatHours(overview.avgRequestResolutionTime)"
				:count-label="trendLabel('avgRequestResolutionTime')"
				:icon="ClockOutline"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Contact Moment Volume')"
				:count="overview.contactMomentVolume || 0"
				:count-label="trendLabel('contactMomentVolume')"
				:icon="MessageText"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Customer Satisfaction')"
				:count="formatScore(overview.customerSatisfactionScore)"
				:count-label="trendLabel('customerSatisfactionScore')"
				:icon="StarOutline"
				horizontal />
		</CnKpiGrid>

		<div class="unified-analytics__charts">
			<CnChartWidget
				type="line"
				:title="t('pipelinq', 'Leads over time')"
				:labels="leadTrendLabels"
				:series="leadTrendSeries"
				class="unified-analytics__chart" />
			<CnChartWidget
				type="bar"
				:title="t('pipelinq', 'Requests by category')"
				:labels="requestsByCategoryLabels"
				:series="requestsByCategorySeries"
				class="unified-analytics__chart" />
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcSelect } from '@nextcloud/vue'
import {
	CnChartWidget,
	CnKpiGrid,
	CnStatsBlock,
} from '@conduction/nextcloud-vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import MessageText from 'vue-material-design-icons/MessageText.vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'

/**
 * UnifiedAnalyticsWidget — cross-module KPI + trend dashboard widget.
 *
 * @spec openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-010
 * @spec openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-011
 */
export default {
	name: 'UnifiedAnalyticsWidget',
	components: {
		NcSelect,
		CnChartWidget,
		CnKpiGrid,
		CnStatsBlock,
	},
	data() {
		return {
			ChartLine,
			ClockOutline,
			MessageText,
			StarOutline,
			selectedPeriod: 'month',
			loading: false,
			error: null,
			overview: {
				leadConversionRate: null,
				avgRequestResolutionTime: null,
				contactMomentVolume: 0,
				customerSatisfactionScore: null,
				previousPeriod: {},
			},
			leadTrend: { series: [] },
			requestsByCategory: { series: [] },
		}
	},
	computed: {
		periodOptions() {
			return [
				{ value: 'week', label: this.t('pipelinq', 'This week') },
				{ value: 'month', label: this.t('pipelinq', 'This month') },
				{ value: 'quarter', label: this.t('pipelinq', 'This quarter') },
				{ value: 'year', label: this.t('pipelinq', 'This year') },
			]
		},
		leadTrendLabels() {
			return (this.leadTrend?.series || []).map(pt => pt.date)
		},
		leadTrendSeries() {
			const values = (this.leadTrend?.series || []).map(pt => pt.value)
			return [{ name: this.t('pipelinq', 'Leads'), data: values }]
		},
		requestsByCategoryLabels() {
			return (this.requestsByCategory?.series || []).map(pt => pt.date)
		},
		requestsByCategorySeries() {
			const values = (this.requestsByCategory?.series || []).map(pt => pt.value)
			return [{ name: this.t('pipelinq', 'Requests'), data: values }]
		},
	},
	mounted() {
		this.fetchAll()
	},
	methods: {
		/**
		 * Fetch overview + both trend endpoints in parallel.
		 *
		 * @spec openspec/changes/dashboard/specs/dashboard/spec.md#REQ-DASH-010
		 */
		async fetchAll() {
			this.loading = true
			this.error = null
			try {
				const period = this.selectedPeriod
				const [overview, leads, requests] = await Promise.all([
					axios.get(generateUrl('/apps/pipelinq/api/analytics/overview'), { params: { period } }),
					axios.get(generateUrl('/apps/pipelinq/api/analytics/trends'), { params: { metric: 'leads', period } }),
					axios.get(generateUrl('/apps/pipelinq/api/analytics/trends'), { params: { metric: 'requests-by-category', period } }),
				])
				this.overview = overview.data || {}
				this.leadTrend = leads.data || { series: [] }
				this.requestsByCategory = requests.data || { series: [] }
			} catch (err) {
				console.error('UnifiedAnalyticsWidget fetch error:', err)
				this.error = this.t('pipelinq', 'Could not load analytics data.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Re-fetch all endpoints when the period changes.
		 */
		onPeriodChange() {
			this.fetchAll()
		},
		formatPercent(value) {
			if (value === null || value === undefined) {
				return this.t('pipelinq', 'N/A')
			}
			return value.toFixed(1) + '%'
		},
		formatHours(value) {
			if (value === null || value === undefined) {
				return this.t('pipelinq', 'N/A')
			}
			return value.toFixed(1) + 'h'
		},
		formatScore(value) {
			if (value === null || value === undefined) {
				return this.t('pipelinq', 'N/A')
			}
			return value.toFixed(1)
		},
		/**
		 * Render a small up/down trend indicator versus the previous period.
		 *
		 * @param {string} field - Key in `overview` to compare.
		 * @return {string} Human-readable delta string.
		 */
		trendLabel(field) {
			const current = this.overview?.[field]
			const previous = this.overview?.previousPeriod?.[field]
			if (current === null || current === undefined || previous === null || previous === undefined) {
				return this.t('pipelinq', 'vs previous period')
			}
			if (current === previous) {
				return this.t('pipelinq', 'no change')
			}
			const arrow = current > previous ? '↑' : '↓'
			return arrow + ' ' + this.t('pipelinq', 'vs previous period')
		},
	},
}
</script>

<style scoped>
.unified-analytics {
	display: flex;
	flex-direction: column;
	height: 100%;
	gap: 12px;
	padding: 8px;
	box-sizing: border-box;
	overflow-y: auto;
}

.unified-analytics__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.unified-analytics__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.unified-analytics__period {
	min-width: 180px;
}

.unified-analytics__error {
	padding: 8px 12px;
	background: var(--color-background-hover);
	color: var(--color-error);
	border-radius: 6px;
	font-size: 13px;
}

.unified-analytics__kpis {
	margin: 4px 0;
}

.unified-analytics__charts {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.unified-analytics__chart {
	min-height: 220px;
}

@media (max-width: 900px) {
	.unified-analytics__charts {
		grid-template-columns: 1fr;
	}
}
</style>
