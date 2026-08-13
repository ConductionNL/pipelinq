<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="leads-over-time-widget">
		<div v-if="error" class="leads-over-time-widget__error">
			{{ error }}
		</div>
		<CnChartWidget
			v-else
			type="line"
			:labels="chartLabels"
			:series="chartSeries"
			class="leads-over-time-widget__chart" />
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { getAnalyticsTrend } from '../../../services/dashboardData.js'
import analyticsPeriodMixin from './analyticsPeriodMixin.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

/**
 * Line chart: lead count over time for the selected period. Title is
 * rendered by the dashboard widget chrome, not in the body.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
export default {
	name: 'LeadsOverTimeChartWidget',
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
			return [{ name: this.t('pipelinq', 'Leads'), data: values }]
		},
	},
	methods: {
		/**
		 * @spec openspec/specs/dashboard/spec.md
		 */
		async load() {
			this.error = null
			try {
				this.trend = (await getAnalyticsTrend('leads', this.period)) || {
					series: [],
				}
			} catch (err) {
				console.error('LeadsOverTimeChartWidget fetch error:', err)
				this.error = this.t('pipelinq', 'Could not load analytics data.')
			}
		},
	},
}
</script>

<style scoped>
.leads-over-time-widget {
	height: 100%;
	padding: 4px;
	box-sizing: border-box;
}

.leads-over-time-widget__chart {
	min-height: 220px;
}

.leads-over-time-widget__error {
	padding: 8px 12px;
	background: var(--color-background-hover);
	color: var(--color-error);
	border-radius: 6px;
	font-size: 13px;
}
</style>
