<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Pipeline Value')"
		:count="count"
		:loading="loading"
		:error="error"
		:countLabel="currency"
		:icon="Cash"
		variant="success"
		horizontal
		:route="{ name: 'Pipelines' }" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import Cash from 'vue-material-design-icons/Cash.vue'
import {
	getClosedStageNames,
	getLeads,
	getPipelines,
} from '../../../services/dashboardData.js'
import { reportingCurrency } from '../../../services/reportingCurrency.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'PipelineValueKpiWidget',
	components: {
		CnStatsBlock,
	},

	mixins: [dashboardRefreshMixin],
	data() {
		return {
			Cash,
			// Open pipeline value is shown in the reporting currency.
			currency: reportingCurrency(),
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
