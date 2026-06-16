<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  ProjectActivityList — table of every projectActivity (time entry) for
  one project. Filter controls let you narrow by date range, user, task
  and billable; a totals row sums billable / non-billable hours with
  inheritance applied (REQ-PTH-004 Scenario 18, REQ-PTH-005, REQ-PTH-008).

  Data comes from the projectActivity object store filtered by project
  UUID; phase/task metadata is loaded once so each row can show the
  task name and inheritance chain.
-->
<template>
	<div class="project-activity-list">
		<div class="project-activity-list__header">
			<NcButton @click="$router.push({ name: 'ProjectDetail', params: { id: projectId } })">
				{{ t('pipelinq', 'Terug naar project') }}
			</NcButton>
			<h2>
				{{ t('pipelinq', 'Tijdregistraties') }}
				<small v-if="projectData.name">— {{ projectData.name }}</small>
			</h2>
		</div>

		<div class="filters">
			<label>
				{{ t('pipelinq', 'Vanaf') }}
				<input v-model="filters.from" type="date">
			</label>
			<label>
				{{ t('pipelinq', 'Tot') }}
				<input v-model="filters.to" type="date">
			</label>
			<label>
				{{ t('pipelinq', 'Gebruiker') }}
				<input v-model="filters.user" type="text" :placeholder="t('pipelinq', 'UID')">
			</label>
			<label>
				{{ t('pipelinq', 'Taak') }}
				<select v-model="filters.task">
					<option value="">
						{{ t('pipelinq', 'Alle taken') }}
					</option>
					<option v-for="task in tasks" :key="task.id" :value="task.id">
						{{ task.name || task.id }}
					</option>
				</select>
			</label>
			<label>
				{{ t('pipelinq', 'Factureerbaar') }}
				<select v-model="filters.billable">
					<option value="">
						{{ t('pipelinq', 'Alle') }}
					</option>
					<option value="yes">
						{{ t('pipelinq', 'Alleen factureerbaar') }}
					</option>
					<option value="no">
						{{ t('pipelinq', 'Alleen niet-factureerbaar') }}
					</option>
				</select>
			</label>
		</div>

		<div v-if="loading" class="loading-state">
			{{ t('pipelinq', 'Laden…') }}
		</div>
		<div v-else-if="filteredActivities.length === 0" class="empty-state">
			{{ t('pipelinq', 'Geen tijdregistraties gevonden.') }}
		</div>
		<div v-else class="table-wrap">
			<table class="activity-table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Datum') }}</th>
						<th>{{ t('pipelinq', 'Gebruiker') }}</th>
						<th>{{ t('pipelinq', 'Taak') }}</th>
						<th>{{ t('pipelinq', 'Omschrijving') }}</th>
						<th class="numeric">
							{{ t('pipelinq', 'Duur') }}
						</th>
						<th>{{ t('pipelinq', 'Factureerbaar') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in filteredActivities" :key="row.id">
						<td>{{ formatDate(row.date) }}</td>
						<td>{{ row.user || '-' }}</td>
						<td>{{ taskName(row.task) }}</td>
						<td>{{ row.description || '-' }}</td>
						<td class="numeric">
							{{ formatDuration(row.durationMinutes) }}
						</td>
						<td>
							<span :class="['billable-dot', resolveBillable(row) ? 'billable-dot--on' : 'billable-dot--off']" />
							{{ resolveBillable(row) ? t('pipelinq', 'Factureerbaar') : t('pipelinq', 'Niet-factureerbaar') }}
						</td>
					</tr>
				</tbody>
				<tfoot>
					<tr>
						<td colspan="4" class="totals-label">
							{{ t('pipelinq', 'Totaal') }}
						</td>
						<td class="numeric">
							{{ formatHours(totals.total) }}
						</td>
						<td>
							{{ t('pipelinq', 'Factureerbaar') }}: {{ formatHours(totals.billable) }}
							·
							{{ t('pipelinq', 'Niet-factureerbaar') }}: {{ formatHours(totals.nonBillable) }}
						</td>
					</tr>
				</tfoot>
			</table>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ProjectActivityList',
	components: {
		NcButton,
	},
	props: {
		id: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			loading: true,
			activities: [],
			tasks: [],
			phases: [],
			projectData: {},
			filters: {
				from: '',
				to: '',
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
		projectId() {
			return this.id || (this.$route && this.$route.params && this.$route.params.id) || null
		},
		filteredActivities() {
			return this.activities.filter((row) => {
				if (this.filters.from && row.date && row.date < this.filters.from) return false
				if (this.filters.to && row.date && row.date > this.filters.to) return false
				if (this.filters.user && row.user && row.user.toLowerCase().indexOf(this.filters.user.toLowerCase()) === -1) return false
				if (this.filters.task && row.task !== this.filters.task) return false
				if (this.filters.billable === 'yes' && !this.resolveBillable(row)) return false
				if (this.filters.billable === 'no' && this.resolveBillable(row)) return false
				return true
			})
		},
		totals() {
			let billable = 0
			let nonBillable = 0
			for (const row of this.filteredActivities) {
				const minutes = Number(row.durationMinutes) || 0
				if (this.resolveBillable(row)) {
					billable += minutes
				} else {
					nonBillable += minutes
				}
			}
			return {
				total: this.minutesToHours(billable + nonBillable),
				billable: this.minutesToHours(billable),
				nonBillable: this.minutesToHours(nonBillable),
			}
		},
	},
	async mounted() {
		await this.loadAll()
	},
	methods: {
		async loadAll() {
			this.loading = true
			try {
				const [project, phases, tasks, activities] = await Promise.all([
					this.objectStore.fetchObject('project', this.projectId),
					this.objectStore.fetchCollection('projectPhase', { _limit: 200, project: this.projectId }),
					this.objectStore.fetchCollection('projectTask', { _limit: 500, project: this.projectId }),
					this.objectStore.fetchCollection('projectActivity', { _limit: 2000, project: this.projectId }),
				])
				this.projectData = project || {}
				this.phases = phases || []
				this.tasks = tasks || []
				this.activities = activities || []
			} finally {
				this.loading = false
			}
		},
		/**
		 * Resolved billable value for an activity walking activity → task →
		 * phase → project (REQ-PTH-005).
		 *
		 * @param {object} activity Activity row.
		 * @return {boolean}
		 */
		resolveBillable(activity) {
			if (activity && typeof activity.billable === 'boolean') {
				return activity.billable
			}
			const task = this.tasks.find(t => t.id === activity.task)
			if (task && typeof task.billable === 'boolean') {
				return task.billable
			}
			const phase = task ? this.phases.find(p => p.id === task.phase) : null
			if (phase && typeof phase.billable === 'boolean') {
				return phase.billable
			}
			if (this.projectData && typeof this.projectData.billable === 'boolean') {
				return this.projectData.billable
			}
			return true
		},
		taskName(taskId) {
			if (!taskId) return '-'
			const task = this.tasks.find(t => t.id === taskId)
			return task ? (task.name || taskId) : taskId
		},
		minutesToHours(minutes) {
			return Math.round((Number(minutes) / 60) * 10) / 10
		},
		formatHours(value) {
			const n = Number(value || 0)
			return n + 'u'
		},
		formatDuration(minutes) {
			const n = Number(minutes) || 0
			const h = Math.floor(n / 60)
			const m = n % 60
			if (h === 0) {
				return m + ' min'
			}
			if (m === 0) {
				return h + 'u'
			}
			return h + 'u ' + m + 'min'
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
.project-activity-list__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 12px;
}

.filters {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	padding: 8px 0 16px 0;
}

.filters label {
	display: flex;
	flex-direction: column;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.activity-table {
	width: 100%;
	border-collapse: collapse;
}

.activity-table th,
.activity-table td {
	padding: 8px 10px;
	border-bottom: 1px solid var(--color-border-dark);
	text-align: left;
}

.activity-table .numeric {
	text-align: right;
	font-variant-numeric: tabular-nums;
}

.activity-table tfoot td {
	font-weight: 600;
	border-top: 2px solid var(--color-border);
}

.totals-label {
	text-align: right;
}

.billable-dot {
	display: inline-block;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	margin-right: 6px;
	vertical-align: middle;
}

.billable-dot--on { background: #43a047; }

.billable-dot--off { background: #b0bec5; }

.loading-state,
.empty-state {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>
