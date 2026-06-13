<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Weighted Forecast')"
		:count="overview.weightedForecast || 0"
		:count-label="trendLabel('weightedForecast')"
		:icon="ChartBellCurveCumulative"
		variant="primary"
		horizontal>
		<template #value>
			{{ formatEur(overview.weightedForecast) }}
		</template>
	</CnStatsBlock>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import ChartBellCurveCumulative from 'vue-material-design-icons/ChartBellCurveCumulative.vue'
import commercialKpiMixin from './commercialKpiMixin.js'

/**
 * KPI card: open pipeline value weighted by win probability.
 *
 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
 */
export default {
	name: 'WeightedForecastKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [commercialKpiMixin],
	data() {
		return {
			ChartBellCurveCumulative,
		}
	},
}
</script>
