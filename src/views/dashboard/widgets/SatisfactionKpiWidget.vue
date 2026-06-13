<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Customer Satisfaction')"
		:count="overview.customerSatisfactionScore || 0"
		:count-label="trendLabel('customerSatisfactionScore')"
		:icon="StarOutline"
		horizontal>
		<template #value>
			{{ formatted }}
		</template>
	</CnStatsBlock>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import StarOutline from 'vue-material-design-icons/StarOutline.vue'
import analyticsKpiMixin from './analyticsKpiMixin.js'

/**
 * KPI card: mean survey score (1–5) in the selected period, or N/A.
 *
 * @spec openspec/changes/decompose-unified-analytics/specs/dashboard/spec.md#REQ-DASH-010
 */
export default {
	name: 'SatisfactionKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [analyticsKpiMixin],
	data() {
		return {
			StarOutline,
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/decompose-unified-analytics/specs/dashboard/spec.md#REQ-DASH-010
		 */
		formatted() {
			const value = this.overview.customerSatisfactionScore
			if (value === null || value === undefined) {
				return this.t('pipelinq', 'N/A')
			}
			return value.toFixed(1)
		},
	},
}
</script>
