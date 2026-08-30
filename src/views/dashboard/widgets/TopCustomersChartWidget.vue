<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="commercial-chart-widget">
		<div v-if="error" class="commercial-chart-widget__error">
			{{ error }}
		</div>
		<p v-else-if="isEmpty" class="commercial-chart-widget__empty">
			{{ t('pipelinq', 'No customer revenue in this period yet.') }}
		</p>
		<CnChartWidget
			v-else
			type="bar"
			:labels="chartLabels"
			:series="chartSeries"
			:options="chartOptions"
			class="commercial-chart-widget__chart" />
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { formatEur, formatEurCompact } from '../../../services/commercialFormat.js'
import { getAnalyticsTrend } from '../../../services/dashboardData.js'
import analyticsPeriodMixin from './analyticsPeriodMixin.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

/**
 * Horizontal bar: top customers by revenue (won-deal + POS) in the
 * selected period. Title comes from the widget chrome.
 *
 * @spec openspec/specs/commercial-dashboard/spec.md
 */
export default {
	name: 'TopCustomersChartWidget',
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
		/** @return {boolean} Whether there is nothing to plot. */
		isEmpty() {
			return (this.trend?.series || []).length === 0
		},

		/** @return {Array<string>} Customer names. */
		chartLabels() {
			return (this.trend?.series || []).map((pt) => pt.date)
		},

		/** @return {Array<object>} Single revenue series. */
		chartSeries() {
			const values = (this.trend?.series || []).map((pt) => pt.value)
			return [{ name: this.t('pipelinq', 'Revenue'), data: values }]
		},

		/** @return {object} Horizontal bar options with euro axis. */
		chartOptions() {
			return {
				plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
				dataLabels: { enabled: false },
				xaxis: { labels: { formatter: (value) => formatEurCompact(value) } },
				tooltip: { y: { formatter: (value) => formatEur(value, 2) } },
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
				this.trend = (await getAnalyticsTrend(
					'top-customers',
					this.period,
				)) || { series: [] }
			} catch (err) {
				console.error('TopCustomersChartWidget fetch error:', err)
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

.commercial-chart-widget__empty {
	padding: 16px 12px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>
