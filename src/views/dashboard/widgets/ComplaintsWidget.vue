<template>
	<ComplaintsOverviewWidget :complaints="complaints" :loading="loading" />
</template>

<script>
import ComplaintsOverviewWidget from '../../widgets/ComplaintsOverviewWidget.vue'
import { getComplaints } from '../../../services/dashboardData.js'

export default {
	name: 'ComplaintsWidget',
	components: {
		ComplaintsOverviewWidget,
	},
	data() {
		return {
			loading: true,
			complaints: [],
		}
	},
	async mounted() {
		try {
			this.complaints = await getComplaints()
		} catch (err) {
			console.error('ComplaintsWidget fetch error:', err)
		} finally {
			this.loading = false
		}
	},
}
</script>
