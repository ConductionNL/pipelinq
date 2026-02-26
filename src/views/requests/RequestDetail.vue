<template>
	<div class="request-detail">
		<div class="request-detail__header">
			<NcButton @click="$emit('navigate', 'requests')">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New request') }}
			</h2>
			<h2 v-else>
				{{ requestData.title || t('pipelinq', 'Request') }}
			</h2>
		</div>

		<NcLoadingIcon v-if="loading" />

		<!-- Edit / Create mode -->
		<RequestForm
			v-else-if="editing || isNew"
			:request="isNew ? null : requestData"
			:pre-linked-client="preLinkedClient"
			@save="onFormSave"
			@cancel="onFormCancel" />

		<!-- View mode -->
		<div v-else class="request-detail__content">
			<!-- Converted notice -->
			<div v-if="isConverted" class="request-detail__notice">
				{{ t('pipelinq', 'This request has been converted to a case and can no longer be edited.') }}
				<a v-if="requestData.caseReference" href="#" @click.prevent="viewCase">
					{{ t('pipelinq', 'View case') }}
				</a>
			</div>

			<div class="request-detail__actions">
				<NcButton v-if="!isConverted" type="primary" @click="editing = true">
					{{ t('pipelinq', 'Edit') }}
				</NcButton>
				<NcButton
					v-if="canConvert"
					@click="convertToCase">
					{{ t('pipelinq', 'Convert to case') }}
				</NcButton>
				<NcButton
					v-if="canDelete"
					type="error"
					@click="showDeleteDialog = true">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</div>

			<div class="request-detail__layout">
				<!-- Left column: core info -->
				<div class="request-detail__info">
					<!-- Status + Priority row -->
					<div class="info-row">
						<div class="info-field">
							<label>{{ t('pipelinq', 'Status') }}</label>
							<div class="status-control">
								<span
									class="status-badge"
									:style="{ background: getStatusColor(requestData.status), color: '#fff' }">
									{{ getStatusLabel(requestData.status) }}
								</span>
								<NcSelect
									v-if="statusTransitions.length > 0 && !isConverted"
									:value="null"
									:options="statusTransitionOptions"
									:placeholder="t('pipelinq', 'Change status')"
									:clearable="false"
									class="status-transition-select"
									@input="onStatusChange" />
							</div>
						</div>
						<div class="info-field">
							<label>{{ t('pipelinq', 'Priority') }}</label>
							<span
								class="priority-badge"
								:style="{ color: getPriorityColor(requestData.priority) }">
								{{ getPriorityLabel(requestData.priority) }}
							</span>
						</div>
					</div>

					<!-- Info grid -->
					<div class="info-grid">
						<div class="info-field">
							<label>{{ t('pipelinq', 'Channel') }}</label>
							<span>{{ requestData.channel || '-' }}</span>
						</div>
						<div class="info-field">
							<label>{{ t('pipelinq', 'Category') }}</label>
							<span>{{ requestData.category || '-' }}</span>
						</div>
						<div class="info-field">
							<label>{{ t('pipelinq', 'Requested at') }}</label>
							<span>{{ formatDate(requestData.requestedAt) }}</span>
						</div>
						<div class="info-field">
							<label>{{ t('pipelinq', 'Assigned to') }}</label>
							<span>{{ requestData.assignee || t('pipelinq', 'Unassigned') }}</span>
						</div>
					</div>

					<div v-if="requestData.description" class="info-field info-field--full">
						<label>{{ t('pipelinq', 'Description') }}</label>
						<p>{{ requestData.description }}</p>
					</div>

					<!-- Client section -->
					<div class="request-detail__section">
						<h3>{{ t('pipelinq', 'Client') }}</h3>
						<div v-if="clientData" class="client-link">
							<a href="#" @click.prevent="$emit('navigate', 'client-detail', clientData.id)">
								{{ clientData.name }}
							</a>
							<span v-if="clientData.email" class="client-meta">{{ clientData.email }}</span>
							<span v-if="clientData.phone" class="client-meta">{{ clientData.phone }}</span>
						</div>
						<p v-else-if="requestData.client" class="section-empty orphaned-ref">
							{{ t('pipelinq', '[Deleted client]') }}
						</p>
						<p v-else class="section-empty">
							{{ t('pipelinq', 'No client linked') }}
						</p>
					</div>
				</div>

				<!-- Right column: pipeline + assignment -->
				<div class="request-detail__sidebar">
					<!-- Assignment -->
					<div class="sidebar-section">
						<h3>{{ t('pipelinq', 'Assignment') }}</h3>
						<NcSelect
							v-if="!isConverted"
							:value="assigneeOption"
							:options="userOptions"
							:clearable="true"
							label="label"
							:reduce="o => o.value"
							:placeholder="t('pipelinq', 'Assign to user')"
							:filterable="true"
							@input="onAssigneeChange" />
						<span v-else>{{ requestData.assignee || t('pipelinq', 'Unassigned') }}</span>
					</div>

					<!-- Pipeline progress -->
					<div v-if="pipelineData" class="sidebar-section">
						<h3>{{ t('pipelinq', 'Pipeline') }}</h3>
						<p class="pipeline-name">{{ pipelineData.title }}</p>

						<div class="pipeline-progress">
							<div
								v-for="stage in sortedStages"
								:key="stage.name"
								class="pipeline-stage"
								:class="stageClass(stage)">
								<span class="stage-indicator" />
								<span class="stage-name">{{ stage.name }}</span>
							</div>
						</div>

						<NcButton
							v-if="nextStage && !isConverted"
							class="next-stage-btn"
							@click="moveToNextStage">
							{{ t('pipelinq', 'Move to {stage}', { stage: nextStage.name }) }}
						</NcButton>
					</div>
					<div v-else class="sidebar-section">
						<h3>{{ t('pipelinq', 'Pipeline') }}</h3>
						<p class="section-empty">{{ t('pipelinq', 'Not on pipeline') }}</p>
					</div>
				</div>
			</div>
		</div>

		<!-- Notes section -->
		<EntityNotes
			v-if="!isNew && !loading && !editing"
			object-type="pipelinq_request"
			:object-id="requestId" />

		<!-- Delete dialog -->
		<NcDialog
			v-if="showDeleteDialog"
			:name="t('pipelinq', 'Delete request')"
			@closing="showDeleteDialog = false">
			<p>{{ t('pipelinq', 'Are you sure you want to delete "{title}"?', { title: requestData.title }) }}</p>
			<template #actions>
				<NcButton @click="showDeleteDialog = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="confirmDelete">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import RequestForm from './RequestForm.vue'
