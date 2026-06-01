<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->

<!--
  Project detail view.

  Shows a full project record with:
  - Header: name, client link, status badge, colour swatch, edit/delete actions
  - Budget summary cards: geplande uren, gelogde uren, factureerbaar, resterende uren
  - WBS tree section embedding ProjectWbsTree.vue
  - "Fase toevoegen" button opening a NcDialog for quick phase creation
  - "Taak toevoegen" dialog for quick task creation
  - "Tijdregistratie" dialog for logging a time entry
  - CnObjectSidebar via the host-provided objectSidebarState channel

  @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2
-->
<template>
	<div v-if="editing || isNew">
		<div class="project-detail__header">
			<NcButton @click="onFormCancel">
				{{ t('pipelinq', 'Terug naar lijst') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'Nieuw project') }}
			</h2>
			<h2 v-else>
				{{ projectData.name || t('pipelinq', 'Project') }}
			</h2>
		</div>
		<ProjectForm
			:project="projectData"
			@save="onFormSave"
			@cancel="onFormCancel" />
	</div>

	<CnDetailPage
		v-else
		:title="projectData.name || t('pipelinq', 'Project')"
		:subtitle="statusLabel(projectData.status)"
		:back-route="{ name: 'Projects' }"
		:back-label="t('pipelinq', 'Terug naar overzicht')"
		:loading="loading"
		:sidebar="!isNew && !loading"
		object-type="pipelinq_project"
		:object-id="projectId"
		:sidebar-props="sidebarProps">
		<template #actions>
			<NcButton type="primary" @click="editing = true">
				{{ t('pipelinq', 'Bewerken') }}
			</NcButton>
			<NcButton
				type="secondary"
				@click="$router.push({ name: 'ProjectActivityList', params: { id: projectId } })">
				{{ t('pipelinq', 'Tijdregistraties') }}
			</NcButton>
			<NcButton type="error" @click="showDeleteDialog = true">
				{{ t('pipelinq', 'Verwijderen') }}
			</NcButton>
		</template>

		<!-- Project information card -->
		<CnDetailCard :title="t('pipelinq', 'Projectinformatie')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Klant') }}</label>
					<span v-if="projectData.client" class="info-link" @click="navigateToClient">
						{{ clientName || projectData.client }}
					</span>
					<span v-else>-</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<span :class="['status-chip', 'status-chip--' + (projectData.status || 'open')]">
						{{ statusLabel(projectData.status) }}
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Factureerbaar') }}</label>
					<span :class="projectData.billable !== false ? 'billable--yes' : 'billable--no'">
						{{ projectData.billable !== false ? t('pipelinq', 'Ja') : t('pipelinq', 'Nee') }}
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Uurtarief') }}</label>
					<span>{{ projectData.hourlyRate ? '€ ' + projectData.hourlyRate : '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Startdatum') }}</label>
					<span>{{ projectData.startDate || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Einddatum') }}</label>
					<span>{{ projectData.endDate || '-' }}</span>
				</div>
				<div v-if="projectData.color" class="info-field">
					<label>{{ t('pipelinq', 'Kleur') }}</label>
					<span class="color-swatch" :style="{ background: projectData.color }">
						{{ projectData.color }}
					</span>
				</div>
			</div>
			<div v-if="projectData.description" class="info-field info-field--full">
				<label>{{ t('pipelinq', 'Beschrijving') }}</label>
				<p>{{ projectData.description }}</p>
			</div>
		</CnDetailCard>

		<!-- Budget summary cards -->
		<CnDetailCard :title="t('pipelinq', 'Budgetoverzicht')">
			<div class="budget-grid">
				<div class="budget-card">
					<span class="budget-value">{{ projectData.budgetHours || 0 }}u</span>
					<span class="budget-label">{{ t('pipelinq', 'Geplande uren') }}</span>
				</div>
				<div class="budget-card">
					<span
						:class="['budget-value', isOverBudget ? 'budget-value--warning' : '']">
						{{ formatHours(totalLoggedMinutes) }}
					</span>
					<span class="budget-label">
						{{ t('pipelinq', 'Gelogde uren') }}
						<span v-if="isOverBudget" class="budget-over">
							{{ overBudgetHours }}u {{ t('pipelinq', 'over budget') }}
						</span>
					</span>
				</div>
				<div class="budget-card">
					<span class="budget-value budget-value--billable">
						{{ formatHours(billableLoggedMinutes) }}
					</span>
					<span class="budget-label">{{ t('pipelinq', 'Factureerbaar') }}</span>
				</div>
				<div class="budget-card">
					<span class="budget-value budget-value--neutral">
						{{ formatHours(nonBillableLoggedMinutes) }}
					</span>
					<span class="budget-label">{{ t('pipelinq', 'Niet-factureerbaar') }}</span>
				</div>
				<div class="budget-card">
					<span :class="['budget-value', remainingHours < 0 ? 'budget-value--warning' : 'budget-value--remaining']">
						{{ remainingHours }}u
					</span>
					<span class="budget-label">{{ t('pipelinq', 'Resterende uren') }}</span>
				</div>
			</div>
		</CnDetailCard>

		<!-- WBS Tree -->
		<CnDetailCard :title="t('pipelinq', 'Werk-breakdown structuur')">
			<template #actions>
				<NcButton type="primary" @click="openAddPhaseDialog">
					{{ t('pipelinq', 'Fase toevoegen') }}
				</NcButton>
			</template>

			<ProjectWbsTree
				:project="projectData"
				:phases="phases"
				:tasks="tasks"
				:activities="activities"
				@add-task="openAddTaskDialog"
				@add-phase="openAddPhaseDialog"
				@log-activity="openLogActivityDialog" />
		</CnDetailCard>

		<!-- Delete warning dialog -->
		<NcDialog
			v-if="showDeleteDialog"
			:name="t('pipelinq', 'Project verwijderen')"
			@closing="showDeleteDialog = false">
			<p>
				{{ t('pipelinq', 'Weet je zeker dat je "{name}" wilt verwijderen?', { name: projectData.name }) }}
			</p>
			<template #actions>
				<NcButton @click="showDeleteDialog = false">
					{{ t('pipelinq', 'Annuleren') }}
				</NcButton>
				<NcButton type="error" @click="confirmDelete">
					{{ t('pipelinq', 'Verwijderen') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Add Phase dialog -->
		<NcDialog
			v-if="showAddPhaseDialog"
			:name="t('pipelinq', 'Fase toevoegen')"
			size="normal"
			@closing="showAddPhaseDialog = false">
			<div class="dialog-form">
				<div class="form-field">
					<label for="phase-name">{{ t('pipelinq', 'Naam') }} *</label>
					<input
						id="phase-name"
						v-model="newPhase.name"
						type="text"
						class="form-input"
						:placeholder="t('pipelinq', 'Naam van de fase')" />
				</div>
				<div class="form-field">
					<label for="phase-description">{{ t('pipelinq', 'Beschrijving') }}</label>
					<textarea
						id="phase-description"
						v-model="newPhase.description"
						class="form-input"
						:placeholder="t('pipelinq', 'Doel van de fase')" />
				</div>
				<div class="form-row">
					<div class="form-field">
						<label for="phase-order">{{ t('pipelinq', 'Volgorde') }}</label>
						<input
							id="phase-order"
							v-model.number="newPhase.order"
							type="number"
							class="form-input"
							min="1" />
					</div>
					<div class="form-field">
						<label for="phase-status">{{ t('pipelinq', 'Status') }}</label>
						<select id="phase-status" v-model="newPhase.status" class="form-input">
							<option value="open">
								{{ t('pipelinq', 'Open') }}
							</option>
							<option value="in_progress">
								{{ t('pipelinq', 'In uitvoering') }}
							</option>
							<option value="completed">
								{{ t('pipelinq', 'Voltooid') }}
							</option>
							<option value="cancelled">
								{{ t('pipelinq', 'Geannuleerd') }}
							</option>
						</select>
					</div>
				</div>
				<div class="form-row">
					<div class="form-field">
						<label for="phase-start">{{ t('pipelinq', 'Startdatum') }}</label>
						<input
							id="phase-start"
							v-model="newPhase.startDate"
							type="date"
							class="form-input" />
					</div>
					<div class="form-field">
						<label for="phase-end">{{ t('pipelinq', 'Einddatum') }}</label>
						<input
							id="phase-end"
							v-model="newPhase.endDate"
							type="date"
							class="form-input" />
					</div>
				</div>
			</div>
			<template #actions>
				<NcButton @click="showAddPhaseDialog = false">
					{{ t('pipelinq', 'Annuleren') }}
				</NcButton>
				<NcButton type="primary" :disabled="!newPhase.name" @click="savePhase">
					{{ t('pipelinq', 'Opslaan') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Add Task dialog -->
		<NcDialog
			v-if="showAddTaskDialog"
			:name="t('pipelinq', 'Taak toevoegen')"
			size="normal"
			@closing="showAddTaskDialog = false">
			<div class="dialog-form">
				<div class="form-field">
					<label for="task-name">{{ t('pipelinq', 'Naam') }} *</label>
					<input
						id="task-name"
						v-model="newTask.name"
						type="text"
						class="form-input"
						:placeholder="t('pipelinq', 'Naam van de taak')" />
				</div>
				<div class="form-field">
					<label for="task-description">{{ t('pipelinq', 'Beschrijving') }}</label>
					<textarea
						id="task-description"
						v-model="newTask.description"
						class="form-input"
						:placeholder="t('pipelinq', 'Beschrijving van de taak')" />
				</div>
				<div class="form-row">
					<div class="form-field">
						<label for="task-estimated">{{ t('pipelinq', 'Geschatte uren') }}</label>
						<input
							id="task-estimated"
							v-model.number="newTask.estimatedHours"
							type="number"
							class="form-input"
							min="0"
							step="0.5" />
					</div>
					<div class="form-field">
						<label for="task-deadline">{{ t('pipelinq', 'Deadline') }}</label>
						<input
							id="task-deadline"
							v-model="newTask.deadline"
							type="date"
							class="form-input" />
					</div>
				</div>
				<div class="form-field">
					<label for="task-assignee">{{ t('pipelinq', 'Verantwoordelijke') }}</label>
					<input
						id="task-assignee"
						v-model="newTask.assignee"
						type="text"
						class="form-input"
						:placeholder="t('pipelinq', 'Nextcloud gebruikersnaam')" />
				</div>
			</div>
			<template #actions>
				<NcButton @click="showAddTaskDialog = false">
					{{ t('pipelinq', 'Annuleren') }}
				</NcButton>
				<NcButton type="primary" :disabled="!newTask.name" @click="saveTask">
					{{ t('pipelinq', 'Opslaan') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Log Activity dialog -->
		<NcDialog
			v-if="showLogActivityDialog"
			:name="t('pipelinq', 'Tijdregistratie')"
			size="normal"
			@closing="showLogActivityDialog = false">
			<div class="dialog-form">
				<div class="form-field">
					<label for="activity-date">{{ t('pipelinq', 'Datum') }} *</label>
					<input
						id="activity-date"
						v-model="newActivity.date"
						type="date"
						class="form-input" />
				</div>
				<div class="form-field">
					<label for="activity-duration">{{ t('pipelinq', 'Duur (minuten)') }} *</label>
					<input
						id="activity-duration"
						v-model.number="newActivity.durationMinutes"
						type="number"
						class="form-input"
						min="1" />
				</div>
				<div class="form-field">
					<label for="activity-description">{{ t('pipelinq', 'Beschrijving') }}</label>
					<textarea
						id="activity-description"
						v-model="newActivity.description"
						class="form-input"
						:placeholder="t('pipelinq', 'Uitgevoerd werk')" />
				</div>
				<div class="form-row">
					<div class="form-field">
						<label for="activity-user">{{ t('pipelinq', 'Gebruiker') }} *</label>
						<input
							id="activity-user"
							v-model="newActivity.user"
							type="text"
							class="form-input"
							:placeholder="t('pipelinq', 'Nextcloud gebruikersnaam')" />
					</div>
					<div class="form-field">
						<label for="activity-rate">{{ t('pipelinq', 'Uurtarief (EUR)') }}</label>
						<input
							id="activity-rate"
							v-model.number="newActivity.hourlyRate"
							type="number"
							class="form-input"
							min="0"
							step="0.01" />
					</div>
				</div>
			</div>
			<template #actions>
				<NcButton @click="showLogActivityDialog = false">
					{{ t('pipelinq', 'Annuleren') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!newActivity.date || !newActivity.durationMinutes || !newActivity.user"
					@click="saveActivity">
					{{ t('pipelinq', 'Opslaan') }}
				</NcButton>
			</template>
		</NcDialog>
	</CnDetailPage>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import ProjectWbsTree from '../../components/ProjectWbsTree.vue'
import ProjectForm from './ProjectForm.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ProjectDetail',

	components: {
		NcButton,
		NcDialog,
		CnDetailPage,
		CnDetailCard,
		ProjectWbsTree,
		ProjectForm,
	},

	props: {
		projectId: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			editing: false,
			phases: [],
			tasks: [],
			activities: [],
			clientData: null,
			showDeleteDialog: false,
			showAddPhaseDialog: false,
			showAddTaskDialog: false,
			showLogActivityDialog: false,
			activePhaseId: null,
			activeTaskId: null,
			newPhase: this.emptyPhase(),
			newTask: this.emptyTask(),
			newActivity: this.emptyActivity(),
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2
		 */
		objectStore() {
			return useObjectStore()
		},

		isNew() {
			return !this.projectId || this.projectId === 'new'
		},

		loading() {
			return this.objectStore.loading.project || false
		},

		/**
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2a
		 */
		projectData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('project', this.projectId) || {}
		},

		/**
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2e
		 */
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.project || {}
			return {
				title: t('pipelinq', 'Project'),
				register: config.register || '',
				schema: config.schema || '',
			}
		},

		clientName() {
			if (!this.clientData) return null
			return this.clientData.name || null
		},

		/**
		 * Total logged minutes across all activities in this project.
		 *
		 * @return {number} Total minutes logged.
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2b
		 */
		totalLoggedMinutes() {
			return this.activities.reduce((sum, a) => sum + (a.durationMinutes || 0), 0)
		},

		/**
		 * Billable logged minutes (resolved billable = true).
		 *
		 * @return {number} Billable minutes.
		 */
		billableLoggedMinutes() {
			return this.activities
				.filter(a => this.resolveActivityBillable(a))
				.reduce((sum, a) => sum + (a.durationMinutes || 0), 0)
		},

		/**
		 * Non-billable logged minutes.
		 *
		 * @return {number} Non-billable minutes.
		 */
		nonBillableLoggedMinutes() {
			return this.totalLoggedMinutes - this.billableLoggedMinutes
		},

		/**
		 * Remaining hours: budgetHours - logged hours.
		 *
		 * @return {number} Remaining hours (may be negative).
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2b
		 */
		remainingHours() {
			const budget = this.projectData.budgetHours || 0
			const logged = this.totalLoggedMinutes / 60
			return Math.round((budget - logged) * 10) / 10
		},

		/**
		 * Whether the project has exceeded its hour budget.
		 *
		 * @return {boolean} True when over budget.
		 * @spec openspec/changes/project-task-hierarchy/specs.md#scenario-31
		 */
		isOverBudget() {
			if (!this.projectData.budgetHours) return false
			return this.totalLoggedMinutes / 60 > this.projectData.budgetHours
		},

		/**
		 * Hours over budget when over budget.
		 *
		 * @return {number} Hours over budget.
		 */
		overBudgetHours() {
			if (!this.isOverBudget) return 0
			return Math.round((this.totalLoggedMinutes / 60 - this.projectData.budgetHours) * 10) / 10
		},
	},

	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('project', this.projectId)
			await this.fetchRelated()
		}
	},

	methods: {
		emptyPhase() {
			return {
				name: '',
				description: '',
				order: (this.phases || []).length + 1,
				status: 'open',
				startDate: '',
				endDate: '',
			}
		},

		emptyTask() {
			return {
				name: '',
				description: '',
				estimatedHours: null,
				deadline: '',
				assignee: '',
				status: 'open',
			}
		},

		emptyActivity() {
			return {
				date: new Date().toISOString().split('T')[0],
				durationMinutes: 60,
				description: '',
				user: '',
				hourlyRate: this.projectData ? this.projectData.hourlyRate : null,
			}
		},

		/**
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2
		 */
		async onFormSave(formData) {
			const result = await this.objectStore.saveObject('project', formData)
			if (result) {
				if (this.isNew) {
					this.$router.push({ name: 'ProjectDetail', params: { id: result.id } })
				} else {
					await this.objectStore.fetchObject('project', this.projectId)
					this.editing = false
				}
			} else {
				const error = this.objectStore.getError('project')
				showError(error?.message || t('pipelinq', 'Kon project niet opslaan. Probeer het opnieuw.'))
			}
		},

		onFormCancel() {
			if (this.isNew) {
				this.$router.push({ name: 'Projects' })
			} else {
				this.editing = false
			}
		},

		async confirmDelete() {
			this.showDeleteDialog = false
			const success = await this.objectStore.deleteObject('project', this.projectId)
			if (success) {
				this.$router.push({ name: 'Projects' })
			} else {
				const error = this.objectStore.getError('project')
				showError(error?.message || t('pipelinq', 'Kon project niet verwijderen.'))
			}
		},

		/**
		 * Fetch phases, tasks, activities and client data for this project.
		 *
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2c
		 */
		async fetchRelated() {
			try {
				this.phases = await this.objectStore.fetchCollection('projectPhase', {
					_limit: 100,
					project: this.projectId,
					_order: { order: 'asc' },
				}) || []
			} catch {
				this.phases = []
			}

			try {
				this.tasks = await this.objectStore.fetchCollection('projectTask', {
					_limit: 200,
					project: this.projectId,
					_order: { order: 'asc' },
				}) || []
			} catch {
				this.tasks = []
			}

			try {
				this.activities = await this.objectStore.fetchCollection('projectActivity', {
					_limit: 500,
					project: this.projectId,
				}) || []
			} catch {
				this.activities = []
			}

			if (this.projectData.client) {
				try {
					this.clientData = await this.objectStore.fetchObject('client', this.projectData.client)
				} catch {
					this.clientData = null
				}
			}
		},

		/**
		 * Resolve billable for an activity (used for budget calculations).
		 *
		 * @param {object} activity The activity object.
		 * @return {boolean} The resolved billable value.
		 */
		resolveActivityBillable(activity) {
			if (activity.billable !== null && activity.billable !== undefined) return activity.billable
			const task = this.tasks.find(t => t.id === activity.task)
			if (task) {
				if (task.billable !== null && task.billable !== undefined) return task.billable
				const phase = this.phases.find(p => p.id === task.phase)
				if (phase) {
					if (phase.billable !== null && phase.billable !== undefined) return phase.billable
				}
			}
			return this.projectData.billable !== false
		},

		/**
		 * Format minutes as "Xu" or "Xu Ymin".
		 *
		 * @param {number} minutes Total minutes.
		 * @return {string} Formatted string.
		 */
		formatHours(minutes) {
			if (!minutes) return '0u'
			const h = Math.floor(minutes / 60)
			const m = minutes % 60
			if (!m) return h + 'u'
			return h + 'u ' + m + 'min'
		},

		/**
		 * @spec openspec/changes/project-task-hierarchy/specs.md#scenario-33
		 */
		navigateToClient() {
			if (this.projectData.client) {
				this.$router.push({ name: 'ClientDetail', params: { id: this.projectData.client } })
			}
		},

		/**
		 * Open add phase dialog.
		 *
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2d
		 */
		openAddPhaseDialog() {
			this.newPhase = this.emptyPhase()
			this.showAddPhaseDialog = true
		},

		/**
		 * Save a new phase.
		 *
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2d
		 */
		async savePhase() {
			const phaseData = {
				...this.newPhase,
				project: this.projectId,
			}
			const result = await this.objectStore.saveObject('projectPhase', phaseData)
			if (result) {
				this.showAddPhaseDialog = false
				showSuccess(t('pipelinq', 'Fase toegevoegd'))
				await this.fetchRelated()
			} else {
				const error = this.objectStore.getError('projectPhase')
				showError(error?.message || t('pipelinq', 'Kon fase niet opslaan. Probeer het opnieuw.'))
			}
		},

		/**
		 * Open add task dialog for a given phase.
		 *
		 * @param {string} phaseId The phase to add the task to.
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2d
		 */
		openAddTaskDialog(phaseId) {
			this.activePhaseId = phaseId
			this.newTask = this.emptyTask()
			this.showAddTaskDialog = true
		},

		/**
		 * Save a new task.
		 *
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2d
		 */
		async saveTask() {
			const taskData = {
				...this.newTask,
				phase: this.activePhaseId,
				project: this.projectId,
			}
			const result = await this.objectStore.saveObject('projectTask', taskData)
			if (result) {
				this.showAddTaskDialog = false
				showSuccess(t('pipelinq', 'Taak toegevoegd'))
				await this.fetchRelated()
			} else {
				const error = this.objectStore.getError('projectTask')
				showError(error?.message || t('pipelinq', 'Kon taak niet opslaan. Probeer het opnieuw.'))
			}
		},

		/**
		 * Open log activity dialog for a given task.
		 *
		 * @param {string} taskId The task to log time against.
		 */
		openLogActivityDialog(taskId) {
			this.activeTaskId = taskId
			this.newActivity = this.emptyActivity()
			this.showLogActivityDialog = true
		},

		/**
		 * Save a new time entry (activity).
		 *
		 * @spec openspec/changes/project-task-hierarchy/tasks.md#task-3.2d
		 */
		async saveActivity() {
			const activityData = {
				...this.newActivity,
				task: this.activeTaskId,
				project: this.projectId,
			}
			const result = await this.objectStore.saveObject('projectActivity', activityData)
			if (result) {
				this.showLogActivityDialog = false
				showSuccess(t('pipelinq', 'Tijdregistratie opgeslagen'))
				await this.fetchRelated()
			} else {
				const error = this.objectStore.getError('projectActivity')
				showError(error?.message || t('pipelinq', 'Kon tijdregistratie niet opslaan. Probeer het opnieuw.'))
			}
		},

		/**
		 * Human-readable label for a status value.
		 *
		 * @param {string} status The status.
		 * @return {string} Translated label.
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
.project-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
	padding: 20px 20px 0;
}

.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.info-field {
	margin-bottom: 8px;
}

.info-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.info-field span,
.info-field p {
	margin: 0;
}

.info-field--full {
	margin-top: 16px;
	grid-column: 1 / -1;
}

.info-link {
	color: var(--color-primary);
	cursor: pointer;
	text-decoration: underline;
}

.status-chip {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 500;
}

.status-chip--open { background: #e3f2fd; color: #1565c0; }
.status-chip--in_progress { background: #fff8e1; color: #f57f17; }
.status-chip--completed { background: #e8f5e9; color: #2e7d32; }
.status-chip--cancelled { background: #fce4ec; color: #c62828; }
.status-chip--on_hold { background: #f3e5f5; color: #6a1b9a; }

.billable--yes { color: #2e7d32; }
.billable--no { color: #c62828; }

.color-swatch {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 4px;
	color: white;
	font-size: 12px;
	font-weight: 600;
}

.budget-grid {
	display: grid;
	grid-template-columns: repeat(5, 1fr);
	gap: 12px;
}

@media (max-width: 800px) {
	.budget-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}

.budget-card {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
	text-align: center;
}

.budget-value {
	font-size: 22px;
	font-weight: bold;
	color: var(--color-main-text);
}

.budget-value--warning {
	color: #e53935;
}

.budget-value--billable {
	color: #2e7d32;
}

.budget-value--neutral {
	color: var(--color-text-maxcontrast);
}

.budget-value--remaining {
	color: var(--color-primary);
}

.budget-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
	display: flex;
	flex-direction: column;
	align-items: center;
}

.budget-over {
	display: block;
	color: #e53935;
	font-size: 11px;
}

.dialog-form {
	padding: 8px 0;
}

.form-field {
	margin-bottom: 12px;
}

.form-field label {
	display: block;
	font-weight: 500;
	margin-bottom: 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.form-input {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
	box-sizing: border-box;
}

.form-input:focus {
	outline: none;
	border-color: var(--color-primary);
}

.form-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}
</style>
