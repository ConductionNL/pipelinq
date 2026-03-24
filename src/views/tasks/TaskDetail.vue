<template>
	<div v-if="editing || isNew">
		<div class="task-detail__header">
			<NcButton @click="onFormCancel">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New task') }}
			</h2>
			<h2 v-else>
				{{ taskData.subject || t('pipelinq', 'Task') }}
			</h2>
		</div>
		<TaskForm
			:task="isNew ? null : taskData"
			:client-id="prefillClientId"
			:request-id="prefillRequestId"
			@save="onFormSave"
			@cancel="onFormCancel" />
	</div>

	<CnDetailPage
		v-else
		:title="taskData.subject || t('pipelinq', 'Task')"
		:subtitle="t('pipelinq', 'Task')"
		:back-route="{ name: 'Tasks' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="!isNew && !loading"
		object-type="pipelinq_task"
		:object-id="taskId"
		:sidebar-props="sidebarProps">
		<template #header-actions>
			<NcButton type="primary" @click="editing = true">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton
				v-if="canClaim"
				type="primary"
				@click="claimTask">
				{{ t('pipelinq', 'Claim') }}
			</NcButton>
			<NcButton
				v-if="canComplete"
				type="primary"
				@click="showCompleteDialog = true">
				{{ t('pipelinq', 'Afgerond') }}
			</NcButton>
			<NcButton
				v-if="canLogAttempt"
				type="secondary"
				@click="logUnreachable">
				{{ t('pipelinq', 'Niet bereikbaar') }}
			</NcButton>
			<NcButton
				v-if="canReassign"
				type="secondary"
				@click="showReassignDialog = true">
				{{ t('pipelinq', 'Hertoewijzen') }}
			</NcButton>
			<NcButton
				v-if="canReopen"
				type="secondary"
				@click="reopenTask">
				{{ t('pipelinq', 'Heropenen') }}
			</NcButton>
			<NcButton type="error" @click="showDeleteDialog = true">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<!-- Banners -->
		<div v-if="taskData.callbackPhoneNumber" class="task-banner task-banner--phone">
			<strong>{{ t('pipelinq', 'Callback number:') }}</strong> {{ taskData.callbackPhoneNumber }}
		</div>
		<div v-if="taskData.preferredTimeSlot" class="task-banner task-banner--time">
			<strong>{{ t('pipelinq', 'Preferred time:') }}</strong> {{ taskData.preferredTimeSlot }}
		</div>

		<CnDetailCard :title="t('pipelinq', 'Details')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Type') }}</label>
					<span class="type-badge" :class="'type-' + taskData.type">
						{{ getTaskTypeLabel(taskData.type) }}
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<span class="status-badge" :class="'status-' + taskData.status">
						{{ getTaskStatusLabel(taskData.status) }}
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Priority') }}</label>
					<span :style="{ color: getTaskPriorityColor(taskData.priority) }">
						{{ getTaskPriorityLabel(taskData.priority) }}
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Deadline') }}</label>
					<span :class="{ 'overdue-text': isOverdue }">
						{{ taskData.deadline ? formatDate(taskData.deadline) : '-' }}
						<span v-if="isOverdue" class="overdue-badge">{{ t('pipelinq', 'Overdue') }}</span>
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Assignee') }}</label>
					<span>{{ taskData.assigneeUserId || taskData.assigneeGroupId || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Created by') }}</label>
					<span>{{ taskData.createdBy || '-' }}</span>
				</div>
			</div>
			<div v-if="taskData.description" class="info-field info-field--full">
				<label>{{ t('pipelinq', 'Description') }}</label>
				<p>{{ taskData.description }}</p>
			</div>
			<div v-if="taskData.contactMomentSummary" class="info-field info-field--full">
				<label>{{ t('pipelinq', 'Contact moment') }}</label>
				<p>{{ taskData.contactMomentSummary }}</p>
			</div>
		</CnDetailCard>

		<!-- Client / Request links -->
		<CnDetailCard v-if="taskData.clientId || taskData.requestId" :title="t('pipelinq', 'Linked items')">
			<div v-if="taskData.clientId" class="linked-item">
				<a href="#" @click.prevent="$router.push({ name: 'ClientDetail', params: { id: taskData.clientId } })">
					{{ t('pipelinq', 'View client') }}
				</a>
			</div>
			<div v-if="taskData.requestId" class="linked-item">
				<a href="#" @click.prevent="$router.push({ name: 'RequestDetail', params: { id: taskData.requestId } })">
					{{ t('pipelinq', 'View request') }}
				</a>
			</div>
		</CnDetailCard>

		<!-- Completion info -->
		<CnDetailCard v-if="taskData.status === 'afgerond'" :title="t('pipelinq', 'Completion')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Completed at') }}</label>
					<span>{{ taskData.completedAt ? formatDate(taskData.completedAt) : '-' }}</span>
				</div>
				<div v-if="taskData.resultText" class="info-field info-field--full">
					<label>{{ t('pipelinq', 'Result') }}</label>
					<p>{{ taskData.resultText }}</p>
				</div>
			</div>
		</CnDetailCard>

		<!-- Callback attempts -->
		<CnDetailCard v-if="attemptsList.length > 0" :title="t('pipelinq', 'Attempts')">
			<template #actions>
				<span class="attempt-count">{{ attemptsList.length }}/3</span>
			</template>
			<div class="attempts-list">
				<div v-for="(attempt, idx) in attemptsList" :key="idx" class="attempt-entry">
					<span class="attempt-time">{{ formatDate(attempt.timestamp) }}</span>
					<span class="attempt-result" :class="'result-' + attempt.result">
						{{ attempt.result }}
					</span>
					<span v-if="attempt.notes" class="attempt-notes">{{ attempt.notes }}</span>
				</div>
			</div>
		</CnDetailCard>

		<!-- Complete dialog -->
		<NcDialog
			v-if="showCompleteDialog"
			:name="t('pipelinq', 'Complete task')"
			@closing="showCompleteDialog = false">
			<div class="dialog-body">
				<label>{{ t('pipelinq', 'Result text') }}</label>
				<textarea v-model="resultText" rows="3" class="dialog-textarea" />
			</div>
			<template #actions>
				<NcButton @click="showCompleteDialog = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" @click="completeTask">
					{{ t('pipelinq', 'Mark as completed') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Reassign dialog -->
		<NcDialog
			v-if="showReassignDialog"
			:name="t('pipelinq', 'Reassign task')"
			@closing="showReassignDialog = false">
			<div class="dialog-body">
				<label>{{ t('pipelinq', 'Assign to') }}</label>
				<input
					v-model="reassignQuery"
					type="text"
					:placeholder="t('pipelinq', 'Search users or groups...')"
					class="dialog-input"
					@input="onReassignSearch">
				<div v-if="reassignResults.length > 0" class="assignee-results">
					<div
						v-for="item in reassignResults"
						:key="item.type + '-' + item.id"
						class="assignee-option"
						@click="selectReassignee(item)">
						{{ item.label }}
						<span class="assignee-type">({{ item.type }})</span>
					</div>
				</div>
			</div>
			<template #actions>
				<NcButton @click="showReassignDialog = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Delete dialog -->
		<NcDialog
			v-if="showDeleteDialog"
			:name="t('pipelinq', 'Delete task')"
			@closing="showDeleteDialog = false">
			<p>{{ t('pipelinq', 'Are you sure you want to delete this task?') }}</p>
			<template #actions>
				<NcButton @click="showDeleteDialog = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="deleteTask">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</CnDetailPage>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import { showError } from '@nextcloud/dialogs'
import { useObjectStore } from '../../store/modules/object.js'
import {
	getTaskTypeLabel,
	getTaskStatusLabel,
	getTaskPriorityLabel,
	getTaskPriorityColor,
	isTaskOverdue,
	getDefaultDeadline,
	searchAssignees,
} from '../../services/taskUtils.js'
import TaskForm from './TaskForm.vue'

export default {
	name: 'TaskDetail',
	components: {
		NcButton,
		NcDialog,
		CnDetailPage,
		CnDetailCard,
		TaskForm,
	},
	props: {
		taskId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			editing: false,
			showCompleteDialog: false,
			showReassignDialog: false,
			showDeleteDialog: false,
			resultText: '',
			reassignQuery: '',
			reassignResults: [],
			prefillClientId: this.$route?.query?.clientId || null,
			prefillRequestId: this.$route?.query?.requestId || null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return this.taskId === 'new'
		},
		loading() {
			return this.objectStore.loading.task || false
		},
		taskData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('task', this.taskId) || {}
		},
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.task || {}
			return {
				title: t('pipelinq', 'Task'),
				register: config.register || '',
				schema: config.schema || '',
			}
		},
		isOverdue() {
			return isTaskOverdue(this.taskData)
		},
		attemptsList() {
			return this.taskData.attempts || []
		},
		canClaim() {
			return this.taskData.status === 'open'
				&& this.taskData.assigneeGroupId
				&& !this.taskData.assigneeUserId
		},
		canComplete() {
			return this.taskData.status === 'in_behandeling'
				|| this.taskData.status === 'open'
		},
		canLogAttempt() {
			return this.taskData.type === 'terugbelverzoek'
				&& this.taskData.status === 'in_behandeling'
		},
		canReassign() {
			return this.taskData.status === 'open'
				|| this.taskData.status === 'in_behandeling'
		},
		canReopen() {
			return this.taskData.status === 'afgerond'
				|| this.taskData.status === 'verlopen'
		},
	},
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('task', this.taskId)
		}
	},
	methods: {
		getTaskTypeLabel,
		getTaskStatusLabel,
		getTaskPriorityLabel,
		getTaskPriorityColor,

		async claimTask() {
			await this.objectStore.saveObject('task', {
				...this.taskData,
				assigneeUserId: OC.currentUser,
				assigneeGroupId: null,
				status: 'in_behandeling',
			})
			await this.objectStore.fetchObject('task', this.taskId)
		},

		async completeTask() {
			await this.objectStore.saveObject('task', {
				...this.taskData,
				status: 'afgerond',
				completedAt: new Date().toISOString(),
				resultText: this.resultText,
			})
			await this.objectStore.fetchObject('task', this.taskId)
			this.showCompleteDialog = false
			this.resultText = ''
		},

		async logUnreachable() {
			const attempts = [...(this.taskData.attempts || [])]
			attempts.push({
				timestamp: new Date().toISOString(),
				result: 'niet_bereikbaar',
				notes: '',
			})
			await this.objectStore.saveObject('task', {
				...this.taskData,
				attempts,
			})
			await this.objectStore.fetchObject('task', this.taskId)
			if (attempts.length >= 3) {
				const confirmClose = confirm(
					t('pipelinq', 'This is attempt {count}/3. Would you like to close this task?', { count: attempts.length }),
				)
				if (confirmClose) {
					await this.objectStore.saveObject('task', {
						...this.taskData,
						status: 'afgerond',
						completedAt: new Date().toISOString(),
						resultText: t('pipelinq', 'Citizen not reached after {count} attempts', { count: attempts.length }),
					})
					await this.objectStore.fetchObject('task', this.taskId)
				}
			}
		},

		async reopenTask() {
			const attempts = [...(this.taskData.attempts || [])]
			attempts.push({
				timestamp: new Date().toISOString(),
				result: 'heropend',
				notes: '',
			})
			await this.objectStore.saveObject('task', {
				...this.taskData,
				status: 'open',
				deadline: getDefaultDeadline(),
				completedAt: null,
				resultText: null,
				attempts,
			})
			await this.objectStore.fetchObject('task', this.taskId)
		},

		async onReassignSearch() {
			this.reassignResults = await searchAssignees(this.reassignQuery)
		},

		async selectReassignee(item) {
			const attempts = [...(this.taskData.attempts || [])]
			attempts.push({
				timestamp: new Date().toISOString(),
				result: 'hertoegewezen',
				notes: item.label,
			})

			const data = { ...this.taskData, attempts }
			if (item.type === 'user') {
				data.assigneeUserId = item.id
				data.assigneeGroupId = null
			} else {
				data.assigneeGroupId = item.id
				data.assigneeUserId = null
			}

			await this.objectStore.saveObject('task', data)
			await this.objectStore.fetchObject('task', this.taskId)
			this.showReassignDialog = false
			this.reassignQuery = ''
			this.reassignResults = []
		},

		async deleteTask() {
			this.showDeleteDialog = false
			const success = await this.objectStore.deleteObject('task', this.taskId)
			if (success) {
				this.$router.push({ name: 'Tasks' })
			} else {
				const error = this.objectStore.getError('task')
				showError(error?.message || t('pipelinq', 'Failed to delete task.'))
			}
		},

		async onFormSave(formData) {
			const result = await this.objectStore.saveObject('task', formData)
			if (result) {
				if (this.isNew) {
					this.$router.push({ name: 'TaskDetail', params: { id: result.id } })
				} else {
					await this.objectStore.fetchObject('task', this.taskId)
					this.editing = false
				}
			} else {
				const error = this.objectStore.getError('task')
				showError(error?.message || t('pipelinq', 'Failed to save task. Please try again.'))
			}
		},

		onFormCancel() {
			if (this.isNew) {
				this.$router.push({ name: 'Tasks' })
			} else {
				this.editing = false
			}
		},

		formatDate(dateStr) {
			if (!dateStr) return '-'
			try {
				return new Date(dateStr).toLocaleDateString('nl-NL', {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
					hour: '2-digit',
					minute: '2-digit',
				})
			} catch {
				return dateStr
			}
		},
	},
}
</script>

