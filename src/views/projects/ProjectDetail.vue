<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Project detail surface — the entry point into the WBS for one project.
  Renders header info, budget KPI cards, the collapsible phase/task tree
  (delegated to ProjectWbsTree) and a tabbed sidebar (files / notes / audit
  via CnObjectSidebar). The "Fase toevoegen" / "Taak toevoegen" /
  "Tijdregistratie" actions emitted by the tree open a CnFormDialog that
  saves through the ordinary object store — no bespoke controller is
  needed (REQ-PTH-002, REQ-PTH-003, REQ-PTH-004, REQ-PTH-007, REQ-PTH-008).
-->
<template>
	<div v-if="editing || isNew">
		<div class="project-detail__header">
			<NcButton @click="cancelEdit">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New project') }}
			</h2>
			<h2 v-else>
				{{ projectData.name || t('pipelinq', 'Project') }}
			</h2>
		</div>
		<CnFormDialog
			v-if="showProjectForm"
			ref="projectForm"
			:dialog-title="isNew ? t('pipelinq', 'New project') : t('pipelinq', 'Edit project')"
			:fields="projectFields"
			:initial-data="projectFormInitial"
			:confirm-label="t('pipelinq', 'Save')"
			:cancel-label="t('pipelinq', 'Cancel')"
			name-field="name"
			@confirm="onProjectSaved"
			@close="cancelEdit" />
	</div>

	<CnDetailPage
		v-else
		:title="projectData.name || t('pipelinq', 'Project')"
		:subtitle="t('pipelinq', 'Project')"
		:back-route="{ name: 'Projects' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="{ enabled: !isNew && !loading }"
		object-type="pipelinq_project"
		:object-id="projectId"
		:sidebar-props="sidebarProps">
		<template #actions>
			<NcButton variant="primary" @click="startEdit">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton @click="goToActivities">
				{{ t('pipelinq', 'Time entries') }}
			</NcButton>
			<NcButton variant="error" @click="confirmDelete">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Project details')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Client') }}</label>
					<router-link
						v-if="projectData.client"
						class="client-link"
						:to="{ name: 'ClientDetail', params: { id: projectData.client } }">
						{{ clientName }}
					</router-link>
					<span v-else>-</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<span class="status-pill" :class="'status-pill--' + (projectData.status || 'open')">
						{{ statusLabel(projectData.status) }}
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Billable') }}</label>
					<span>
						<span :class="['billable-dot', projectData.billable === false ? 'billable-dot--off' : 'billable-dot--on']" />
						{{ projectData.billable === false ? t('pipelinq', 'Non-billable') : t('pipelinq', 'Billable') }}
					</span>
				</div>
				<div v-if="projectData.color" class="info-field">
					<label>{{ t('pipelinq', 'Color') }}</label>
					<span class="color-swatch" :style="{ backgroundColor: projectData.color }" />
					<span>{{ projectData.color }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Start date') }}</label>
					<span>{{ formatDate(projectData.startDate) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'End date') }}</label>
					<span>{{ formatDate(projectData.endDate) }}</span>
				</div>
				<div class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Description') }}</label>
					<p>{{ projectData.description || '-' }}</p>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Budget')">
			<div class="kpi-grid">
				<div class="kpi-card">
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Planned hours') }}
					</div>
					<div class="kpi-card__value">
						{{ formatHours(plannedHours) }}
					</div>
				</div>
				<div class="kpi-card">
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Logged hours') }}
					</div>
					<div class="kpi-card__value" :class="{ 'kpi-card__value--warn': overBudget }">
						{{ formatHours(loggedHours) }}
						<small v-if="overBudget">
							/ {{ formatHours(plannedHours) }}
							({{ Math.round(loggedHours - plannedHours) }} {{ t('pipelinq', 'hours over budget') }})
						</small>
					</div>
				</div>
				<div class="kpi-card">
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Billable') }}
					</div>
					<div class="kpi-card__value">
						{{ formatHours(billableHours) }}
					</div>
				</div>
				<div class="kpi-card">
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Non-billable') }}
					</div>
					<div class="kpi-card__value">
						{{ formatHours(nonBillableHours) }}
					</div>
				</div>
				<div class="kpi-card">
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Remaining hours') }}
					</div>
					<div class="kpi-card__value">
						{{ formatHours(Math.max(plannedHours - loggedHours, 0)) }}
					</div>
				</div>
				<div v-if="projectData.budgetAmount" class="kpi-card">
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Budget amount') }}
					</div>
					<div class="kpi-card__value">
						{{ formatEur(projectData.budgetAmount) }}
					</div>
				</div>
			</div>
		</CnDetailCard>

		<!-- Shillinq Ledger card (REQ-PLG-005) — only rendered when the admin
		     has configured shillinq_ledger_webhook_url. Sits above the WBS so
		     finance-conscious users see sync state without scrolling. -->
		<CnDetailCard
			v-if="ledgerWebhookConfigured"
			:title="t('pipelinq', 'Shillinq Ledger')">
			<div class="ledger-card">
				<div class="ledger-card__row">
					<label class="ledger-card__label">
						{{ t('pipelinq', 'Status') }}
					</label>
					<span v-if="!ledgerSyncStatus" class="ledger-card__dash">-</span>
					<span
						v-else
						class="ledger-card__pill"
						:class="ledgerPillClass(ledgerSyncStatus)">
						{{ ledgerStatusLabel(ledgerSyncStatus) }}
					</span>
				</div>
				<div class="ledger-card__row">
					<label class="ledger-card__label">
						{{ t('pipelinq', 'Last synced') }}
					</label>
					<span>{{ formatDateTime(ledgerSyncedAt) }}</span>
				</div>
				<div v-if="ledgerSyncStatus === 'failed'" class="ledger-card__row ledger-card__row--actions">
					<NcButton
						variant="primary"
						:disabled="ledgerRetrying"
						@click="retryLedgerSync">
						{{ ledgerRetrying ? t('pipelinq', 'Retrying...') : t('pipelinq', 'Retry Sync') }}
					</NcButton>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Workload distribution')">
			<ProjectWbsTree
				:project="projectData"
				:phases="phases"
				:tasks="tasks"
				:activities="activities"
				@add-phase="openPhaseDialog()"
				@add-task="openTaskDialog($event.phase)"
				@add-activity="openActivityDialog($event.task, $event.phase)" />
		</CnDetailCard>

		<!-- Fase dialog -->
		<CnFormDialog
			v-if="showPhaseDialog"
			ref="phaseDialog"
			:dialog-title="t('pipelinq', 'Add phase')"
			:fields="phaseFields"
			:initial-data="phaseInitial"
			:confirm-label="t('pipelinq', 'Save')"
			:cancel-label="t('pipelinq', 'Cancel')"
			name-field="name"
			@confirm="onPhaseSaved"
			@close="showPhaseDialog = false" />

		<!-- Taak dialog -->
		<CnFormDialog
			v-if="showTaskDialog"
			ref="taskDialog"
			:dialog-title="t('pipelinq', 'Add task')"
			:fields="taskFields"
			:initial-data="taskInitial"
			:confirm-label="t('pipelinq', 'Save')"
			:cancel-label="t('pipelinq', 'Cancel')"
			name-field="name"
			@confirm="onTaskSaved"
			@close="showTaskDialog = false" />

		<!-- Tijdregistratie dialog -->
		<CnFormDialog
			v-if="showActivityDialog"
			ref="activityDialog"
			:dialog-title="t('pipelinq', 'Time entry')"
			:fields="activityFields"
			:initial-data="activityInitial"
			:confirm-label="t('pipelinq', 'Save')"
			:cancel-label="t('pipelinq', 'Cancel')"
			name-field="description"
			@confirm="onActivitySaved"
			@close="showActivityDialog = false" />
	</CnDetailPage>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { computed } from 'vue'
