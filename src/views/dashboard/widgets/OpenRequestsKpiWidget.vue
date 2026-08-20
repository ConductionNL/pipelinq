<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Open Requests')"
		:count="count"
		:countLabel="t('pipelinq', 'requests')"
		:icon="FileDocument"
		variant="primary"
		horizontal
		:route="{
			name: 'Tickets',
			query: { ticketType: 'request', status: 'open' },
		}" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import { getRequests } from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'OpenRequestsKpiWidget',
	components: {
		CnStatsBlock,
	},

	mixins: [dashboardRefreshMixin],
	data() {
		return {
			FileDocument,
			count: 0,
		}
	},

	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-14
		 */
		async load() {
			try {
				const requests = await getRequests()
				this.count = requests.filter(
					(r) => r.status === 'new' || r.status === 'in_progress',
				).length
			} catch (err) {
				console.error('OpenRequestsKpiWidget fetch error:', err)
			}
		},
	},
}
</script>
