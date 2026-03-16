<template>
	<CnIndexPage
		:title="t('pipelinq', 'Requests')"
		:description="t('pipelinq', 'Handle incoming requests')"
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
		@row-click="openRequest"
		@page-changed="onPageChange">
		<template #column-status="{ row }">
			<div class="status-cell" @click.stop>
				<span
					class="status-badge"
					:style="{ background: getStatusColor(row.status), color: '#fff' }">
					{{ getStatusLabel(row.status) }}
				</span>
				<NcSelect
					v-if="getAllowedTransitions(row.status).length > 0"
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

		<template #column-requestedAt="{ value }">
			{{ formatDate(value) }}
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
} from '../../services/requestStatus.js'

export default {
	name: 'RequestList',
	components: {
		NcSelect,
		CnIndexPage,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('request', {
			sidebarState,
			objectStore,
			defaultSort: { key: 'requestedAt', order: 'desc' },
		})
	},

	methods: {
		getAllowedTransitions,
		getStatusLabel,
		getStatusColor,
		getPriorityLabel,
		getPriorityColor,

		getTransitionOptions(currentStatus) {
			return getAllowedTransitions(currentStatus).map(s => ({
				id: s,
				label: getStatusLabel(s),
			}))
		},

		async quickStatusChange(request, option) {
			if (!option) return
			const newStatus = option.id || option
			const objectStore = useObjectStore()
			await objectStore.saveObject('request', {
				...request,
				status: newStatus,
			})
			this.refresh()
		},

		openRequest(row) {
			this.$router.push({ name: 'RequestDetail', params: { id: row.id } })
		},
		createNew() {
			this.$router.push({ name: 'RequestDetail', params: { id: 'new' } })
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
	gap: 6px;
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.status-quick-change {
	width: 44px;
	min-width: 44px;
}

.priority-text {
	font-weight: 600;
	font-size: 13px;
}
</style>