import { CnDetailPage, CnDetailCard, CnFormDialog, useObjectSubscription } from '@conduction/nextcloud-vue'
import ProjectWbsTree from '../../components/ProjectWbsTree.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ProjectDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnFormDialog,
		ProjectWbsTree,
	},
	props: {
		id: {
			type: String,
			default: null,
		},
		projectIdProp: {
			type: String,
			default: null,
		},
	},
	/**
	 * Live updates for the viewed project (nc-vue liveUpdatesPlugin,
	 * default-on since beta.212): subscribe to or-object-{uuid}. Events
	 * are refetch hints — the plugin re-runs fetchObject('project', id)
	 * into the same store cache this view renders from (projectData →
	 * objectStore.getObject), so no extra bridging is needed. The
	 * composable re-scopes on id change and releases on unmount; the
	 * enabled gate waits for the (bootstrap-time) type registration and
	 * skips the create archetype.
	 *
	 * @param {object} props Component props
	 * @return {object} Empty — the subscription is side-effect only
	 * @spec openspec/specs/realtime-updates-ui/spec.md
	 */
	setup(props) {
		const objectStore = useObjectStore()
		const liveObjectId = computed(() => {
			const id = props.id || props.projectIdProp
			return id && id !== 'new' ? id : null
		})
		useObjectSubscription(objectStore, 'project', liveObjectId, {
			enabled: computed(() => Boolean(liveObjectId.value && objectStore.objectTypeRegistry.project)),
		})
		return {}
	},
	data() {
		return {
			editing: false,
			showProjectForm: false,
			showPhaseDialog: false,
			showTaskDialog: false,
			showActivityDialog: false,
			phaseInitial: {},
			taskInitial: {},
			activityInitial: {},
			phases: [],
			tasks: [],
			activities: [],
			clientName: '-',
			availableClients: [],
			// Shillinq ledger integration (REQ-PLG-005).
			ledgerWebhookConfigured: false,
			ledgerRetrying: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		projectId() {
			return this.id || this.projectIdProp || null
		},
		isNew() {
			return !this.projectId || this.projectId === 'new'
		},
		loading() {
			return this.objectStore.loading.project || false
		},
		projectData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('project', this.projectId) || {}
		},
		projectFormInitial() {
			if (this.isNew) {
				return {
					status: 'open',
					billable: true,
				}
			}
			return { ...this.projectData }
		},
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.project || {}
			return {
				title: t('pipelinq', 'Project'),
				register: config.register || '',
				schema: config.schema || '',
				hiddenTabs: ['tasks'],
			}
		},
		/**
		 * CnFormDialog fields for creating / editing a project. Mirrors the
		 * schema in lib/Settings/register.d/60-project-ledger.json so the
		 * dialog matches the required + optional set without forking the
		 * schema (REQ-PTH-001).
		 *
		 * @return {Array<object>}
		 */
		projectFields() {
			return [
				{ key: 'name', label: t('pipelinq', 'Name'), widget: 'text', required: true },
				{ key: 'client', label: t('pipelinq', 'Client'), widget: 'select', enum: this.loadClientOptions },
				{ key: 'description', label: t('pipelinq', 'Description'), widget: 'textarea' },
				{ key: 'status', label: t('pipelinq', 'Status'), widget: 'select', options: this.statusOptions },
				{ key: 'billable', label: t('pipelinq', 'Billable'), widget: 'checkbox' },
				{ key: 'budgetHours', label: t('pipelinq', 'Budget hours'), widget: 'number' },
				{ key: 'budgetAmount', label: t('pipelinq', 'Budget amount (EUR)'), widget: 'number' },
				{ key: 'hourlyRate', label: t('pipelinq', 'Hourly rate'), widget: 'number' },
				{ key: 'startDate', label: t('pipelinq', 'Start date'), widget: 'date' },
				{ key: 'endDate', label: t('pipelinq', 'End date'), widget: 'date' },
				{ key: 'color', label: t('pipelinq', 'Color (hex)'), widget: 'text' },
			]
		},
		phaseFields() {
			return [
				{ key: 'name', label: t('pipelinq', 'Name'), widget: 'text', required: true },
				{ key: 'description', label: t('pipelinq', 'Description'), widget: 'textarea' },
				{ key: 'status', label: t('pipelinq', 'Status'), widget: 'select', options: this.statusOptions },
				{ key: 'billable', label: t('pipelinq', 'Billable (leave empty to inherit)'), widget: 'checkbox' },
				{ key: 'budgetHours', label: t('pipelinq', 'Budget hours'), widget: 'number' },
				{ key: 'sequence', label: t('pipelinq', 'Order'), widget: 'number' },
				{ key: 'startDate', label: t('pipelinq', 'Start date'), widget: 'date' },
				{ key: 'endDate', label: t('pipelinq', 'End date'), widget: 'date' },
			]
		},
		taskFields() {
			return [
				{ key: 'name', label: t('pipelinq', 'Name'), widget: 'text', required: true },
				{ key: 'description', label: t('pipelinq', 'Description'), widget: 'textarea' },
				{ key: 'status', label: t('pipelinq', 'Status'), widget: 'select', options: this.statusOptions },
				{ key: 'billable', label: t('pipelinq', 'Billable (leave empty to inherit)'), widget: 'checkbox' },
				{ key: 'estimatedHours', label: t('pipelinq', 'Estimated hours'), widget: 'number' },
				{ key: 'assignee', label: t('pipelinq', 'Assigned to (user UID)'), widget: 'text' },
				{ key: 'deadline', label: t('pipelinq', 'Deadline'), widget: 'date' },
				{ key: 'sequence', label: t('pipelinq', 'Order'), widget: 'number' },
			]
		},
		activityFields() {
			return [
				{ key: 'date', label: t('pipelinq', 'Date'), widget: 'date', required: true },
				{ key: 'durationMinutes', label: t('pipelinq', 'Duration (minutes)'), widget: 'number', required: true },
				{ key: 'description', label: t('pipelinq', 'Description'), widget: 'textarea' },
				{ key: 'user', label: t('pipelinq', 'User (UID)'), widget: 'text', required: true },
				{ key: 'billable', label: t('pipelinq', 'Billable (leave empty to inherit)'), widget: 'checkbox' },
				{ key: 'hourlyRate', label: t('pipelinq', 'Hourly rate override'), widget: 'number' },
			]
		},
		statusOptions() {
			return [
				{ value: 'open', label: t('pipelinq', 'Open') },
				{ value: 'in_progress', label: t('pipelinq', 'Running') },
				{ value: 'on_hold', label: t('pipelinq', 'Paused') },
				{ value: 'completed', label: t('pipelinq', 'Completed') },
				{ value: 'cancelled', label: t('pipelinq', 'Cancelled') },
			]
		},
		/**
		 * Sum of logged hours across all project activities (REQ-PTH-007
		 * Scenario 27 / REQ-PTH-008 Scenario 31).
		 *
		 * @return {number}
		 */
		loggedHours() {
			const minutes = this.activities.reduce((sum, a) => sum + (Number(a.durationMinutes) || 0), 0)
			return Math.round((minutes / 60) * 10) / 10
		},
		/**
		 * Hours marked billable (with task/phase/project inheritance
		 * applied) — REQ-PTH-008 Scenario 32.
		 *
		 * @return {number}
		 */
		billableHours() {
			return this.computeBillableHours(true)
		},
		/**
		 * Hours marked non-billable (with inheritance applied).
		 *
		 * @return {number}
		 */
		nonBillableHours() {
			return this.computeBillableHours(false)
		},
		plannedHours() {
			return Number(this.projectData.budgetHours || 0)
		},
		overBudget() {
			return this.plannedHours > 0 && this.loggedHours > this.plannedHours
		},
		/**
		 * Convenience accessor for the ledger sync status on the project
		 * payload (REQ-PLG-005). The shared object store preserves this
		 * field on every fetch / save round-trip.
		 *
		 * @return {string|null}
		 */
		ledgerSyncStatus() {
			return this.projectData.ledgerSyncStatus || null
		},
		/**
		 * ISO timestamp of the last successful ledger dispatch.
		 *
		 * @return {string|null}
		 */
		ledgerSyncedAt() {
			return this.projectData.ledgerSyncedAt || null
		},
	},
	async mounted() {
		if (this.isNew) {
			this.showProjectForm = true
			this.editing = true
		} else {
			await this.objectStore.fetchObject('project', this.projectId)
			await this.fetchRelations()
			this.loadClientName()
			this.loadLedgerWebhookStatus()
		}
	},
	methods: {
		/**
		 * Load phases / tasks / activities scoped to this project in
		 * parallel. allSettled so one slow query does not block the others.
		 *
		 * @return {Promise<void>}
		 */
		async fetchRelations() {
			const tasks = [
				this.objectStore.fetchCollection('projectPhase', { _limit: 200, project: this.projectId }),
				this.objectStore.fetchCollection('projectTask', { _limit: 500, project: this.projectId }),
				this.objectStore.fetchCollection('projectActivity', { _limit: 1000, project: this.projectId }),
			]
			const [phases, taskRows, activities] = await Promise.allSettled(tasks)
			this.phases = (phases.status === 'fulfilled' && phases.value) || []
			this.tasks = (taskRows.status === 'fulfilled' && taskRows.value) || []
			this.activities = (activities.status === 'fulfilled' && activities.value) || []
		},
		async loadClientName() {
			if (!this.projectData.client) {
				this.clientName = '-'
				return
			}
			try {
				const client = await this.objectStore.fetchObject('client', this.projectData.client)
				this.clientName = client?.name || t('pipelinq', '[Verwijderde client]')
			} catch {
				this.clientName = t('pipelinq', '[Verwijderde client]')
			}
		},
		/**
		 * Resolve the effective billable value for an activity walking up
		 * task → phase → project (REQ-PTH-005 Scenarios 19..21). Returns
		 * `true` when nothing along the chain is explicitly set.
		 *
		 * @param {object} activity Activity row.
		 * @return {boolean}
		 */
		resolveActivityBillable(activity) {
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
		/**
		 * Sum logged hours across activities whose resolved billable
		 * value equals the requested filter (REQ-PTH-008 Scenario 32).
		 *
		 * @param {boolean} wantBillable True for billable hours, false for non-billable.
		 * @return {number}
		 */
		computeBillableHours(wantBillable) {
			const minutes = this.activities
				.filter(a => this.resolveActivityBillable(a) === wantBillable)
				.reduce((sum, a) => sum + (Number(a.durationMinutes) || 0), 0)
			return Math.round((minutes / 60) * 10) / 10
		},
		startEdit() {
			this.showProjectForm = true
			this.editing = true
		},
		cancelEdit() {
			this.showProjectForm = false
			this.editing = false
			if (this.isNew) {
				this.$router.push({ name: 'Projects' })
			}
		},
		async onProjectSaved(formData) {
			const payload = this.isNew
				? { ...formData }
				: { ...this.projectData, ...formData }
			const result = await this.objectStore.saveObject('project', payload)
			if (result) {
				showSuccess(t('pipelinq', 'Project saved.'))
				this.showProjectForm = false
				this.editing = false
				if (this.isNew) {
					this.$router.push({ name: 'ProjectDetail', params: { id: result.id } })
				} else {
					await this.objectStore.fetchObject('project', this.projectId)
				}
			} else {
				const error = this.objectStore.getError('project')
				showError(error?.message || t('pipelinq', 'Could not save project. Please try again.'))
			}
		},
		async confirmDelete() {
			// eslint-disable-next-line no-alert
			if (!window.confirm(t('pipelinq', 'Are you sure you want to delete this project?'))) {
				return
			}
			const success = await this.objectStore.deleteObject('project', this.projectId)
			if (success) {
				this.$router.push({ name: 'Projects' })
			} else {
				const error = this.objectStore.getError('project')
				showError(error?.message || t('pipelinq', 'Could not delete project.'))
			}
		},
		goToActivities() {
			this.$router.push({ name: 'ProjectActivities', params: { id: this.projectId } })
		},
		openPhaseDialog() {
			const maxSequence = this.phases.reduce((m, p) => Math.max(m, Number(p.sequence || 0)), 0)
			this.phaseInitial = {
				project: this.projectId,
				status: 'open',
				sequence: maxSequence + 1,
			}
			this.showPhaseDialog = true
		},
		async onPhaseSaved(formData) {
			const payload = { ...formData, project: this.projectId }
			const result = await this.objectStore.saveObject('projectPhase', payload)
			if (result) {
				showSuccess(t('pipelinq', 'Phase saved.'))
				this.showPhaseDialog = false
				await this.fetchRelations()
			} else {
				showError(t('pipelinq', 'Could not save phase. Please try again.'))
			}
		},
		openTaskDialog(phase) {
			const phaseTasks = this.tasks.filter(t => t.phase === phase.id)
			const maxSequence = phaseTasks.reduce((m, t) => Math.max(m, Number(t.sequence || 0)), 0)
			this.taskInitial = {
				phase: phase.id,
				project: this.projectId,
				status: 'open',
				sequence: maxSequence + 1,
			}
			this.showTaskDialog = true
		},
		async onTaskSaved(formData) {
			const payload = {
				...formData,
				project: this.projectId,
			}
			const result = await this.objectStore.saveObject('projectTask', payload)
			if (result) {
				showSuccess(t('pipelinq', 'Task saved.'))
				this.showTaskDialog = false
				await this.fetchRelations()
			} else {
				showError(t('pipelinq', 'Could not save task. Please try again.'))
			}
		},
		openActivityDialog(task) {
			this.activityInitial = {
				task: task.id,
				project: this.projectId,
				date: new Date().toISOString().slice(0, 10),
				// Pull the active Nextcloud user id from the page bootstrap. Falls
				// back to an empty string so the form still saves with the user
				// editing it manually (REQ-PTH-004).
				user: (window.OC?.currentUser?.uid ?? window.OC?.currentUser) || '',
			}
			this.showActivityDialog = true
		},
		async onActivitySaved(formData) {
			const payload = {
				...formData,
				project: this.projectId,
				durationMinutes: Number(formData.durationMinutes) || 0,
			}
			const result = await this.objectStore.saveObject('projectActivity', payload)
			if (result) {
				showSuccess(t('pipelinq', 'Time entry saved.'))
				this.showActivityDialog = false
				await this.fetchRelations()
			} else {
				showError(t('pipelinq', 'Could not save time entry. Please try again.'))
			}
		},
		/**
		 * Async option loader for the project's client select.
		 *
		 * @param {string} query Search query.
		 * @return {Promise<Array<{label: string, value: string}>>}
		 */
		async loadClientOptions(query) {
			try {
				const clients = await this.objectStore.fetchCollection('client', {
					_limit: 50,
					name: query || undefined,
				})
				this.availableClients = clients || []
				return (clients || []).map(c => ({
					value: c.id,
					label: c.name || c.id,
				}))
			} catch {
				return []
			}
		},
		statusLabel(status) {
			const map = {
				open: t('pipelinq', 'Open'),
				in_progress: t('pipelinq', 'Running'),
				on_hold: t('pipelinq', 'Paused'),
				completed: t('pipelinq', 'Completed'),
				cancelled: t('pipelinq', 'Cancelled'),
			}
			return map[status] || (status || '-')
		},
		formatDate(dateStr) {
			if (!dateStr) return '-'
			try {
				return new Date(dateStr).toLocaleDateString()
			} catch {
				return dateStr
			}
		},
		formatHours(value) {
			const n = Number(value)
			if (Number.isNaN(n)) return '0u'
			return n + 'u'
		},
		formatEur(value) {
			const n = Number(value || 0)
			if (Number.isNaN(n)) return '€ 0'
			return '€ ' + n.toLocaleString('nl-NL', { maximumFractionDigits: 0 })
		},
		/**
		 * Localised label for the ledger sync status (REQ-PLG-005). The
		 * three keys are English source strings — Dutch translations live
		 * in l10n/nl.json.
		 *
		 * @param {string|null} status The ledgerSyncStatus value.
		 * @return {string}
		 */
		ledgerStatusLabel(status) {
			const map = {
				synced: t('pipelinq', 'Ledger synchronized'),
				pending: t('pipelinq', 'Ledger pending'),
				failed: t('pipelinq', 'Ledger sync failed'),
			}
			return map[status] || (status || '-')
		},
		/**
		 * Modifier class for the ledger card pill (mirrors the ProjectList
		 * pill colours for visual consistency).
		 *
		 * @param {string|null} status The ledgerSyncStatus value.
		 * @return {string}
		 */
		ledgerPillClass(status) {
			return 'ledger-card__pill--' + (status || 'unknown')
		},
		/**
		 * Format an ISO timestamp as a locale date/time string, or "-" when
		 * the value is missing.
		 *
		 * @param {string|null} value The ISO timestamp.
		 * @return {string}
		 */
		formatDateTime(value) {
			if (!value) return '-'
			try {
				return new Date(value).toLocaleString()
			} catch {
				return value
			}
		},
		/**
		 * Resolve whether the admin has configured the Shillinq ledger
		 * webhook URL. Drives the v-if on the ledger card (REQ-PLG-005-04:
		 * the entire card is removed from the DOM when no URL is set).
		 *
		 * @return {Promise<void>}
		 */
		async loadLedgerWebhookStatus() {
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/settings'))
				const url = (data?.config?.shillinq_ledger_webhook_url || '').trim()
				this.ledgerWebhookConfigured = url !== ''
			} catch {
				// Settings unreachable -> hide the card rather than show stale state.
				this.ledgerWebhookConfigured = false
			}
		},
		/**
		 * Manually re-dispatch this project to the Shillinq ledger via
		 * POST /apps/pipelinq/api/ledger/retry/{projectId} (REQ-PLG-005-03).
		 * Refreshes the project after a successful retry so the card
		 * reflects the new state.
		 *
		 * @return {Promise<void>}
		 */
		async retryLedgerSync() {
			if (this.ledgerRetrying || !this.projectId) return
			this.ledgerRetrying = true
			try {
				const url = generateUrl(`/apps/pipelinq/api/ledger/retry/${encodeURIComponent(this.projectId)}`)
				const { data } = await axios.post(url, {})
				if (data?.ledgerSyncStatus === 'synced') {
					showSuccess(t('pipelinq', 'Ledger sync retried successfully.'))
				} else {
					showError(data?.error || t('pipelinq', 'Could not retry the ledger sync.'))
				}
			} catch (e) {
				const message = e?.response?.data?.error || t('pipelinq', 'Could not retry the ledger sync.')
				showError(message)
			} finally {
				this.ledgerRetrying = false
				// Refresh the project so the ledger card reflects the new state.
				try {
					await this.objectStore.fetchObject('project', this.projectId)
				} catch {
					// Best-effort refresh; the toast above already informed the user.
				}
			}
		},
	},
}
</script>

