<template>
	<CnIndexPage
		:title="t('pipelinq', 'Tasks')"
		:description="t('pipelinq', 'Manage callback requests and follow-up tasks')"
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
		@row-click="openTask"
		@page-changed="onPageChange">
		<template #column-type="{ value }">
			<span class="type-badge" :class="'type-' + value">
				{{ getTaskTypeLabel(value) }}
			</span>
		</template>

		<template #column-status="{ value }">
			<span class="status-badge" :class="'status-' + value">
				{{ getTaskStatusLabel(value) }}
			</span>
		</template>

		<template #column-priority="{ value }">
			<span
				v-if="value && value !== 'normaal'"
				class="priority-badge"
				:style="{ color: getTaskPriorityColor(value) }">
				{{ getTaskPriorityLabel(value) }}
			</span>
			<span v-else>{{ getTaskPriorityLabel(value) }}</span>
		</template>
	</CnIndexPage>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import {
	getTaskTypeLabel,
	getTaskStatusLabel,
	getTaskPriorityLabel,
	getTaskPriorityColor,
} from '../../services/taskUtils.js'

export default {
	name: 'TaskList',
	components: {
		CnIndexPage,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('task', { sidebarState, objectStore })
	},

	methods: {
		getTaskTypeLabel,
		getTaskStatusLabel,
		getTaskPriorityLabel,
		getTaskPriorityColor,
		openTask(row) {
			this.$router.push({ name: 'TaskDetail', params: { id: row.id } })
		},
		createNew() {
			this.$router.push({ name: 'TaskDetail', params: { id: 'new' } })
		},
	},
}
</script>

<style scoped>
.type-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: bold;
}

.type-terugbelverzoek {
	background: #dbeafe;
	color: #1d4ed8;
}

.type-opvolgtaak {
	background: #dcfce7;
	color: #15803d;
}

.type-informatievraag {
	background: #fef3c7;
	color: #92400e;
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: bold;
}

.status-open {
	background: #dbeafe;
	color: #1d4ed8;
}

.status-in_behandeling {
	background: #fef3c7;
	color: #92400e;
}

.status-afgerond {
	background: #dcfce7;
	color: #15803d;
}

.status-verlopen {
	background: #fee2e2;
	color: #991b1b;
}

.priority-badge {
	font-weight: 600;
}
</style>
