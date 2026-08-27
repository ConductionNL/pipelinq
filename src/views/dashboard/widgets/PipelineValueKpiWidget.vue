<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Pipeline Value')"
		:count="count"
		:loading="loading"
		:error="error"
		countLabel="EUR"
		:icon="CurrencyEur"
		variant="success"
		horizontal
		:route="{ name: 'Pipelines' }" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import CurrencyEur from 'vue-material-design-icons/CurrencyEur.vue'
import {
	getClosedStageNames,
	getLeads,
	getPipelines,
} from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'PipelineValueKpiWidget',
	components: {
		CnStatsBlock,
	},

	mixins: [dashboardRefreshMixin],
	data() {
		return {
			CurrencyEur,
			count: 0,
		}
	},

	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-16
		 */
		async load() {
			const [leads, pipelines] = await Promise.all([
				getLeads(),
				getPipelines(),
			])
			const closed = getClosedStageNames(pipelines)
			this.count = leads
				.filter((l) => !closed.has(l.stage))
				.reduce((sum, l) => sum + (Number(l.value) || 0), 0)
		},
	},
}
</script>
