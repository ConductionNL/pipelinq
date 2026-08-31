<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Collapsible WBS (work breakdown structure) tree for a project — renders
  phases (level 2) with their tasks (level 3) indented underneath. Drives
  the inline "Fase toevoegen" / "Taak toevoegen" / "Tijd registreren"
  buttons on the project detail page by emitting events; this component
  never persists anything itself.

  Spec: project-task-hierarchy / REQ-PTH-007 Scenario 28..30,
        REQ-PTH-005 Scenarios 19..21 (billable inheritance).

  Billable inheritance: every level reads its first explicitly-set value
  walking up project → phase → task → activity. We expose the helper as a
  named function on this component so the parent ProjectDetail / activity
  list can reuse the same resolution rule.
-->
<template>
	<div class="wbs-tree">
		<div v-if="phases.length === 0" class="wbs-empty">
			<p>{{ t('pipelinq', 'There are no phases for this project yet.') }}</p>
			<NcButton @click="$emit('add-phase')">
				{{ t('pipelinq', 'Add phase') }}
			</NcButton>
		</div>

		<div v-for="phase in orderedPhases" :key="phase.id" class="wbs-phase">
			<div
				class="wbs-phase__row"
				role="button"
				tabindex="0"
				:aria-expanded="isOpen(phase.id)"
				:aria-label="
					t('pipelinq', 'Toggle phase {name}', {
						name: phase.title || phase.name,
					})
				"
				@click="toggle(phase.id)"
				@keydown.enter.prevent="toggle(phase.id)"
				@keydown.space.prevent="toggle(phase.id)">
				<span
					class="wbs-phase__chevron"
					:class="{ 'wbs-phase__chevron--open': isOpen(phase.id) }">
					›
				</span>
				<span class="wbs-phase__name">{{
					phase.name || t('pipelinq', '(unnamed phase)')
				}}</span>
				<span
					class="status-pill"
					:class="'status-pill--' + (phase.status || 'open')">
					{{ statusLabel(phase.status) }}
				</span>
				<span class="wbs-billable">
					<span
						class="billable-dot"
						:class="[
							resolvedBillable('phase', phase)
								? 'billable-dot--on'
								: 'billable-dot--off',
						]" />
					{{ billableLabel('phase', phase) }}
				</span>
				<span class="wbs-progress">
					{{ tasksCompleted(phase) }} / {{ tasksFor(phase).length }}
				</span>
			</div>

			<div v-if="isOpen(phase.id)" class="wbs-phase__body">
				<div v-if="tasksFor(phase).length === 0" class="wbs-task-empty">
					{{ t('pipelinq', 'No tasks in this phase yet.') }}
				</div>
				<div v-for="task in tasksFor(phase)" :key="task.id" class="wbs-task">
					<span class="wbs-task__name">{{
						task.name || t('pipelinq', '(unnamed task)')
					}}</span>
					<span v-if="task.assignee" class="wbs-task__assignee"
						>@{{ task.assignee }}</span
					>
					<span class="wbs-task__hours">
						{{ task.estimatedHours || 0 }}u
						{{ t('pipelinq', 'planned') }}
						·
						{{ loggedHoursForTask(task.id) }}u
						{{ t('pipelinq', 'logged') }}
					</span>
					<span
						class="status-pill"
						:class="'status-pill--' + (task.status || 'open')">
						{{ statusLabel(task.status) }}
					</span>
					<span class="wbs-billable wbs-billable--task">
						<span
							class="billable-dot"
							:class="[
								resolvedBillable('task', task, { phase })
									? 'billable-dot--on'
									: 'billable-dot--off',
							]" />
						{{ billableLabel('task', task, { phase }) }}
					</span>
					<NcButton
						variant="tertiary"
						@click="$emit('add-activity', { phase, task })">
						{{ t('pipelinq', 'Time entry') }}
					</NcButton>
				</div>
				<div class="wbs-phase__actions">
					<NcButton @click="$emit('add-task', { phase })">
						{{ t('pipelinq', 'Add task') }}
					</NcButton>
				</div>
			</div>
		</div>

		<div v-if="phases.length > 0" class="wbs-tree__actions">
			<NcButton @click="$emit('add-phase')">
				{{ t('pipelinq', 'Add phase') }}
			</NcButton>
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
		/** Parent project — used as the root of the billable inheritance chain. */
		project: {
			type: Object,
			required: true,
		},

		/** Array of projectPhase objects belonging to the project. */
		phases: {
			type: Array,
			default: () => [],
		},

		/** Array of projectTask objects belonging to the project. */
		tasks: {
			type: Array,
			default: () => [],
		},

		/** Array of projectActivity objects belonging to the project. */
		activities: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['add-phase', 'add-task', 'add-activity'],
	data() {
		return {
			openPhases: {},
		}
	},

	computed: {
		/**
		 * Phases sorted by their stored sequence (the legacy field also
		 * exposed as "order" in the design) then by name as a tie-break.
		 *
		 * @return {Array<object>}
		 */
		orderedPhases() {
			return [...this.phases].sort((a, b) => {
				const sa = Number(a.sequence ?? a.order ?? 0)
				const sb = Number(b.sequence ?? b.order ?? 0)
				if (sa !== sb) return sa - sb
				return String(a.name || '').localeCompare(String(b.name || ''))
			})
		},
	},

	methods: {
		/**
		 * Toggle a phase's collapsed/expanded state.
		 *
		 * @param {string} phaseId Phase UUID.
		 */
		toggle(phaseId) {
			this.openPhases = {
				...this.openPhases,
				[phaseId]: !this.openPhases[phaseId],
			}
		},

		/**
		 * Whether the phase is currently expanded. Phases default to open.
		 *
		 * @param {string} phaseId Phase UUID.
		 * @return {boolean}
		 */
		isOpen(phaseId) {
			if (this.openPhases[phaseId] === undefined) return true
			return !!this.openPhases[phaseId]
		},

		/**
		 * Tasks belonging to a given phase, ordered by sequence then name.
		 *
		 * @param {object} phase Phase object.
		 * @return {Array<object>}
		 */
		tasksFor(phase) {
			return this.tasks
				.filter((t) => t.phase === phase.id)
				.sort((a, b) => {
					const sa = Number(a.sequence ?? a.order ?? 0)
					const sb = Number(b.sequence ?? b.order ?? 0)
					if (sa !== sb) return sa - sb
					return String(a.name || '').localeCompare(String(b.name || ''))
				})
		},

		/**
		 * Count of completed tasks in a phase (Scenario 13 / 28).
		 *
		 * @param {object} phase Phase object.
		 * @return {number}
		 */
		tasksCompleted(phase) {
			return this.tasksFor(phase).filter((t) => t.status === 'completed')
				.length
		},

		/**
		 * Logged hours summed across all activities for a given task.
		 *
		 * @param {string} taskId Task UUID.
		 * @return {number}
		 */
		loggedHoursForTask(taskId) {
			const minutes = this.activities
				.filter((a) => a.task === taskId)
				.reduce((sum, a) => sum + (Number(a.durationMinutes) || 0), 0)
			return Math.round((minutes / 60) * 10) / 10
		},

		/**
		 * Resolved billable flag for a given WBS level, walking up the
		 * hierarchy until the first explicitly-set value is found
		 * (REQ-PTH-005). Defaults to `true` when nothing along the chain
		 * is set, matching the spec rule "billable defaults to true".
		 *
		 * @param {string} level    One of 'phase' | 'task' | 'activity'.
		 * @param {object} obj      The object at that level.
		 * @param {object} [ctx]    Optional context — { phase, task } for
		 *                          walking parents that aren't direct refs.
		 * @return {boolean}
		 */
		resolvedBillable(level, obj, ctx) {
			ctx = ctx || {}
			if (obj && typeof obj.billable === 'boolean') {
				return obj.billable
			}
			if (level === 'activity') {
				const task =
					ctx.task || this.tasks.find((t) => t.id === (obj && obj.task))
				if (task) {
					return this.resolvedBillable('task', task)
				}
			}
			if (level === 'task' || level === 'activity') {
				const phase =
					ctx.phase || this.phases.find((p) => p.id === (obj && obj.phase))
				if (phase) {
					return this.resolvedBillable('phase', phase)
				}
			}
			if (this.project && typeof this.project.billable === 'boolean') {
				return this.project.billable
			}
			return true
		},

		/**
		 * UI label for the billable indicator, including the "(geërfd van …)"
		 * hint when the value was resolved via inheritance (Scenarios 8, 11).
		 *
		 * @param {string} level WBS level.
		 * @param {object} obj   Object at this level.
		 * @param {object} [ctx] Inheritance context.
		 * @return {string}
		 */
		billableLabel(level, obj, ctx) {
			const value = this.resolvedBillable(level, obj, ctx)
			const set = obj && typeof obj.billable === 'boolean'
			const base = value
				? t('pipelinq', 'Billable')
				: t('pipelinq', 'Non-billable')
			if (set) return base
			if (level === 'phase') {
				return base + ' ' + t('pipelinq', '(inherited from project)')
			}
			if (level === 'task') {
				return base + ' ' + t('pipelinq', '(inherited from phase)')
			}
			if (level === 'activity') {
				return base + ' ' + t('pipelinq', '(inherited from task)')
			}
			return base
		},

		/**
		 * Localised label for a lifecycle status.
		 *
		 * @param {string|null} status Status code.
		 * @return {string}
		 */
		statusLabel(status) {
			const map = {
				open: t('pipelinq', 'Open'),
				in_progress: t('pipelinq', 'Running'),
				on_hold: t('pipelinq', 'Paused'),
				completed: t('pipelinq', 'Completed'),
				cancelled: t('pipelinq', 'Cancelled'),
			}
			return map[status] || status || '-'
		},
	},
}
</script>

