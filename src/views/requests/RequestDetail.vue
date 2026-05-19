<template>
	<div v-if="editing || isNew">
		<div class="request-detail__header">
			<NcButton @click="onFormCancel">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New request') }}
			</h2>
			<h2 v-else>
				{{ requestData.title || t('pipelinq', 'Request') }}
			</h2>
		</div>
		<RequestForm
			:request="isNew ? null : requestData"
			:pre-linked-client="preLinkedClient"
			@save="onFormSave"
			@cancel="onFormCancel" />
	</div>

	<CnDetailPage
		v-else
		:title="requestData.title || t('pipelinq', 'Request')"
		:subtitle="t('pipelinq', 'Request')"
		:back-route="{ name: 'Requests' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="!isNew && !loading"
		object-type="pipelinq_request"
		:object-id="requestId"
		:sidebar-props="sidebarProps">
		<template #header-actions>
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
		</template>

		<!-- Converted notice -->
		<div v-if="isConverted" class="request-detail__notice">
			{{ t('pipelinq', 'This request has been converted to a case and can no longer be edited.') }}
			<a v-if="requestData.caseReference" href="#" @click.prevent="viewCase">
				{{ t('pipelinq', 'View case') }}
			</a>
		</div>

		<CnDetailCard :title="t('pipelinq', 'Status & Priority')">
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
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Request Information')">
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
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Client')">
			<div v-if="clientData" class="client-link">
				<a href="#" @click.prevent="$router.push({ name: 'ClientDetail', params: { id: clientData.id } })">
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
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Assignment')">
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
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Queue')">
			<div v-if="queueData" class="queue-link">
				<a href="#" @click.prevent="$router.push({ name: 'QueueDetail', params: { id: queueData.id } })">
					{{ queueData.title }}
				</a>
				<NcSelect
					v-if="!isConverted"
					:value="queueOption"
					:options="queueOptions"
					:clearable="true"
					label="label"
					:reduce="o => o.value"
					:placeholder="t('pipelinq', 'Change queue')"
					:filterable="true"
					class="queue-select"
					@input="onQueueChange" />
			</div>
			<div v-else>
				<NcSelect
					v-if="!isConverted"
					:value="null"
					:options="queueOptions"
					:clearable="true"
					label="label"
					:reduce="o => o.value"
					:placeholder="t('pipelinq', 'Assign to queue')"
					:filterable="true"
					class="queue-select"
					@input="onQueueChange" />
				<p v-else class="section-empty">
					{{ t('pipelinq', 'Not in a queue') }}
				</p>
			</div>
		</CnDetailCard>

		<!-- Routing Suggestions -->
		<CnDetailCard v-if="showRoutingSuggestions" :title="t('pipelinq', 'Routing')">
			<RoutingSuggestionPanel
				:request-id="requestData.id"
				:category="requestData.category"
				entity-type="request"
				@assigned="onRoutingAssign" />
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Pipeline')">
			<div v-if="pipelineData">
				<p class="pipeline-name">
					{{ pipelineData.title }}
				</p>

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
			<p v-else class="section-empty">
				{{ t('pipelinq', 'Not on pipeline') }}
			</p>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Contactmomenten')">
			<template #actions>
				<NcButton @click="showContactmomentQuickLog = true">
					{{ t('pipelinq', 'Log contactmoment') }}
				</NcButton>
			</template>

			<div v-if="contactmomenten.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'Geen contactmomenten geregistreerd') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Subject') }}</th>
							<th>{{ t('pipelinq', 'Channel') }}</th>
							<th>{{ t('pipelinq', 'Agent') }}</th>
							<th>{{ t('pipelinq', 'Date') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="cm in contactmomenten"
							:key="cm.id"
							class="viewTableRow"
							@click="$router.push({ name: 'ContactmomentDetail', params: { id: cm.id } })">
							<td>{{ cm.subject || '-' }}</td>
							<td>{{ cm.channel || '-' }}</td>
							<td>{{ cm.agent || '-' }}</td>
							<td>{{ formatDatetime(cm.contactedAt) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</CnDetailCard>

		<!-- Activity timeline -->
		<CnDetailCard v-if="!isNew" :title="t('pipelinq', 'Activity')">
			<ActivityTimeline :entity-type="'request'" :entity-id="requestId" />
		</CnDetailCard>

		<!-- Contactmoment quick-log dialog -->
		<NcDialog
			v-if="showContactmomentQuickLog"
			:name="t('pipelinq', 'Log contactmoment')"
			size="normal"
			@closing="showContactmomentQuickLog = false">
			<ContactmomentQuickLog
				:client-id="requestData.client || null"
				:request-id="requestId"
				:inline="true"
				@saved="onContactmomentSaved"
				@cancel="showContactmomentQuickLog = false" />
		</NcDialog>

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
	</CnDetailPage>
</template>

<script>
import { NcButton, NcDialog, NcSelect } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import RequestForm from './RequestForm.vue'
import RoutingSuggestionPanel from '../../components/RoutingSuggestionPanel.vue'
import ContactmomentQuickLog from '../../components/ContactmomentQuickLog.vue'
import ActivityTimeline from '../../components/ActivityTimeline.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { useQueuesStore } from '../../store/modules/queues.js'
import {
	getAllowedTransitions,
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
		NcSelect,
		CnDetailPage,
		CnDetailCard,
		RequestForm,
		RoutingSuggestionPanel,
		ContactmomentQuickLog,
		ActivityTimeline,
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
			showContactmomentQuickLog: false,
			clientData: null,
			pipelineData: null,
			queueData: null,
			allQueues: [],
			contactmomenten: [],
			users: [],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.requestId || this.requestId === 'new'
		},
		preLinkedClient() {
			return this.$route.query.client || null
		},
		loading() {
			return this.objectStore.loading.request || false
		},
		requestData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('request', this.requestId) || {}
		},
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.request || {}
			return {
				title: t('pipelinq', 'Request'),
				register: config.register || '',
				schema: config.schema || '',
			}
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
		queueOption() {
			if (!this.queueData) return null
			return { value: this.queueData.id, label: this.queueData.title }
		},
		queueOptions() {
			return this.allQueues
				.filter(q => q.isActive !== false)
				.map(q => ({ value: q.id, label: q.title }))
		},
		showRoutingSuggestions() {
			return !this.isNew && !this.isConverted && (this.requestData.queue || this.requestData.category)
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
			if (this.requestData.queue) {
				const queue = await this.objectStore.fetchObject('queue', this.requestData.queue)
				this.queueData = queue || null
			}
			// Fetch all queues for the dropdown
			const queuesStore = useQueuesStore()
			await queuesStore.fetchQueues()
			this.allQueues = queuesStore.queues
			try {
				const allContactmomenten = await this.objectStore.fetchCollection('contactmoment', {
					_limit: 50,
					request: this.requestId,
					_order: { contactedAt: 'desc' },
				})
				this.contactmomenten = allContactmomenten || []
			} catch {
				this.contactmomenten = []
			}
		},
		formatDatetime(dateStr) {
			if (!dateStr) return '-'
			try {
				return new Date(dateStr).toLocaleString()
			} catch {
				return dateStr
			}
		},
		async onContactmomentSaved() {
			this.showContactmomentQuickLog = false
			try {
				const allContactmomenten = await this.objectStore.fetchCollection('contactmoment', {
					_limit: 50,
					request: this.requestId,
					_order: { contactedAt: 'desc' },
				})
				this.contactmomenten = allContactmomenten || []
			} catch {
				this.contactmomenten = []
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

		async onQueueChange(queueId) {
			await this.objectStore.saveObject('request', {
				...this.requestData,
				queue: queueId || null,
			})
			await this.objectStore.fetchObject('request', this.requestId)
			if (queueId) {
				const queue = await this.objectStore.fetchObject('queue', queueId)
				this.queueData = queue || null
			} else {
				this.queueData = null
			}
		},

		async onRoutingAssign(userId) {
			await this.objectStore.saveObject('request', {
				...this.requestData,
				assignee: userId,
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
					this.$router.push({ name: 'RequestDetail', params: { id: result.id } })
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
				this.$router.push({ name: 'Requests' })
			} else {
				this.editing = false
			}
		},

		async confirmDelete() {
			this.showDeleteDialog = false
			const success = await this.objectStore.deleteObject('request', this.requestId)
			if (success) {
				this.$router.push({ name: 'Requests' })
			} else {
				const error = this.objectStore.getError('request')
				showError(error?.message || t('pipelinq', 'Failed to delete request.'))
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
.request-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
	padding: 20px 20px 0;
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

.info-row {
	display: flex;
	gap: 24px;
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

.queue-link a {
	font-weight: bold;
	color: var(--color-primary);
}

.queue-select {
	margin-top: 8px;
	min-width: 200px;
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
	background-color: var(--color-main-background);
}

.viewTable th,
.viewTable td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.viewTableRow {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background: var(--color-background-hover);
}
</style>
