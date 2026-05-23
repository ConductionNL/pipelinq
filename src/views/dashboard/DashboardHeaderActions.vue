<template>
	<div class="dashboard-header-actions">
		<NcButton type="primary" @click="showLeadDialog = true">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('pipelinq', 'New Lead') }}
		</NcButton>
		<NcButton @click="showRequestDialog = true">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('pipelinq', 'New Request') }}
		</NcButton>
		<NcButton @click="showClientDialog = true">
			<template #icon>
				<Plus :size="20" />
			</template>
			{{ t('pipelinq', 'New Client') }}
		</NcButton>
		<NcButton
			:aria-label="t('pipelinq', 'Refresh dashboard')"
			@click="refresh">
			<template #icon>
				<Refresh :size="20" :class="{ 'icon-spinning': refreshing }" />
			</template>
		</NcButton>

		<LeadCreateDialog
			v-if="showLeadDialog"
			@created="onLeadCreated"
			@close="showLeadDialog = false" />

		<RequestCreateDialog
			v-if="showRequestDialog"
			@created="onRequestCreated"
			@close="showRequestDialog = false" />

		<ClientCreateDialog
			v-if="showClientDialog"
			@created="onClientCreated"
			@close="showClientDialog = false" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import LeadCreateDialog from '../leads/LeadCreateDialog.vue'
import RequestCreateDialog from '../requests/RequestCreateDialog.vue'
import ClientCreateDialog from '../clients/ClientCreateDialog.vue'
import { invalidateDashboardData } from '../../services/dashboardData.js'

export default {
	name: 'DashboardHeaderActions',
	components: {
		NcButton,
		Plus,
		Refresh,
		LeadCreateDialog,
		RequestCreateDialog,
		ClientCreateDialog,
	},
	data() {
		return {
			showLeadDialog: false,
			showRequestDialog: false,
			showClientDialog: false,
			refreshing: false,
		}
	},
	methods: {
		async refresh() {
			this.refreshing = true
			invalidateDashboardData()
			// Force every widget to remount by bumping the route key. Cheap
			// trick — re-pushing the same route after a query bump makes
			// vue-router rerender the page, which remounts every widget.
			const q = { ...this.$route.query, _r: Date.now() }
			await this.$router.replace({ name: this.$route.name, query: q })
			// brief visual feedback
			setTimeout(() => { this.refreshing = false }, 400)
		},
		onLeadCreated(leadId) {
			this.showLeadDialog = false
			this.$router.push({ name: 'LeadDetail', params: { id: leadId } })
		},
		onRequestCreated(requestId) {
			this.showRequestDialog = false
			this.$router.push({ name: 'RequestDetail', params: { id: requestId } })
		},
		onClientCreated(clientId) {
			this.showClientDialog = false
			this.$router.push({ name: 'ClientDetail', params: { id: clientId } })
		},
	},
}
</script>

<style scoped>
.dashboard-header-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}

.icon-spinning {
	animation: spin 1s linear infinite;
}

@keyframes spin {
	from { transform: rotate(0deg); }
	to { transform: rotate(360deg); }
}
</style>
