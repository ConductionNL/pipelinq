<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Open Leads')"
		:count="count"
		:countLabel="t('pipelinq', 'leads')"
		:icon="TrendingUp"
		variant="primary"
		horizontal
		:route="{ name: 'Leads', query: { status: 'open' } }" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import {
	getClosedStageNames,
	getLeads,
	getPipelines,
} from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'OpenLeadsKpiWidget',
	components: {
		CnStatsBlock,
	},

	mixins: [dashboardRefreshMixin],
	data() {
		return {
			TrendingUp,
			count: 0,
		}
	},

	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-13
		 */
		async load() {
			try {
				const [leads, pipelines] = await Promise.all([
					getLeads(),
					getPipelines(),
				])
				const closed = getClosedStageNames(pipelines)
				this.count = leads.filter((l) => !closed.has(l.stage)).length
			} catch (err) {
				console.error('OpenLeadsKpiWidget fetch error:', err)
			}
		},
	},
}
</script>
