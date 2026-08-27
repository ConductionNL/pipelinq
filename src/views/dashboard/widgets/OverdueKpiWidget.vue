<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Overdue')"
		:count="count"
		:loading="loading"
		:error="error"
		:countLabel="t('pipelinq', 'overdue')"
		:icon="AlertCircle"
		:variant="count > 0 ? 'error' : 'default'"
		horizontal
		:route="{ name: 'Leads', query: { overdue: 'true' } }" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import {
	getClosedStageNames,
	getLeads,
	getPipelines,
	getRequests,
} from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'OverdueKpiWidget',
	components: {
		CnStatsBlock,
	},

	mixins: [dashboardRefreshMixin],
	data() {
		return {
			AlertCircle,
			count: 0,
		}
	},

	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-15
		 */
		async load() {
			const [leads, requests, pipelines] = await Promise.all([
				getLeads(),
				getRequests(),
				getPipelines(),
			])
			const closed = getClosedStageNames(pipelines)
			const now = new Date()
			const thirtyDaysAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000)

			const overdueLeads = leads.filter((l) => {
				if (closed.has(l.stage)) return false
				if (!l.expectedCloseDate) return false
				return new Date(l.expectedCloseDate) < now
			}).length

			// `requestedAt` became `occurredAt` on the ticket supertype
			// (unify-ticket-supertype); getRequests() already narrows to
			// ticketType 'request'.
			const overdueRequests = requests.filter((r) => {
				if (r.status !== 'new' && r.status !== 'in_progress') return false
				if (!r.occurredAt) return false
				return new Date(r.occurredAt) < thirtyDaysAgo
			}).length

			this.count = overdueLeads + overdueRequests
		},
	},
}
</script>
