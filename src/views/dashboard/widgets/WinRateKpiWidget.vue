<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Win Rate')"
		:count="overview.winRate || 0"
		:count-label="trendLabel('winRate')"
		:icon="TrendingUp"
		variant="success"
		horizontal>
		<template #value>
			{{ formatted }}
		</template>
	</CnStatsBlock>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import commercialKpiMixin from './commercialKpiMixin.js'

/**
 * KPI card: won / (won + lost) deals closed in the dashboard date range.
 *
 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
 */
export default {
	name: 'WinRateKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [commercialKpiMixin],
	data() {
		return {
			TrendingUp,
		}
	},
	computed: {
		/**
		 * Win rate as a one-decimal percentage, or N/A when no deals closed.
		 *
		 * @return {string} Formatted percentage.
		 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
		 */
		formatted() {
			const value = this.overview.winRate
			if (value === null || value === undefined) {
				return this.t('pipelinq', 'N/A')
			}
			return value.toFixed(1) + '%'
		},
	},
}
</script>
