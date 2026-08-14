<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="requests-by-category-widget">
		<div v-if="error" class="requests-by-category-widget__error">
			{{ error }}
		</div>
		<CnChartWidget
			v-else
			type="bar"
			:labels="chartLabels"
			:series="chartSeries"
			class="requests-by-category-widget__chart" />
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { getAnalyticsTrend } from '../../../services/dashboardData.js'
import analyticsPeriodMixin from './analyticsPeriodMixin.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

/**
 * Bar chart: request counts grouped by category for the selected period.
 * Title is rendered by the dashboard widget chrome, not in the body.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
export default {
	name: 'RequestsByCategoryChartWidget',
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
		/**
		 * @spec openspec/specs/dashboard/spec.md
		 */
		chartLabels() {
			return (this.trend?.series || []).map((pt) => pt.date)
		},
		/**
		 * @spec openspec/specs/dashboard/spec.md
		 */
		chartSeries() {
			const values = (this.trend?.series || []).map((pt) => pt.value)
			return [{ name: this.t('pipelinq', 'Requests'), data: values }]
		},
	},
	methods: {
		/**
		 * @spec openspec/specs/dashboard/spec.md
		 */
		async load() {
			this.error = null
			try {
				this.trend = (await getAnalyticsTrend(
					'requests-by-category',
					this.period,
				)) || { series: [] }
			} catch (err) {
				console.error('RequestsByCategoryChartWidget fetch error:', err)
				this.error = this.t('pipelinq', 'Could not load analytics data.')
			}
		},
	},
}
</script>

<style scoped>
.requests-by-category-widget {
	height: 100%;
	padding: 4px;
	box-sizing: border-box;
}

.requests-by-category-widget__chart {
	min-height: 220px;
}

.requests-by-category-widget__error {
	padding: 8px 12px;
	background: var(--color-background-hover);
	color: var(--color-error);
	border-radius: 6px;
	font-size: 13px;
}
</style>
