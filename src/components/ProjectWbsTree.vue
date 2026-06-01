<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->

<!--
  Work Breakdown Structure tree component.

  Renders collapsible phase rows with indented task rows. Implements the
  resolvedBillable() helper that walks up the hierarchy (activity → task →
  phase → project) and returns the first explicitly set value, defaulting
  to true. A "(geërfd)" label is appended in the UI whenever the displayed
  value is inherited rather than explicitly set.

  Emits:
    add-task(phaseId)   – user clicked "Taak toevoegen" on a phase row
    add-phase()         – user clicked "Fase toevoegen" button (delegated up)
    log-activity(taskId) – user clicked "Tijdregistratie" on a task row

  @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.4
-->
<template>
	<div class="wbs-tree">
		<!-- Empty state -->
		<div v-if="!phases.length" class="wbs-tree__empty">
			<p>{{ t('pipelinq', 'Geen fases gevonden. Voeg een fase toe om te beginnen.') }}</p>
		</div>

		<!-- Phase rows -->
		<div
			v-for="phase in sortedPhases"
			:key="phase.id"
			class="wbs-phase">
			<!-- Phase header row -->
			<div
				class="wbs-phase__header"
				@click="togglePhase(phase.id)">
				<!-- Expand / collapse chevron -->
				<span class="wbs-phase__chevron">
					{{ collapsedPhases[phase.id] ? '▶' : '▼' }}
				</span>

				<span class="wbs-phase__name">{{ phase.name }}</span>

				<!-- Status chip -->
				<span :class="['wbs-chip', 'wbs-chip--' + (phase.status || 'open')]">
					{{ statusLabel(phase.status) }}
				</span>

				<!-- Billable indicator -->
				<span class="wbs-billable">
					<template v-if="resolvedBillable('phase', phase) !== null">
						<span :class="resolvedBillable('phase', phase) ? 'wbs-billable--yes' : 'wbs-billable--no'">
							{{ resolvedBillable('phase', phase) ? t('pipelinq', 'Factureerbaar') : t('pipelinq', 'Niet-factureerbaar') }}
						</span>
						<span v-if="phase.billable === null || phase.billable === undefined" class="wbs-inherited">
							({{ t('pipelinq', 'geërfd van project') }})
						</span>
					</template>
				</span>

				<!-- Progress bar: tasks completed / total -->
				<span class="wbs-progress">
					<span class="wbs-progress__label">
						{{ completedTaskCount(phase.id) }}/{{ phaseTaskCount(phase.id) }}
					</span>
					<span class="wbs-progress__bar">
						<span
							class="wbs-progress__fill"
							:style="{ width: phaseProgressPercent(phase.id) + '%' }" />
					</span>
				</span>

				<!-- Add task button -->
				<NcButton
					size="small"
					type="tertiary"
					class="wbs-phase__action"
					@click.stop="$emit('add-task', phase.id)">
					{{ t('pipelinq', 'Taak toevoegen') }}
				</NcButton>
			</div>

			<!-- Task rows (indented under phase) -->
			<div v-if="!collapsedPhases[phase.id]" class="wbs-phase__tasks">
				<div
					v-for="task in phaseTasks(phase.id)"
					:key="task.id"
					class="wbs-task">
					<span class="wbs-task__indent" />

					<span class="wbs-task__name">{{ task.name }}</span>

					<!-- Assignee avatar placeholder -->
					<span v-if="task.assignee" class="wbs-task__assignee" :title="task.assignee">
						{{ task.assignee.substring(0, 2).toUpperCase() }}
					</span>

					<!-- Estimated hours -->
					<span class="wbs-task__hours" :title="t('pipelinq', 'Geschatte uren')">
						{{ task.estimatedHours != null ? task.estimatedHours + 'u' : '-' }}
					</span>

					<!-- Logged hours (sum of activities for this task) -->
					<span class="wbs-task__logged" :title="t('pipelinq', 'Gelogde uren')">
						{{ formatLoggedHours(loggedHoursForTask(task.id)) }}
					</span>

					<!-- Status chip -->
					<span :class="['wbs-chip', 'wbs-chip--' + (task.status || 'open')]">
						{{ statusLabel(task.status) }}
					</span>

					<!-- Billable indicator -->
					<span class="wbs-billable">
						<span :class="resolvedBillable('task', task, phase) ? 'wbs-billable--yes' : 'wbs-billable--no'">
							{{ resolvedBillable('task', task, phase) ? t('pipelinq', 'Factureerbaar') : t('pipelinq', 'Niet-factureerbaar') }}
						</span>
						<span v-if="task.billable === null || task.billable === undefined" class="wbs-inherited">
							({{ t('pipelinq', 'geërfd van fase') }})
						</span>
					</span>

					<!-- Log time entry button -->
					<NcButton
						size="small"
						type="tertiary"
						class="wbs-task__action"
						@click="$emit('log-activity', task.id)">
						{{ t('pipelinq', 'Tijdregistratie') }}
					</NcButton>
				</div>

				<!-- Empty tasks placeholder -->
				<div v-if="!phaseTaskCount(phase.id)" class="wbs-phase__empty-tasks">
					<span>{{ t('pipelinq', 'Geen taken') }}</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'ProjectWbsTree',

	components: {
		NcButton,
	},

	props: {
		/**
		 * Parent project object (used to resolve top-level billable flag).
		 *
		 * @type {object}
		 */
		project: {
			type: Object,
			required: true,
		},

		/**
		 * Array of projectPhase objects belonging to this project.
		 *
		 * @type {Array}
		 */
		phases: {
			type: Array,
			default: () => [],
		},

		/**
		 * Array of projectTask objects belonging to this project.
		 *
		 * @type {Array}
		 */
		tasks: {
			type: Array,
			default: () => [],
		},

		/**
		 * Array of projectActivity objects belonging to this project.
		 * Used to compute logged hours per task.
		 *
		 * @type {Array}
		 */
		activities: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['add-task', 'add-phase', 'log-activity'],

	data() {
		return {
			/** Tracks which phases are collapsed by phase id. */
			collapsedPhases: {},
		}
	},

	computed: {
		/**
		 * Phases sorted by their `order` property ascending.
		 *
		 * @return {Array} Sorted phases.
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.4a
		 */
		sortedPhases() {
			return [...this.phases].sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
		},
	},

	methods: {
		/**
		 * Toggle collapse state for a phase.
		 *
		 * @param {string} phaseId The phase id.
		 */
		togglePhase(phaseId) {
			this.$set(this.collapsedPhases, phaseId, !this.collapsedPhases[phaseId])
		},

		/**
		 * Return tasks belonging to a given phase, sorted by order.
		 *
		 * @param {string} phaseId The phase id.
		 * @return {Array} Tasks for the phase.
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.4b
		 */
		phaseTasks(phaseId) {
			return [...this.tasks]
				.filter(t => t.phase === phaseId)
				.sort((a, b) => (a.order ?? 0) - (b.order ?? 0))
		},

		/**
		 * Total task count for a phase.
		 *
		 * @param {string} phaseId The phase id.
		 * @return {number} Total tasks.
		 */
		phaseTaskCount(phaseId) {
			return this.tasks.filter(t => t.phase === phaseId).length
		},

		/**
		 * Completed task count for a phase.
		 *
		 * @param {string} phaseId The phase id.
		 * @return {number} Completed tasks.
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.4c
		 */
		completedTaskCount(phaseId) {
			return this.tasks.filter(t => t.phase === phaseId && t.status === 'completed').length
		},

		/**
		 * Progress percentage for a phase (completed tasks / total tasks * 100).
		 *
		 * @param {string} phaseId The phase id.
		 * @return {number} Progress percentage (0-100).
		 */
		phaseProgressPercent(phaseId) {
			const total = this.phaseTaskCount(phaseId)
			if (!total) return 0
			return Math.round((this.completedTaskCount(phaseId) / total) * 100)
		},

		/**
		 * Sum logged hours (durationMinutes / 60) for a given task from activities.
		 *
		 * @param {string} taskId The task id.
		 * @return {number} Logged hours.
		 */
		loggedHoursForTask(taskId) {
			return this.activities
				.filter(a => a.task === taskId)
				.reduce((sum, a) => sum + ((a.durationMinutes || 0) / 60), 0)
		},

		/**
		 * Format hours as "Xu" or "Xu Ymin" display string.
		 *
		 * @param {number} hours Total hours (possibly fractional).
		 * @return {string} Formatted string.
		 */
		formatLoggedHours(hours) {
			if (!hours) return '0u'
			const h = Math.floor(hours)
			const m = Math.round((hours - h) * 60)
			if (!m) return h + 'u'
			return h + 'u ' + m + 'min'
		},

		/**
		 * Resolve the effective billable flag for a WBS level object.
		 *
		 * Walks up the hierarchy returning the first explicitly set value:
		 *   activity.billable ?? task.billable ?? phase.billable ?? project.billable ?? true
		 *
		 * @param {'project'|'phase'|'task'|'activity'} level The WBS level.
		 * @param {object} obj The object at this level.
		 * @param {object|null} parent The parent object (phase for task, task for activity).
		 * @return {boolean} The resolved billable value.
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.4e
		 */
		resolvedBillable(level, obj, parent = null) {
			if (level === 'project') {
				return obj.billable !== null && obj.billable !== undefined ? obj.billable : true
			}
			if (level === 'phase') {
				if (obj.billable !== null && obj.billable !== undefined) return obj.billable
				return this.project.billable !== null && this.project.billable !== undefined
					? this.project.billable
					: true
			}
			if (level === 'task') {
				if (obj.billable !== null && obj.billable !== undefined) return obj.billable
				if (parent) return this.resolvedBillable('phase', parent)
				// Fallback: look up parent phase
				const phase = this.phases.find(p => p.id === obj.phase)
				if (phase) return this.resolvedBillable('phase', phase)
				return this.resolvedBillable('project', this.project)
			}
			if (level === 'activity') {
				if (obj.billable !== null && obj.billable !== undefined) return obj.billable
				const task = this.tasks.find(tk => tk.id === obj.task)
				if (task) return this.resolvedBillable('task', task)
				return this.resolvedBillable('project', this.project)
			}
			return true
		},

		/**
		 * Human-readable label for a status value.
		 *
		 * @param {string} status The status value.
		 * @return {string} The label.
		 */
		statusLabel(status) {
			const map = {
				open: t('pipelinq', 'Open'),
				in_progress: t('pipelinq', 'In uitvoering'),
				completed: t('pipelinq', 'Voltooid'),
				cancelled: t('pipelinq', 'Geannuleerd'),
				on_hold: t('pipelinq', 'In de wacht'),
			}
			return map[status] || status || t('pipelinq', 'Open')
		},
	},
}
</script>

