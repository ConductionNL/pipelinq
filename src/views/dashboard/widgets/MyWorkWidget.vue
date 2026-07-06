<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnDataTable :rows="items"
		:columns="columns"
		:loading="!loaded"
		:loading-text="t('pipelinq', 'Loading…')"
		hide-header
		borderless
		:empty-text="t('pipelinq', 'No items assigned to you')"
		:row-class="rowClass"
		@row-click="openItem">
		<template #column-entityType="{ row }">
			<span class="entity-badge" :class="'badge--' + row.entityType">
				{{ row.entityType === 'lead' ? 'LEAD' : 'REQ' }}
			</span>
		</template>
		<template #column-dueDate="{ row }">
			<span v-if="row.dueDate" class="my-work-due" :class="{ overdue: row.isOverdue }">
				{{ formatDate(row.dueDate) }}
			</span>
		</template>
		<template #footer>
			<NcButton
				v-if="total > 5"
				type="tertiary"
				class="view-all-link"
				@click="$router.push({ name: 'MyWork' })">
				{{ t('pipelinq', 'View all ({count})', { count: total }) }}
			</NcButton>
		</template>
	</CnDataTable>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { getMyLeads, getMyRequests, getPipelines, getClosedStageNames } from '../../../services/dashboardData.js'
import { getStatusLabel } from '../../../services/requestStatus.js'
import { formatDate } from '../../../services/localeUtils.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

const PRIORITY_ORDER = { urgent: 0, high: 1, normal: 2, low: 3 }

export default {
	name: 'MyWorkWidget',
	components: {
		CnDataTable,
		NcButton,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			loaded: false,
			myLeads: [],
			myRequests: [],
			pipelines: [],
			columns: [
				{ key: 'entityType' },
				{ key: 'title', cellClass: 'cn-cell--strong' },
				{ key: 'stageOrStatus', cellClass: 'cn-cell--muted' },
				{ key: 'dueDate', cellClass: 'cn-cell--end' },
			],
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-8
		 */
		allItems() {
			const closed = getClosedStageNames(this.pipelines)
			const now = new Date()
			const items = []

			for (const l of this.myLeads) {
				if (closed.has(l.stage)) continue
				const due = l.expectedCloseDate ? new Date(l.expectedCloseDate) : null
				items.push({
					id: l.id,
					entityType: 'lead',
					title: l.title,
					stageOrStatus: l.stage || '-',
					priority: l.priority || 'normal',
					dueDate: l.expectedCloseDate,
					isOverdue: due ? due < now : false,
				})
			}

			for (const r of this.myRequests) {
				if (r.status === 'completed' || r.status === 'rejected' || r.status === 'converted') continue
				const due = r.requestedAt ? new Date(r.requestedAt) : null
				items.push({
					id: r.id,
					entityType: 'request',
					title: r.title,
					stageOrStatus: getStatusLabel(r.status),
					priority: r.priority || 'normal',
					dueDate: r.requestedAt,
					isOverdue: due ? (now - due) > 30 * 24 * 60 * 60 * 1000 : false,
				})
			}

			items.sort((a, b) => {
				if (a.isOverdue !== b.isOverdue) return a.isOverdue ? -1 : 1
				const pa = PRIORITY_ORDER[a.priority] ?? 2
				const pb = PRIORITY_ORDER[b.priority] ?? 2
				if (pa !== pb) return pa - pb
				if (a.dueDate && b.dueDate) return new Date(a.dueDate) - new Date(b.dueDate)
				if (a.dueDate) return -1
				if (b.dueDate) return 1
				return 0
			})

			return items
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-12
		 */
		total() {
			return this.allItems.length
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-9
		 */
		items() {
			return this.allItems.slice(0, 5)
		},
	},
	methods: {
		formatDate,
		/**
		 * Row emphasis for overdue items (CnDataTable rowClass hook).
		 *
		 * @param {object} row - Work item row.
		 * @return {string} CSS class for the row.
		 */
		rowClass(row) {
			return row.isOverdue ? 'my-work-row--overdue' : ''
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-10
		 */
		async load() {
			try {
				const [myLeads, myRequests, pipelines] = await Promise.all([
					getMyLeads(), getMyRequests(), getPipelines(),
				])
				this.myLeads = myLeads
				this.myRequests = myRequests
				this.pipelines = pipelines
			} catch (err) {
				console.error('MyWorkWidget fetch error:', err)
			} finally {
				this.loaded = true
			}
		},
		/**
		 * @param {object} item - Work item row (lead or request) to navigate to.
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-11
		 */
		openItem(item) {
			if (item.entityType === 'lead') {
				this.$router.push({ name: 'LeadDetail', params: { id: item.id } })
			} else {
				this.$router.push({ name: 'RequestDetail', params: { id: item.id } })
			}
		},
	},
}
</script>

<style scoped>
:deep(.my-work-row--overdue) {
	background: rgba(233, 50, 45, 0.04);
}

.entity-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.5px;
	flex-shrink: 0;
}

.badge--lead {
	background: #dbeafe;
	color: #1d4ed8;
	border: 1px solid #93c5fd;
}

.badge--request {
	background: #ffedd5;
	color: #c2410c;
	border: 1px solid #fdba74;
}

.my-work-due {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.my-work-due.overdue {
	color: var(--color-error);
	font-weight: 600;
}

.view-all-link {
	margin-top: 4px;
}
</style>