import EntityNotes from '../../components/EntityNotes.vue'
import { useObjectStore } from '../../store/modules/object.js'
import {
	getAllowedTransitions,
	isTerminalStatus,
	getStatusLabel,
	getStatusColor,
	getPriorityLabel,
	getPriorityColor,
} from '../../services/requestStatus.js'

export default {
	name: 'RequestDetail',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcSelect,
		RequestForm,
		EntityNotes,
	},
	props: {
		requestId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			editing: false,
			showDeleteDialog: false,
			clientData: null,
			pipelineData: null,
			users: [],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			if (!this.requestId) return true
			return this.requestId.startsWith('new')
		},
		preLinkedClient() {
			if (this.requestId && this.requestId.includes('client=')) {
				return this.requestId.split('client=')[1]
			}
			return null
		},
		loading() {
			return this.objectStore.isLoading('request')
		},
		requestData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('request', this.requestId) || {}
		},
		isConverted() {
			return this.requestData.status === 'converted'
		},
		canConvert() {
			return this.requestData.status === 'in_progress'
		},
		canDelete() {
			return !this.isConverted
		},
		statusTransitions() {
			return getAllowedTransitions(this.requestData.status)
		},
		statusTransitionOptions() {
			return this.statusTransitions.map(s => ({
				id: s,
				label: getStatusLabel(s),
			}))
		},
		assigneeOption() {
			if (!this.requestData.assignee) return null
			const user = this.users.find(u => u.value === this.requestData.assignee)
			return user || { value: this.requestData.assignee, label: this.requestData.assignee }
		},
		userOptions() {
			return this.users
		},
		sortedStages() {
			if (!this.pipelineData?.stages) return []
			return [...this.pipelineData.stages].sort((a, b) => a.order - b.order)
		},
		currentStageOrder() {
			if (!this.requestData.stage || !this.sortedStages.length) return -1
			const stage = this.sortedStages.find(s => s.name === this.requestData.stage)
			return stage ? stage.order : -1
		},
		nextStage() {
			if (this.currentStageOrder < 0) return null
			const openStages = this.sortedStages.filter(s => !s.isClosed)
			return openStages.find(s => s.order > this.currentStageOrder) || null
		},
	},
	async mounted() {
		this.fetchUsers()
		if (!this.isNew) {
			await this.objectStore.fetchObject('request', this.requestId)
			await this.fetchRelated()
		}
	},
	methods: {
		getStatusLabel,
		getStatusColor,
		getPriorityLabel,
		getPriorityColor,

		async fetchRelated() {
			if (this.requestData.client) {
				const client = await this.objectStore.fetchObject('client', this.requestData.client)
				this.clientData = client || null
			}
			if (this.requestData.pipeline) {
				const pipeline = await this.objectStore.fetchObject('pipeline', this.requestData.pipeline)
				this.pipelineData = pipeline || null
			}
		},

		async fetchUsers() {
			try {
				const response = await fetch('/ocs/v2.php/cloud/users?format=json', {
					headers: {
						'OCS-APIREQUEST': 'true',
						requesttoken: OC.requestToken,
					},
				})
				const data = await response.json()
				const userIds = data?.ocs?.data?.users || []
				this.users = userIds.map(uid => ({ value: uid, label: uid }))
			} catch {
				this.users = []
			}
		},

		stageClass(stage) {
			if (this.currentStageOrder < 0) return ''
			if (stage.order < this.currentStageOrder) return 'stage-completed'
			if (stage.order === this.currentStageOrder) return 'stage-current'
			return 'stage-future'
		},

		async onStatusChange(option) {
			if (!option) return
			const newStatus = option.id || option
			await this.objectStore.saveObject('request', {
				...this.requestData,
				status: newStatus,
			})
			await this.objectStore.fetchObject('request', this.requestId)
		},

		async onAssigneeChange(userId) {
			await this.objectStore.saveObject('request', {
				...this.requestData,
				assignee: userId || null,
			})
			await this.objectStore.fetchObject('request', this.requestId)
		},

		async moveToNextStage() {
			if (!this.nextStage) return
			await this.objectStore.saveObject('request', {
				...this.requestData,
				stage: this.nextStage.name,
				stageOrder: this.nextStage.order,
			})
			await this.objectStore.fetchObject('request', this.requestId)
		},

		async convertToCase() {
			const confirmed = confirm(
				t('pipelinq', 'Convert this request to a case? This action cannot be undone.'),
			)
			if (!confirmed) return

			try {
				// TODO: When Procest integration is available, create the case first
				// const caseResult = await createProcestCase(this.requestData)
				await this.objectStore.saveObject('request', {
					...this.requestData,
					status: 'converted',
					// caseReference: caseResult.id,
				})
				await this.objectStore.fetchObject('request', this.requestId)
			} catch (error) {
				console.error('Failed to convert request:', error)
			}
		},

		viewCase() {
			// TODO: Navigate to Procest case when integration is available
		},

		async onFormSave(formData) {
			const result = await this.objectStore.saveObject('request', formData)
			if (result) {
				if (this.isNew) {
					this.$emit('navigate', 'request-detail', result.id)
				} else {
					await this.objectStore.fetchObject('request', this.requestId)
					await this.fetchRelated()
					this.editing = false
				}
			} else {
				const error = this.objectStore.getError('request')
				showError(error?.message || t('pipelinq', 'Failed to save request. Please try again.'))
			}
		},

		onFormCancel() {
			if (this.isNew) {
				this.$emit('navigate', 'requests')
			} else {
				this.editing = false
			}
		},

		async confirmDelete() {
			this.showDeleteDialog = false
			this.cleanupNotes('pipelinq_request', this.requestId)
			const success = await this.objectStore.deleteObject('request', this.requestId)
			if (success) {
				this.$emit('navigate', 'requests')
			} else {
				const error = this.objectStore.getError('request')
				showError(error?.message || t('pipelinq', 'Failed to delete request.'))
			}
		},
		async cleanupNotes(objectType, objectId) {
			try {
				await fetch(`/apps/pipelinq/api/notes/${objectType}/${objectId}`, {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' },
				})
			} catch {
				// Cleanup failure is non-blocking
			}
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
.request-detail {
	padding: 20px;
	max-width: 900px;
}

.request-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
}

.request-detail__notice {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-left: 4px solid #745bca;
	padding: 12px 16px;
	border-radius: var(--border-radius);
	margin-bottom: 16px;
	font-size: 14px;
}

.request-detail__notice a {
	color: var(--color-primary);
	font-weight: bold;
	margin-left: 8px;
}

.request-detail__actions {
	display: flex;
	gap: 12px;
	margin-bottom: 20px;
}

.request-detail__layout {
	display: flex;
	gap: 32px;
}

.request-detail__info {
	flex: 1;
	min-width: 0;
}

.request-detail__sidebar {
	width: 260px;
	flex-shrink: 0;
}

.info-row {
	display: flex;
	gap: 24px;
	margin-bottom: 16px;
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
}

.status-control {
	display: flex;
	align-items: center;
	gap: 8px;
}

.status-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.status-transition-select {
	min-width: 140px;
}

.priority-badge {
	font-weight: 600;
	font-size: 14px;
}

/* Sections */
.request-detail__section {
	margin-top: 24px;
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.request-detail__section h3 {
	margin: 0 0 8px;
}

.client-link a {
	font-weight: bold;
	color: var(--color-primary);
}

.client-meta {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.section-empty {
	color: var(--color-text-maxcontrast);
}

.orphaned-ref {
	font-style: italic;
	color: var(--color-warning);
}

/* Sidebar */
.sidebar-section {
	margin-bottom: 24px;
}

.sidebar-section h3 {
	margin: 0 0 8px;
}

.pipeline-name {
	color: var(--color-text-maxcontrast);
	margin: 0 0 12px;
	font-size: 13px;
}

.pipeline-progress {
	display: flex;
	flex-direction: column;
}

.pipeline-stage {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
	position: relative;
}

.stage-indicator {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	border: 2px solid var(--color-border-dark);
	flex-shrink: 0;
}

.stage-completed .stage-indicator {
	background: var(--color-success);
	border-color: var(--color-success);
}

.stage-current .stage-indicator {
	background: var(--color-primary);
	border-color: var(--color-primary);
}

.stage-current .stage-name {
	font-weight: bold;
	color: var(--color-primary);
}

.stage-future .stage-indicator {
	background: transparent;
}

.stage-name {
	font-size: 13px;
}

.pipeline-stage:not(:last-child)::after {
	content: '';
	position: absolute;
	left: 5px;
	top: 18px;
	width: 2px;
	height: calc(100% - 6px);
	background: var(--color-border);
}

.stage-completed:not(:last-child)::after {
	background: var(--color-success);
}

.next-stage-btn {
	margin-top: 12px;
}
</style>
