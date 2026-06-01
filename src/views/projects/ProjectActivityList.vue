<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->

<!--
  Project activity (time entry) list view.

  Shows all projectActivity objects for a project in a table with:
  columns: date, user, task name, description, duration, billable badge
  Filters: date range, user, task, billable flag
  Totals row: sum of billable and non-billable hours

  @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.3
-->
<template>
	<CnDetailPage
		:title="projectName + ' — ' + t('pipelinq', 'Tijdregistraties')"
		:subtitle="t('pipelinq', 'Alle gelogde uren voor dit project')"
		:back-route="{ name: 'ProjectDetail', params: { id: projectId } }"
		:back-label="t('pipelinq', 'Terug naar project')"
		:loading="loading">
		<!-- Filter bar -->
		<CnDetailCard :title="t('pipelinq', 'Filters')">
			<div class="filters-row">
				<div class="filter-field">
					<label for="f-date-from">{{ t('pipelinq', 'Datum van') }}</label>
					<input
						id="f-date-from"
						v-model="filters.dateFrom"
						type="date"
						class="filter-input" />
				</div>
				<div class="filter-field">
					<label for="f-date-to">{{ t('pipelinq', 'Datum tot') }}</label>
					<input
						id="f-date-to"
						v-model="filters.dateTo"
						type="date"
						class="filter-input" />
				</div>
				<div class="filter-field">
					<label for="f-user">{{ t('pipelinq', 'Gebruiker') }}</label>
					<input
						id="f-user"
						v-model="filters.user"
						type="text"
						class="filter-input"
						:placeholder="t('pipelinq', 'Alle gebruikers')" />
				</div>
				<div class="filter-field">
					<label for="f-task">{{ t('pipelinq', 'Taak') }}</label>
					<select id="f-task" v-model="filters.task" class="filter-input">
						<option value="">
							{{ t('pipelinq', 'Alle taken') }}
						</option>
						<option v-for="task in tasks" :key="task.id" :value="task.id">
							{{ task.name }}
						</option>
					</select>
				</div>
				<div class="filter-field">
					<label for="f-billable">{{ t('pipelinq', 'Factureerbaar') }}</label>
					<select id="f-billable" v-model="filters.billable" class="filter-input">
						<option value="">
							{{ t('pipelinq', 'Alle') }}
						</option>
						<option value="yes">
							{{ t('pipelinq', 'Factureerbaar') }}
						</option>
						<option value="no">
							{{ t('pipelinq', 'Niet-factureerbaar') }}
						</option>
					</select>
				</div>
				<NcButton type="secondary" @click="clearFilters">
					{{ t('pipelinq', 'Filters wissen') }}
				</NcButton>
			</div>
		</CnDetailCard>

		<!-- Activity table -->
		<CnDetailCard :title="t('pipelinq', 'Tijdregistraties')">
			<div v-if="!filteredActivities.length" class="section-empty">
				<p>{{ t('pipelinq', 'Geen tijdregistraties gevonden') }}</p>
			</div>

			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Datum') }}</th>
							<th>{{ t('pipelinq', 'Gebruiker') }}</th>
							<th>{{ t('pipelinq', 'Taak') }}</th>
							<th>{{ t('pipelinq', 'Beschrijving') }}</th>
							<th>{{ t('pipelinq', 'Duur') }}</th>
							<th>{{ t('pipelinq', 'Factureerbaar') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="activity in filteredActivities" :key="activity.id">
							<td>{{ activity.date || '-' }}</td>
							<td>{{ activity.user || '-' }}</td>
							<td>{{ taskName(activity.task) }}</td>
							<td>{{ activity.description || '-' }}</td>
							<td>{{ formatDuration(activity.durationMinutes) }}</td>
							<td>
								<span
									:class="resolvedBillable(activity) ? 'billable-badge--yes' : 'billable-badge--no'">
									{{ resolvedBillable(activity) ? t('pipelinq', 'Factureerbaar') : t('pipelinq', 'Niet') }}
								</span>
							</td>
						</tr>
					</tbody>
					<!-- Totals row -->
					<tfoot>
						<tr class="totals-row">
							<td colspan="4">
								<strong>{{ t('pipelinq', 'Totaal') }}</strong>
								({{ filteredActivities.length }} {{ t('pipelinq', 'registraties') }})
							</td>
							<td>
								<strong>{{ formatDuration(totalMinutes) }}</strong>
							</td>
							<td>
								<span class="billable-badge--yes">{{ formatDuration(billableMinutes) }}</span>
								&nbsp;/&nbsp;
								<span class="billable-badge--no">{{ formatDuration(nonBillableMinutes) }}</span>
							</td>
						</tr>
					</tfoot>
				</table>
			</div>
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ProjectActivityList',

	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
	},

	props: {
		projectId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			activities: [],
			tasks: [],
			project: null,
			filters: {
				dateFrom: '',
				dateTo: '',
				user: '',
				task: '',
				billable: '',
			},
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		loading() {
			return this.objectStore.loading.projectActivity || false
		},

		projectName() {
			return this.project ? this.project.name || t('pipelinq', 'Project') : t('pipelinq', 'Project')
		},

		/**
		 * Activities filtered by current filter values.
		 *
		 * @return {Array} Filtered activities sorted by date descending.
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.3
		 */
		filteredActivities() {
			return this.activities
				.filter(a => {
					if (this.filters.dateFrom && a.date < this.filters.dateFrom) return false
					if (this.filters.dateTo && a.date > this.filters.dateTo) return false
					if (this.filters.user && a.user !== this.filters.user) return false
					if (this.filters.task && a.task !== this.filters.task) return false
					if (this.filters.billable === 'yes' && !this.resolvedBillable(a)) return false
					if (this.filters.billable === 'no' && this.resolvedBillable(a)) return false
					return true
				})
				.sort((a, b) => (a.date > b.date ? -1 : 1))
		},

		/**
		 * Total minutes for filtered activities.
		 *
		 * @return {number} Total minutes.
		 */
		totalMinutes() {
			return this.filteredActivities.reduce((sum, a) => sum + (a.durationMinutes || 0), 0)
		},

		/**
		 * Billable minutes for filtered activities.
		 *
		 * @return {number} Billable minutes.
		 */
		billableMinutes() {
			return this.filteredActivities
				.filter(a => this.resolvedBillable(a))
				.reduce((sum, a) => sum + (a.durationMinutes || 0), 0)
		},

		/**
		 * Non-billable minutes for filtered activities.
		 *
		 * @return {number} Non-billable minutes.
		 */
		nonBillableMinutes() {
			return this.totalMinutes - this.billableMinutes
		},
	},

	async mounted() {
		await this.fetchData()
	},

	methods: {
		async fetchData() {
			try {
				this.project = await this.objectStore.fetchObject('project', this.projectId)
			} catch {
				this.project = null
			}

			try {
				this.tasks = await this.objectStore.fetchCollection('projectTask', {
					_limit: 200,
					project: this.projectId,
				}) || []
			} catch {
				this.tasks = []
			}

			try {
				this.activities = await this.objectStore.fetchCollection('projectActivity', {
					_limit: 1000,
					project: this.projectId,
				}) || []
			} catch {
				this.activities = []
			}
		},

		/**
		 * Resolve effective billable flag for an activity.
		 *
		 * @param {object} activity The activity object.
		 * @return {boolean} Resolved billable value.
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.4e
		 */
		resolvedBillable(activity) {
			if (activity.billable !== null && activity.billable !== undefined) return activity.billable
			const task = this.tasks.find(t => t.id === activity.task)
			if (task && task.billable !== null && task.billable !== undefined) return task.billable
			if (this.project && this.project.billable !== undefined) return this.project.billable !== false
			return true
		},

		/**
		 * Get task name by id.
		 *
		 * @param {string} taskId The task id.
		 * @return {string} Task name or placeholder.
		 */
		taskName(taskId) {
			const task = this.tasks.find(t => t.id === taskId)
			return task ? task.name : '-'
		},

		/**
		 * Format duration in minutes as "Xu Ymin".
		 *
		 * @param {number} minutes Duration in minutes.
		 * @return {string} Formatted duration.
		 * @spec openspec/changes/project-task-hierarchy/specs.md#scenario-15
		 */
		formatDuration(minutes) {
			if (!minutes) return '0u'
			const h = Math.floor(minutes / 60)
			const m = minutes % 60
			if (!m) return h + 'u'
			return h + 'u ' + m + 'min'
		},

		clearFilters() {
			this.filters = {
				dateFrom: '',
				dateTo: '',
				user: '',
				task: '',
				billable: '',
			}
		},
	},
}
</script>

<style scoped>
.filters-row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	align-items: flex-end;
}

.filter-field {
	display: flex;
	flex-direction: column;
}

.filter-field label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.filter-input {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 13px;
}

.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
}

.viewTable th,
.viewTable td {
	padding: 10px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	font-size: 13px;
}

.viewTable th {
	background: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.totals-row td {
	background: var(--color-background-dark);
	font-weight: bold;
}

.billable-badge--yes {
	display: inline-block;
	padding: 2px 6px;
	border-radius: 10px;
	background: #e8f5e9;
	color: #2e7d32;
	font-size: 11px;
}

.billable-badge--no {
	display: inline-block;
	padding: 2px 6px;
	border-radius: 10px;
	background: #fce4ec;
	color: #c62828;
	font-size: 11px;
}

.section-empty {
	padding: 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>