<style scoped>
.task-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
	padding: 20px 20px 0;
}

.task-banner {
	padding: 10px 16px;
	border-radius: var(--border-radius-large);
	margin-bottom: 12px;
	font-size: 14px;
}

.task-banner--phone {
	background: #dbeafe;
	border: 1px solid #93c5fd;
}

.task-banner--time {
	background: #fef3c7;
	border: 1px solid #fcd34d;
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
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.info-field span,
.info-field p {
	margin: 0;
}

.info-field--full {
	grid-column: 1 / -1;
	margin-top: 8px;
}

.type-badge,
.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: bold;
}

.type-terugbelverzoek { background: #dbeafe; color: #1d4ed8; }
.type-opvolgtaak { background: #dcfce7; color: #15803d; }
.type-informatievraag { background: #fef3c7; color: #92400e; }
.status-open { background: #dbeafe; color: #1d4ed8; }
.status-in_behandeling { background: #fef3c7; color: #92400e; }
.status-afgerond { background: #dcfce7; color: #15803d; }
.status-verlopen { background: #fee2e2; color: #991b1b; }

.overdue-text { color: var(--color-error); }
.overdue-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	background: var(--color-error);
	color: #fff;
	margin-left: 6px;
}

.linked-item {
	margin-bottom: 8px;
}

.linked-item a {
	font-weight: bold;
	color: var(--color-primary);
}

.attempt-count {
	font-size: 13px;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}

.attempts-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.attempt-entry {
	display: flex;
	gap: 12px;
	align-items: center;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
	font-size: 13px;
}

.attempt-time {
	color: var(--color-text-maxcontrast);
	min-width: 140px;
}

.result-niet_bereikbaar { color: var(--color-warning); font-weight: 600; }
.result-hertoegewezen { color: #1d4ed8; font-weight: 600; }
.result-heropend { color: var(--color-text-maxcontrast); font-weight: 600; }

.attempt-notes {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.dialog-body {
	padding: 16px;
}

.dialog-textarea,
.dialog-input {
	width: 100%;
	padding: 8px;
	margin: 8px 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 14px;
}

.assignee-results {
	max-height: 200px;
	overflow-y: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.assignee-option {
	padding: 8px 12px;
	cursor: pointer;
}

.assignee-option:hover {
	background: var(--color-background-hover);
}

.assignee-type {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
</style>
