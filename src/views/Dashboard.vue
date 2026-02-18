<template>
	<div class="pipelinq-dashboard">
		<h2>{{ t('pipelinq', 'Welcome to Pipelinq') }}</h2>
		<p>{{ t('pipelinq', 'Manage your clients and requests') }}</p>

		<div class="dashboard-actions">
			<NcButton type="primary" @click="$emit('navigate', 'clients')">
				{{ t('pipelinq', 'Clients') }}
			</NcButton>
			<NcButton @click="$emit('navigate', 'requests')">
				{{ t('pipelinq', 'Requests') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'Dashboard',
	components: {
		NcButton,
	},
}
</script>

<style scoped>
.pipelinq-dashboard {
	padding: 20px;
	max-width: 800px;
}

.dashboard-actions {
	margin-top: 20px;
	display: flex;
	gap: 12px;
}
</style>
