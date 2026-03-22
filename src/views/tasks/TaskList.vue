<template>
	<div class="task-list">
		<div class="task-list__header">
			<h2>{{ t('pipelinq', 'Tasks') }}</h2>
			<NcButton type="primary" @click="$router.push({ name: 'TaskNew' })">
				{{ t('pipelinq', 'New task') }}
			</NcButton>
		</div>

		<div class="task-list__filters">
			<div class="filter-buttons">
				<NcButton
					v-for="typeOpt in typeOptions"
					:key="typeOpt.value"
					:type="filterType === typeOpt.value ? 'primary' : 'secondary'"
					@click="filterType = typeOpt.value">
					{{ typeOpt.label }}
				</NcButton>
			</div>
			<div class="filter-buttons">
				<NcButton
					v-for="statusOpt in statusOptions"
					:key="statusOpt.value"
					:type="filterStatus === statusOpt.value ? 'primary' : 'secondary'"
					@click="filterStatus = statusOpt.value">
					{{ statusOpt.label }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="filteredTasks.length === 0" class="task-list__empty">
			<NcEmptyContent
				:name="t('pipelinq', 'No tasks found')"
				:description="t('pipelinq', 'Create a callback request or follow-up task')">
				<template #action>
					<NcButton type="primary" @click="$router.push({ name: 'TaskNew' })">
						{{ t('pipelinq', 'New task') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<div v-else class="task-list__items">
			<div
				v-for="task in filteredTasks"
				:key="task.id"
				class="task-card"
				:class="{
					'task-card--overdue': isOverdue(task),
					'task-card--completed': task.status === 'afgerond',
				}"
				tabindex="0"
				@click="$router.push({ name: 'TaskDetail', params: { id: task.id } })"
				@keydown.enter="$router.push({ name: 'TaskDetail', params: { id: task.id } })">
				<div class="task-card__top">
					<span class="type-badge" :class="'type-badge--' + task.type">
						{{ getTypeLabel(task.type) }}
					</span>
					<span
						class="priority-badge"
						:style="{ color: getPriorityColor(task.priority) }">
						{{ task.priority }}
					</span>
					<span
						class="status-badge"
						:class="'status-badge--' + task.status">
						{{ task.status }}
					</span>
				</div>
				<h3 class="task-card__subject">{{ task.subject }}</h3>
				<div class="task-card__meta">
					<span v-if="task.assignee">{{ t('pipelinq', 'Assigned to') }}: {{ task.assignee }}</span>
					<span v-if="task.deadline" class="task-card__deadline" :class="{ 'deadline--urgent': isOverdue(task) }">
						{{ t('pipelinq', 'Deadline') }}: {{ formatDate(task.deadline) }}
					</span>
				</div>
				<div v-if="task.preferredTimeSlot" class="task-card__timeslot">
					{{ t('pipelinq', 'Preferred time') }}: {{ task.preferredTimeSlot }}
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
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
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
	},
	data() {
		return {
			tasks: [],
			loading: false,
			filterType: 'all',
			filterStatus: 'all',
			typeOptions: [
				{ value: 'all', label: t('pipelinq', 'All') },
				{ value: 'terugbelverzoek', label: t('pipelinq', 'Callbacks') },
				{ value: 'opvolgtaak', label: t('pipelinq', 'Follow-ups') },
				{ value: 'informatievraag', label: t('pipelinq', 'Info requests') },
			],
			statusOptions: [
				{ value: 'all', label: t('pipelinq', 'All') },
				{ value: 'open', label: t('pipelinq', 'Open') },
				{ value: 'in_behandeling', label: t('pipelinq', 'In progress') },
				{ value: 'afgerond', label: t('pipelinq', 'Completed') },
				{ value: 'verlopen', label: t('pipelinq', 'Expired') },
			],
		}
	},
	computed: {
		filteredTasks() {
			return this.tasks.filter(task => {
				if (this.filterType !== 'all' && task.type !== this.filterType) return false
				if (this.filterStatus !== 'all' && task.status !== this.filterStatus) return false
				return true
			})
		},
	},
	mounted() {
		this.fetchTasks()
	},
	methods: {
		async fetchTasks() {
			this.loading = true
			try {
				// Fetch from OpenRegister
				this.tasks = []
			} finally {
				this.loading = false
			}
		},
		isOverdue(task) {
			if (task.status === 'afgerond' || !task.deadline) return false
			return new Date(task.deadline) < new Date()
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('nl-NL', {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
		getTypeLabel(type) {
			const labels = {
				terugbelverzoek: t('pipelinq', 'Callback'),
				opvolgtaak: t('pipelinq', 'Follow-up'),
				informatievraag: t('pipelinq', 'Info request'),
			}
			return labels[type] || type
		},
		getPriorityColor(priority) {
			const colors = { hoog: '#e53e3e', normaal: '#3182ce', laag: '#718096' }
			return colors[priority] || '#718096'
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
.task-list { padding: 20px; max-width: 1000px; margin: 0 auto; }
.task-list__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.task-list__filters { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
.filter-buttons { display: flex; gap: 4px; flex-wrap: wrap; }
.task-list__items { display: flex; flex-direction: column; gap: 8px; }
.task-card { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 16px; cursor: pointer; transition: box-shadow 0.2s; }
.task-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
.task-card--overdue { border-left: 4px solid #e53e3e; }
.task-card--completed { opacity: 0.7; }
.task-card__top { display: flex; gap: 8px; margin-bottom: 8px; }
.task-card__subject { margin: 0 0 8px; font-size: 1.05em; }
.task-card__meta { display: flex; gap: 16px; font-size: 0.85em; color: var(--color-text-lighter); }
.task-card__timeslot { margin-top: 4px; font-size: 0.85em; color: var(--color-primary-element); font-weight: 600; }
.deadline--urgent { color: #e53e3e; font-weight: 600; }
.type-badge { padding: 2px 8px; border-radius: var(--border-radius); font-size: 0.75em; font-weight: 600; }
.type-badge--terugbelverzoek { background: #bee3f8; color: #2a4365; }
.type-badge--opvolgtaak { background: #fefcbf; color: #744210; }
.type-badge--informatievraag { background: #c6f6d5; color: #22543d; }
.priority-badge { font-size: 0.75em; font-weight: 700; text-transform: uppercase; }
.status-badge { padding: 2px 8px; border-radius: var(--border-radius); font-size: 0.75em; }
.status-badge--open { background: var(--color-primary-element-light); }
.status-badge--in_behandeling { background: var(--color-warning); color: #000; }
.status-badge--afgerond { background: var(--color-success); color: #fff; }
.status-badge--verlopen { background: #e53e3e; color: #fff; }
.task-list__empty { padding: 40px 0; }
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