<style scoped>
.project-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.info-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 12px;
}

.info-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.info-field label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.info-field--wide {
	grid-column: 1 / -1;
}

.kpi-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
	gap: 12px;
}

.kpi-card {
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-background-hover);
}

.kpi-card__label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.kpi-card__value {
	font-size: 1.4em;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
}

.kpi-card__value--warn {
	color: #c62828;
}

.status-pill {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.85em;
	background: var(--color-background-dark);
}

.status-pill--open { background: #e3f2fd; color: #0d47a1; }

.status-pill--in_progress { background: #fff8e1; color: #6d4c00; }

.status-pill--on_hold { background: #ede7f6; color: #4527a0; }

.status-pill--completed { background: #e8f5e9; color: #1b5e20; }

.status-pill--cancelled { background: #fbe9e7; color: #b71c1c; }

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

.color-swatch {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 3px;
	margin-right: 6px;
	border: 1px solid var(--color-border);
	vertical-align: middle;
}

.client-link {
	color: var(--color-primary-element);
	cursor: pointer;
}

/* Shillinq Ledger card (REQ-PLG-005). */
.ledger-card {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 8px 16px;
	align-items: center;
}

.ledger-card__row {
	display: contents;
}

.ledger-card__row--actions {
	grid-column: 1 / -1;
	display: flex;
	justify-content: flex-end;
}

.ledger-card__label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.ledger-card__pill {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.85em;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	width: max-content;
}

.ledger-card__pill--synced { background: #e8f5e9; color: #1b5e20; }

.ledger-card__pill--pending { background: #fff8e1; color: #6d4c00; }

.ledger-card__pill--failed { background: #fbe9e7; color: #b71c1c; }

.ledger-card__dash {
	color: var(--color-text-maxcontrast);
}
</style>
