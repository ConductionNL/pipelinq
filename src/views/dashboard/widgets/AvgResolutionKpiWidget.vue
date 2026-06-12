<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Avg Request Resolution')"
		:count="overview.avgRequestResolutionTime || 0"
		:count-label="trendLabel('avgRequestResolutionTime')"
		:icon="ClockOutline"
		horizontal>
		<template #value>
			{{ formatted }}
		</template>
	</CnStatsBlock>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import analyticsKpiMixin from './analyticsKpiMixin.js'

/**
 * KPI card: mean resolution time (hours) for requests resolved in the
 * selected period.
 *
 * @spec openspec/changes/decompose-unified-analytics/specs/dashboard/spec.md#REQ-DASH-010
 */
export default {
	name: 'AvgResolutionKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [analyticsKpiMixin],
	data() {
		return {
			ClockOutline,
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/decompose-unified-analytics/specs/dashboard/spec.md#REQ-DASH-010
		 */
		formatted() {
			const value = this.overview.avgRequestResolutionTime
			if (value === null || value === undefined) {
				return this.t('pipelinq', 'N/A')
			}
			return value.toFixed(1) + 'h'
		},
	},
}
</script>