<style scoped>
.wbs-tree {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.wbs-empty {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.wbs-phase {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	overflow: hidden;
}

.wbs-phase__row {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 14px;
	background: var(--color-background-hover);
	cursor: pointer;
}

.wbs-phase__chevron {
	display: inline-block;
	transform: rotate(0deg);
	transition: transform 0.2s ease;
	font-weight: 700;
	width: 12px;
}

.wbs-phase__chevron--open {
	transform: rotate(90deg);
}

.wbs-phase__name {
	flex: 1;
	font-weight: 600;
}

.wbs-progress {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.wbs-phase__body {
	padding: 8px 14px 12px 32px;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.wbs-task {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border-dark);
}

.wbs-task:last-of-type {
	border-bottom: none;
}

.wbs-task__name {
	flex: 1;
	font-weight: 500;
}

.wbs-task__assignee {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.wbs-task__hours {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	font-variant-numeric: tabular-nums;
}

.wbs-task-empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: 4px 0;
}

.wbs-phase__actions {
	margin-top: 8px;
}

.wbs-tree__actions {
	margin-top: 8px;
}

.status-pill {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.85em;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.status-pill--open {
	background: #e3f2fd;
	color: #0d47a1;
}

.status-pill--in_progress {
	background: #fff8e1;
	color: #6d4c00;
}

.status-pill--on_hold {
	background: #ede7f6;
	color: #4527a0;
}

.status-pill--completed {
	background: #e8f5e9;
	color: #1b5e20;
}

.status-pill--cancelled {
	background: #fbe9e7;
	color: #b71c1c;
}

.wbs-billable {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.wbs-billable--task {
	font-size: 0.85em;
}

.billable-dot {
	display: inline-block;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	margin-inline-end: 6px;
	vertical-align: middle;
}

.billable-dot--on {
	background: #43a047;
}

.billable-dot--off {
	background: #b0bec5;
}

@media (prefers-reduced-motion: reduce) {
	.wbs-phase__chevron {
		transition: none;
	}
}
</style>
