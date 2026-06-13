<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Open Pipeline')"
		:count="overview.openPipelineValue || 0"
		:count-label="trendLabel('openPipelineValue')"
		:icon="Filter"
		variant="default"
		horizontal>
		<template #value>
			{{ formatEur(overview.openPipelineValue) }}
		</template>
	</CnStatsBlock>
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import Filter from 'vue-material-design-icons/Filter.vue'
import commercialKpiMixin from './commercialKpiMixin.js'

/**
 * KPI card: total value of open leads.
 *
 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
 */
export default {
	name: 'OpenPipelineKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [commercialKpiMixin],
	data() {
		return {
			Filter,
		}
	},
}
</script>
