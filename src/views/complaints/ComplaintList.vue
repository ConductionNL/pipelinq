<template>
	<CnIndexPage
		:title="t('pipelinq', 'Complaints')"
		:description="t('pipelinq', 'Register and track customer complaints')"
		:schema="schema"
		:objects="objects"
		:pagination="pagination"
		:loading="loading"
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:selectable="true"
		:include-columns="visibleColumns"
		@add="createNew"
		@refresh="refresh"
		@sort="onSort"
		@row-click="openComplaint"
		@page-changed="onPageChange">
		<template #column-status="{ row }">
			<div class="status-cell" @click.stop>
				<span
					class="status-badge"
					:style="{ background: getStatusColor(row.status), color: '#fff' }">
					{{ getStatusLabel(row.status) }}
				</span>
				<NcSelect
					v-if="getComplaintTransitions(row.status).length > 0"
					:value="null"
					:options="getTransitionOptions(row.status)"
					:placeholder="'\u2192'"
					:clearable="false"
					class="status-quick-change"
					@input="v => quickStatusChange(row, v)" />
			</div>
		</template>

		<template #column-priority="{ row }">
			<span
				class="priority-text"
				:style="{ color: getPriorityColor(row.priority) }">
				{{ getPriorityLabel(row.priority) }}
			</span>
		</template>

		<template #column-category="{ row }">
			{{ getCategoryLabel(row.category) }}
		</template>

		<template #column-slaDeadline="{ row }">
			<span
				v-if="row.slaDeadline"
				class="sla-indicator"
				:style="{ color: getSlaColor(getSlaIndicator(row.slaDeadline, row.status)) }">
				{{ formatDate(row.slaDeadline) }}
				<span v-if="getSlaIndicator(row.slaDeadline, row.status) === 'overdue'" class="sla-overdue-badge">
					{{ t('pipelinq', 'OVERDUE') }}
				</span>
			</span>
			<span v-else>-</span>
		</template>
	</CnIndexPage>
</template>

<script>
import { inject } from 'vue'
import { NcSelect } from '@nextcloud/vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import {
	getAllowedTransitions,
	getStatusLabel,
	getStatusColor,
	getPriorityLabel,
	getPriorityColor,
	getCategoryLabel,
	getSlaIndicator,
	getSlaColor,
	requiresResolution,
} from '../../services/complaintStatus.js'

export default {
	name: 'ComplaintList',
	components: {
		NcSelect,
		CnIndexPage,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('complaint', {
			sidebarState,
			objectStore,
			defaultSort: { key: '_dateCreated', order: 'desc' },
		})
	},

	methods: {
		getComplaintTransitions: getAllowedTransitions,
		getStatusLabel,
		getStatusColor,
		getPriorityLabel,
		getPriorityColor,
		getCategoryLabel,
		getSlaIndicator,
		getSlaColor,

		getTransitionOptions(currentStatus) {
			return getAllowedTransitions(currentStatus).map(s => ({
				id: s,
				label: getStatusLabel(s),
			}))
		},

		async quickStatusChange(complaint, option) {
			if (!option) return
			const newStatus = option.id || option

			if (requiresResolution(newStatus)) {
				// Navigate to detail for resolution input
				this.$router.push({ name: 'ComplaintDetail', params: { id: complaint.id } })
				return
			}

			const objectStore = useObjectStore()
			await objectStore.saveObject('complaint', {
				...complaint,
				status: newStatus,
			})
			this.refresh()
		},

		openComplaint(row) {
			this.$router.push({ name: 'ComplaintDetail', params: { id: row.id } })
		},
		createNew() {
			this.$router.push({ name: 'ComplaintDetail', params: { id: 'new' } })
		},
		formatDate(dateStr) {
			if (!dateStr) return '-'
			try {
				return new Date(dateStr).toLocaleDateString()
			} catch {
				return dateStr
			}
		},
	},
}
</script>

<style scoped>
.status-cell {
	display: flex;
	align-items: center;
	gap: 4px;
}

.status-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.status-quick-change {
	min-width: 50px;
	max-width: 60px;
}

.priority-text {
	font-weight: 600;
}

.sla-indicator {
	font-weight: 500;
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.sla-overdue-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.5px;
	background: rgba(233, 50, 45, 0.1);
	color: #e9322d;
	border: 1px solid rgba(233, 50, 45, 0.3);
}
</style>
