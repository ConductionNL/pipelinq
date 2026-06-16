<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/kcc-werkplek/tasks.md#task-3.2 -->
<template>
	<div class="werkplek-inbox">
		<section class="werkplek-inbox__section">
			<h3 class="werkplek-inbox__heading">
				{{ t('pipelinq', 'Requests') }}
				<span class="werkplek-inbox__count">{{ sortedRequests.length }}</span>
			</h3>
			<div v-if="sortedRequests.length === 0" class="werkplek-inbox__empty-section">
				{{ t('pipelinq', 'No assigned requests') }}
			</div>
			<CnDataTable
				v-else
				:columns="requestColumns"
				:rows="sortedRequests"
				row-key="id"
				:row-class="requestRowClass"
				@row-click="onRequestClick">
				<template #column-priority="{ value }">
					<span class="werkplek-inbox__badge" :class="'werkplek-inbox__badge--' + (value || 'normal')">
						{{ priorityLabel(value) }}
					</span>
				</template>
				<template #column-channel="{ value }">
					<span class="werkplek-inbox__badge werkplek-inbox__badge--channel">
						{{ value || t('pipelinq', '—') }}
					</span>
				</template>
				<template #column-requestedAt="{ value }">
					{{ formatDate(value) }}
				</template>
			</CnDataTable>
		</section>

		<section class="werkplek-inbox__section">
			<h3 class="werkplek-inbox__heading">
				{{ t('pipelinq', 'Tasks') }}
				<span class="werkplek-inbox__count">{{ sortedTasks.length }}</span>
			</h3>
			<div v-if="sortedTasks.length === 0" class="werkplek-inbox__empty-section">
				{{ t('pipelinq', 'No open tasks') }}
			</div>
			<CnDataTable
				v-else
				:columns="taskColumns"
				:rows="sortedTasks"
				row-key="id"
				:row-class="taskRowClass"
				@row-click="onTaskClick">
				<template #column-type="{ value }">
					<span class="werkplek-inbox__badge werkplek-inbox__badge--type">
						{{ typeLabel(value) }}
					</span>
				</template>
				<template #column-deadline="{ value, row }">
					<span :class="{ 'werkplek-inbox__overdue': isOverdue(row) }">
						{{ formatDate(value) }}
					</span>
				</template>
				<template #column-status="{ value }">
					<span class="werkplek-inbox__badge">{{ value || '—' }}</span>
				</template>
			</CnDataTable>
		</section>

		<div
			v-if="sortedRequests.length === 0 && sortedTasks.length === 0"
			class="werkplek-inbox__empty">
			<p>{{ t('pipelinq', 'No open items') }}</p>
		</div>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'

/**
 * Priority ordering used to sort both requests and tasks (highest first).
 *
 * The request and task schemas share the laag/normaal/hoog/urgent values
 * across pipelinq; English equivalents map cleanly onto the same weights.
 */
const PRIORITY_WEIGHT = {
	urgent: 4,
	hoog: 3,
	high: 3,
	normaal: 2,
	normal: 2,
	laag: 1,
	low: 1,
}

/**
 * Inbox panel for the KCC Werkplek — assigned requests + open tasks.
 *
 * The component is presentational: it accepts arrays as props, sorts them
 * by priority (then deadline for tasks), highlights overdue tasks in red
 * via the NL Design `--color-error` token, and emits `select-item` when
 * the agent clicks a row so the parent can route the context into the
 * center panel (REQ-KWP-020 / REQ-KWP-030).
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.2
 */
