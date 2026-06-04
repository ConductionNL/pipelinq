<template>
	<ComplaintsOverviewWidget :complaints="complaints" :loading="loading" />
</template>

<script>
import ComplaintsOverviewWidget from '../../widgets/ComplaintsOverviewWidget.vue'
import { getComplaints } from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'ComplaintsWidget',
	components: {
		ComplaintsOverviewWidget,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			loading: true,
			complaints: [],
		}
	},
	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-7
		 */
		async load() {
			try {
				this.complaints = await getComplaints()
			} catch (err) {
				console.error('ComplaintsWidget fetch error:', err)
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
