<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="commercial-chart-widget">
		<div v-if="error" class="commercial-chart-widget__error">
			{{ error }}
		</div>
		<CnChartWidget
			v-else
			type="line"
			:labels="chartLabels"
			:series="chartSeries"
			:options="chartOptions"
			class="commercial-chart-widget__chart" />
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { getAnalyticsTrend } from '../../../services/dashboardData.js'
import { formatEur, formatEurCompact } from '../../../services/commercialFormat.js'
import analyticsPeriodMixin from './analyticsPeriodMixin.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

/**
 * Line chart: revenue over time (settled POS turnover + won-deal value)
 * for the selected period. Title comes from the widget chrome.
 *
 * @spec openspec/specs/commercial-dashboard/spec.md
 */
export default {
	name: 'RevenueOverTimeChartWidget',
	components: {
		CnChartWidget,
	},
	mixins: [analyticsPeriodMixin, dashboardRefreshMixin],
	data() {
		return {
			error: null,
			trend: { series: [] },
		}
	},
	computed: {
		/** @return {Array<string>} X-axis date labels. */
		chartLabels() {
			return (this.trend?.series || []).map(pt => pt.date)
		},
		/** @return {Array<object>} Single revenue series. */
		chartSeries() {
			const values = (this.trend?.series || []).map(pt => pt.value)
			return [{ name: this.t('pipelinq', 'Revenue'), data: values }]
		},
		/** @return {object} ApexCharts options with euro axis + tooltip. */
		chartOptions() {
			return {
				yaxis: { labels: { formatter: value => formatEurCompact(value) } },
				tooltip: { y: { formatter: value => formatEur(value, 2) } },
			}
		},
	},
	methods: {
		/**
		 * @spec openspec/specs/commercial-dashboard/spec.md
		 */
		async load() {
			this.error = null
			try {
				this.trend = await getAnalyticsTrend('revenue', this.period) || { series: [] }
			} catch (err) {
				console.error('RevenueOverTimeChartWidget fetch error:', err)
				this.error = this.t('pipelinq', 'Could not load analytics data.')
			}
		},
	},
}
</script>

<style scoped>
.commercial-chart-widget {
	height: 100%;
	padding: 4px;
	box-sizing: border-box;
}

.commercial-chart-widget__chart {
	min-height: 220px;
}

.commercial-chart-widget__error {
	padding: 8px 12px;
	background: var(--color-background-hover);
	color: var(--color-error);
	border-radius: 6px;
	font-size: 13px;
}
</style>
