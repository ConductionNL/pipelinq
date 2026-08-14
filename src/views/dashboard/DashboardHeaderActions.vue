<template>
	<div class="dashboard-header-actions">
		<NcButton variant="primary" @click="showLeadDialog = true">
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
		<NcButton :aria-label="t('pipelinq', 'Refresh dashboard')" @click="refresh">
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
import LeadCreateDialog from '../../dialogs/LeadCreateDialog.vue'
import RequestCreateDialog from '../../dialogs/RequestCreateDialog.vue'
import ClientCreateDialog from '../../dialogs/ClientCreateDialog.vue'
import {
	refreshDashboardData,
	getLeads,
	getRequests,
	getPipelines,
	getComplaints,
	getClients,
	getMyLeads,
	getMyRequests,
} from '../../services/dashboardData.js'

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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-4
		 */
		async refresh() {
			this.refreshing = true
			try {
				// Drop cached datasets and bump the refresh signal so every
				// mounted widget re-runs its load(). Then await the shared
				// fetchers so the spinner reflects the real fetch time —
				// widgets share these promises, so no duplicate requests.
				refreshDashboardData()
				await Promise.allSettled([
					getLeads(),
					getRequests(),
					getPipelines(),
					getComplaints(),
					getClients(),
					getMyLeads(),
					getMyRequests(),
				])
			} finally {
				this.refreshing = false
			}
		},
		/**
		 * @param leadId
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-2
		 */
		onLeadCreated(leadId) {
			this.showLeadDialog = false
			this.$router.push({ name: 'LeadDetail', params: { id: leadId } })
		},
		/**
		 * @param requestId
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-3
		 */
		onRequestCreated(requestId) {
			this.showRequestDialog = false
			// A request is a `ticket` with ticketType=request
			// (unify-ticket-supertype) — open the unified detail page.
			this.$router.push({ name: 'TicketDetail', params: { id: requestId } })
		},
		/**
		 * @param clientId
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-1
		 */
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
	from {
		transform: rotate(0deg);
	}
	to {
		transform: rotate(360deg);
	}
}

@media (prefers-reduced-motion: reduce) {
	.icon-spinning {
		animation: none;
	}
}
</style>