export default {
	name: 'WerkplekInbox',

	components: { CnDataTable },

	props: {
		/**
		 * Assigned requests for the current agent (status open / in_progress).
		 *
		 * @type {Array<object>}
		 */
		requests: {
			type: Array,
			default: () => [],
		},
		/**
		 * Open tasks assigned to the current agent.
		 *
		 * @type {Array<object>}
		 */
		tasks: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['select-item'],

	computed: {
		/**
		 * Column definitions for the requests table.
		 *
		 * @return {Array<{key: string, label: string}>}
		 */
		requestColumns() {
			return [
				{ key: 'title', label: this.t('pipelinq', 'Title') },
				{ key: 'channel', label: this.t('pipelinq', 'Channel') },
				{ key: 'priority', label: this.t('pipelinq', 'Priority') },
				{ key: 'requestedAt', label: this.t('pipelinq', 'Created') },
			]
		},
		/**
		 * Column definitions for the tasks table.
		 *
		 * @return {Array<{key: string, label: string}>}
		 */
		taskColumns() {
			return [
				{ key: 'subject', label: this.t('pipelinq', 'Subject') },
				{ key: 'type', label: this.t('pipelinq', 'Type') },
				{ key: 'deadline', label: this.t('pipelinq', 'Deadline') },
				{ key: 'status', label: this.t('pipelinq', 'Status') },
			]
		},
		/**
		 * Requests sorted by priority weight descending.
		 *
		 * @return {Array<object>}
		 */
		sortedRequests() {
			return [...this.requests].sort((a, b) => {
				return (PRIORITY_WEIGHT[b.priority] || 0) - (PRIORITY_WEIGHT[a.priority] || 0)
			})
		},
		/**
		 * Tasks sorted by priority descending, then deadline ascending.
		 *
		 * @return {Array<object>}
		 */
		sortedTasks() {
			return [...this.tasks].sort((a, b) => {
				const dp = (PRIORITY_WEIGHT[b.priority] || 0) - (PRIORITY_WEIGHT[a.priority] || 0)
				if (dp !== 0) return dp
				const da = a.deadline ? Date.parse(a.deadline) : Infinity
				const db = b.deadline ? Date.parse(b.deadline) : Infinity
				return da - db
			})
		},
	},

	methods: {
		/**
		 * Whether a task is overdue (deadline before now and not closed).
		 *
		 * @param {object} task Task row.
		 *
		 * @return {boolean}
		 */
		isOverdue(task) {
			if (!task || !task.deadline) return false
			const closed = ['afgerond', 'verlopen']
			if (closed.includes((task.status || '').toLowerCase())) return false
			const ms = Date.parse(task.deadline)
			if (Number.isNaN(ms)) return false
			return ms < Date.now()
		},
		/**
		 * Tailwind-style row class for the request table.
		 *
		 * @return {string}
		 */
		requestRowClass() {
			return 'werkplek-inbox__row'
		},
		/**
		 * Row class for the task table — highlights overdue rows.
		 *
		 * @param {object} task Task row.
		 *
		 * @return {string}
		 */
		taskRowClass(task) {
			return this.isOverdue(task)
				? 'werkplek-inbox__row werkplek-inbox__row--overdue'
				: 'werkplek-inbox__row'
		},
		/**
		 * Translate the priority enum into a human label.
		 *
		 * @param {string} value Priority enum value.
		 *
		 * @return {string}
		 */
		priorityLabel(value) {
			const lookup = {
				urgent: this.t('pipelinq', 'Urgent'),
				hoog: this.t('pipelinq', 'High'),
				high: this.t('pipelinq', 'High'),
				normaal: this.t('pipelinq', 'Normal'),
				normal: this.t('pipelinq', 'Normal'),
				laag: this.t('pipelinq', 'Low'),
				low: this.t('pipelinq', 'Low'),
			}
			return lookup[value] || value || ''
		},
		/**
		 * Translate the task type enum into a human label.
		 *
		 * @param {string} value Task-type enum value.
		 *
		 * @return {string}
		 */
		typeLabel(value) {
			const lookup = {
				terugbelverzoek: this.t('pipelinq', 'Callback'),
				opvolgtaak: this.t('pipelinq', 'Follow-up'),
				informatievraag: this.t('pipelinq', 'Information'),
			}
			return lookup[value] || value || ''
		},
		/**
		 * Format an ISO-8601 timestamp as a short locale-aware date.
		 *
		 * @param {string} value ISO timestamp or empty.
		 *
		 * @return {string}
		 */
		formatDate(value) {
			if (!value) return ''
			try {
				return new Date(value).toLocaleDateString()
			} catch {
				return String(value)
			}
		},
		/**
		 * Forward a request row click as a select-item event with kind=request.
		 *
		 * @param {object} row Clicked request row.
		 */
		onRequestClick(row) {
			this.$emit('select-item', { kind: 'request', item: row })
		},
		/**
		 * Forward a task row click as a select-item event with kind=task.
		 *
		 * @param {object} row Clicked task row.
		 */
		onTaskClick(row) {
			this.$emit('select-item', { kind: 'task', item: row })
		},
	},
}
</script>

<style scoped>
.werkplek-inbox {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px;
	max-height: 100%;
	overflow-y: auto;
}

.werkplek-inbox__section {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.werkplek-inbox__heading {
	font-size: 1em;
	margin: 0;
	padding: 4px 6px;
	display: flex;
	justify-content: space-between;
	align-items: center;
	color: var(--color-text-maxcontrast);
	border-bottom: 1px solid var(--color-border);
}

.werkplek-inbox__count {
	background: var(--color-background-darker);
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 0.85em;
}

.werkplek-inbox__empty-section {
	padding: 8px 6px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	font-size: 0.9em;
}

.werkplek-inbox__empty {
	padding: 24px 12px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.werkplek-inbox__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-background-dark);
	border: 1px solid var(--color-border);
	font-size: 0.85em;
}

.werkplek-inbox__badge--urgent { color: var(--color-error); border-color: var(--color-error); }
.werkplek-inbox__badge--hoog,
.werkplek-inbox__badge--high { color: var(--color-warning); border-color: var(--color-warning); }
.werkplek-inbox__overdue { color: var(--color-error); font-weight: 600; }

:deep(.werkplek-inbox__row) { cursor: pointer; }
:deep(.werkplek-inbox__row--overdue) { background: color-mix(in srgb, var(--color-error) 8%, transparent); }
</style>
