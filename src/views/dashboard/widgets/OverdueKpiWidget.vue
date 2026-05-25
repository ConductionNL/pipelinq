<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Overdue')"
		:count="count"
		:count-label="t('pipelinq', 'overdue')"
		:icon="AlertCircle"
		:variant="count > 0 ? 'error' : 'default'"
		horizontal
		:route="{ name: 'Leads', query: { overdue: 'true' } }" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import { getLeads, getRequests, getPipelines, getClosedStageNames } from '../../../services/dashboardData.js'

export default {
	name: 'OverdueKpiWidget',
	components: {
		CnStatsBlock,
	},
	data() {
		return {
			AlertCircle,
			count: 0,
		}
	},
	async mounted() {
		try {
			const [leads, requests, pipelines] = await Promise.all([
				getLeads(), getRequests(), getPipelines(),
			])
			const closed = getClosedStageNames(pipelines)
			const now = new Date()
			const thirtyDaysAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000)

			const overdueLeads = leads.filter(l => {
				if (closed.has(l.stage)) return false
				if (!l.expectedCloseDate) return false
				return new Date(l.expectedCloseDate) < now
			}).length

			const overdueRequests = requests.filter(r => {
				if (r.status !== 'new' && r.status !== 'in_progress') return false
				if (!r.requestedAt) return false
				return new Date(r.requestedAt) < thirtyDaysAgo
			}).length

			this.count = overdueLeads + overdueRequests
		} catch (err) {
			console.error('OverdueKpiWidget fetch error:', err)
		}
	},
}
</script>
