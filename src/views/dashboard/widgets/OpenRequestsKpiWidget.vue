<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Open Requests')"
		:count="count"
		:count-label="t('pipelinq', 'requests')"
		:icon="FileDocument"
		variant="primary"
		horizontal
		:route="{ name: 'Requests', query: { status: 'open' } }" />
</template>

<script>
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import { getRequests } from '../../../services/dashboardData.js'

export default {
	name: 'OpenRequestsKpiWidget',
	components: {
		CnStatsBlock,
	},
	data() {
		return {
			FileDocument,
			count: 0,
		}
	},
	async mounted() {
		try {
			const requests = await getRequests()
			this.count = requests.filter(
				r => r.status === 'new' || r.status === 'in_progress',
			).length
		} catch (err) {
			console.error('OpenRequestsKpiWidget fetch error:', err)
		}
	},
}
</script>
