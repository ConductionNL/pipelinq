<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Pipeline Value')"
		:count="count"
		count-label="EUR"
		:icon="CurrencyEur"
		variant="success"
		horizontal
		:route="{ name: 'Pipeline' }" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import CurrencyEur from 'vue-material-design-icons/CurrencyEur.vue'
import { getLeads, getPipelines, getClosedStageNames } from '../../../services/dashboardData.js'

export default {
	name: 'PipelineValueKpiWidget',
	components: {
		CnStatsBlock,
	},
	data() {
		return {
			CurrencyEur,
			count: 0,
		}
	},
	async mounted() {
		try {
			const [leads, pipelines] = await Promise.all([getLeads(), getPipelines()])
			const closed = getClosedStageNames(pipelines)
			this.count = leads
				.filter(l => !closed.has(l.stage))
				.reduce((sum, l) => sum + (Number(l.value) || 0), 0)
		} catch (err) {
			console.error('PipelineValueKpiWidget fetch error:', err)
		}
	},
}
</script>
