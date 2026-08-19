<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="commercial-chart-widget">
		<div v-if="error" class="commercial-chart-widget__error">
			{{ error }}
		</div>
		<p v-else-if="isEmpty" class="commercial-chart-widget__empty">
			{{ t('pipelinq', 'No categorised sales in this period yet.') }}
		</p>
		<CnChartWidget
			v-else
			type="donut"
			:labels="chartLabels"
			:series="chartValues"
			:options="chartOptions"
			class="commercial-chart-widget__chart" />
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { getAnalyticsTrend } from '../../../services/dashboardData.js'
import { formatEur } from '../../../services/commercialFormat.js'
import analyticsPeriodMixin from './analyticsPeriodMixin.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

/**
 * Donut: POS revenue by product category for the selected period.
 * Title comes from the widget chrome.
 *
 * @spec openspec/specs/commercial-dashboard/spec.md
 */
export default {
	name: 'RevenueByCategoryChartWidget',
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
		/** @return {Array<string>} Category labels. */
		chartLabels() {
			return (this.trend?.series || []).map(pt => pt.date)
		},
		/** @return {Array<number>} Per-category revenue values (donut series). */
		chartValues() {
			return (this.trend?.series || []).map(pt => pt.value)
		},
		/** @return {object} Donut options with euro tooltip. */
		chartOptions() {
			return {
				legend: { position: 'bottom' },
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
				this.trend = await getAnalyticsTrend('revenue-by-product-category', this.period) || { series: [] }
			} catch (err) {
				console.error('RevenueByCategoryChartWidget fetch error:', err)
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
