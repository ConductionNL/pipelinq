<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Lead Conversion Rate')"
		:count="overview.leadConversionRate || 0"
		:count-label="trendLabel('leadConversionRate')"
		:icon="ChartLine"
		variant="primary"
		horizontal>
		<template #value>
			{{ formatted }}
		</template>
	</CnStatsBlock>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import analyticsKpiMixin from './analyticsKpiMixin.js'

/**
 * KPI card: percentage of leads won over total leads in the selected period.
 *
 * @spec openspec/changes/decompose-unified-analytics/specs/dashboard/spec.md#REQ-DASH-010
 */
export default {
	name: 'LeadConversionKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [analyticsKpiMixin],
	data() {
		return {
			ChartLine,
		}
	},
	computed: {
		formatted() {
			const value = this.overview.leadConversionRate
			if (value === null || value === undefined) {
				return this.t('pipelinq', 'N/A')
			}
			return value.toFixed(1) + '%'
		},
	},
}
</script>
