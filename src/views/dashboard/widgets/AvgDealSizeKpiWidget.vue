<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Avg Deal Size')"
		:count="overview.avgDealSize || 0"
		:count-label="trendLabel('avgDealSize')"
		:icon="ScaleBalance"
		variant="default"
		horizontal>
		<template #value>
			{{ formatEur(overview.avgDealSize) }}
		</template>
	</CnStatsBlock>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import commercialKpiMixin from './commercialKpiMixin.js'

/**
 * KPI card: mean value of won deals in the dashboard date range.
 *
 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
 */
export default {
	name: 'AvgDealSizeKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [commercialKpiMixin],
	data() {
		return {
			ScaleBalance,
		}
	},
}
</script>