<style scoped>
.wbs-tree {
	padding: 0;
}

.wbs-tree__empty {
	padding: 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.wbs-phase {
	margin-bottom: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.wbs-phase__header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 12px;
	background: var(--color-background-dark);
	cursor: pointer;
	user-select: none;
	flex-wrap: wrap;
}

.wbs-phase__header:hover {
	background: var(--color-background-hover);
}

.wbs-phase__chevron {
	font-size: 10px;
	color: var(--color-text-maxcontrast);
	width: 12px;
	text-align: center;
}

.wbs-phase__name {
	font-weight: 600;
	flex: 1;
	min-width: 120px;
}

.wbs-phase__action {
	margin-left: auto;
}

.wbs-phase__tasks {
	background: var(--color-main-background);
}

.wbs-phase__empty-tasks {
	padding: 10px 40px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.wbs-task {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border-dark);
	flex-wrap: wrap;
}

.wbs-task:last-child {
	border-bottom: none;
}

.wbs-task__indent {
	width: 20px;
	flex-shrink: 0;
}

.wbs-task__name {
	flex: 1;
	min-width: 120px;
}

.wbs-task__assignee {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 28px;
	height: 28px;
	border-radius: 50%;
	background: var(--color-primary);
	color: var(--color-primary-text);
	font-size: 11px;
	font-weight: bold;
	flex-shrink: 0;
}

.wbs-task__hours,
.wbs-task__logged {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.wbs-task__action {
	margin-left: auto;
}

.wbs-chip {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 500;
	white-space: nowrap;
}

.wbs-chip--open {
	background: #e3f2fd;
	color: #1565c0;
}

.wbs-chip--in_progress {
	background: #fff8e1;
	color: #f57f17;
}

.wbs-chip--completed {
	background: #e8f5e9;
	color: #2e7d32;
}

.wbs-chip--cancelled {
	background: #fce4ec;
	color: #c62828;
}

.wbs-billable {
	font-size: 12px;
	display: flex;
	align-items: center;
	gap: 4px;
}

.wbs-billable--yes {
	color: #2e7d32;
}

.wbs-billable--no {
	color: #c62828;
}

.wbs-inherited {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.wbs-progress {
	display: flex;
	align-items: center;
	gap: 6px;
}

.wbs-progress__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.wbs-progress__bar {
	width: 60px;
	height: 6px;
	background: var(--color-border);
	border-radius: 3px;
	overflow: hidden;
}

.wbs-progress__fill {
	display: block;
	height: 100%;
	background: var(--color-primary);
	transition: width 0.3s ease;
}
</style>
