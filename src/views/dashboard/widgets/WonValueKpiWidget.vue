<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Won Value')"
		:count="overview.wonValue || 0"
		:count-label="trendLabel('wonValue')"
		:icon="Trophy"
		variant="success"
		horizontal>
		<template #value>
			{{ formatEur(overview.wonValue) }}
		</template>
	</CnStatsBlock>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import Trophy from 'vue-material-design-icons/Trophy.vue'
import commercialKpiMixin from './commercialKpiMixin.js'

/**
 * KPI card: value of deals won in the dashboard date range.
 *
 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
 */
export default {
	name: 'WonValueKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [commercialKpiMixin],
	data() {
		return {
			Trophy,
		}
	},
}
</script>
