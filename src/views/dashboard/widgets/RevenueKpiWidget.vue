<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Revenue')"
		:count="overview.revenue || 0"
		:count-label="trendLabel('revenue')"
		:icon="CashMultiple"
		variant="primary"
		horizontal>
		<template #value>
			{{ formatEur(overview.revenue) }}
		</template>
	</CnStatsBlock>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import commercialKpiMixin from './commercialKpiMixin.js'

/**
 * KPI card: settled POS turnover + won-deal value in the dashboard date range.
 *
 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
 */
export default {
	name: 'RevenueKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [commercialKpiMixin],
	data() {
		return {
			CashMultiple,
		}
	},
}
</script>
