<template>
	<div v-if="editing || isNew">
		<div class="complaint-detail__header">
			<NcButton @click="onFormCancel">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New complaint') }}
			</h2>
			<h2 v-else>
				{{ complaintData.title || t('pipelinq', 'Complaint') }}
			</h2>
		</div>
		<ComplaintForm
			:complaint="isNew ? null : complaintData"
			:pre-linked-client="preLinkedClient"
			@save="onFormSave"
			@cancel="onFormCancel" />
	</div>

	<CnDetailPage
		v-else
		:title="complaintData.title || t('pipelinq', 'Complaint')"
		:subtitle="t('pipelinq', 'Complaint')"
		:back-route="{ name: 'Complaints' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="!isNew && !loading"
		object-type="pipelinq_complaint"
		:object-id="complaintId"
		:sidebar-props="sidebarProps">
		<template #header-actions>
			<NcButton v-if="!isTerminal" type="primary" @click="editing = true">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton
				type="error"
				@click="showDeleteDialog = true">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Status & Priority')">
			<div class="info-row">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<div class="status-control">
						<span
							class="status-badge"
							:style="{ background: getStatusColor(complaintData.status), color: '#fff' }">
							{{ getStatusLabel(complaintData.status) }}
						</span>
						<template v-if="statusTransitions.length > 0">
							<NcButton
								v-for="transition in statusTransitions"
								:key="transition"
								:type="getTransitionButtonType(transition)"
								size="small"
								@click="onStatusTransition(transition)">
								{{ getTransitionLabel(transition) }}
							</NcButton>
						</template>
					</div>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Priority') }}</label>
					<span
						class="priority-badge"
						:style="{ color: getPriorityColor(complaintData.priority) }">
						{{ getPriorityLabel(complaintData.priority) }}
					</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Complaint Information')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Category') }}</label>
					<span>{{ getCategoryLabel(complaintData.category) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Channel') }}</label>
					<span>{{ complaintData.channel ? getChannelLabel(complaintData.channel) : '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'SLA Deadline') }}</label>
					<span
						v-if="complaintData.slaDeadline"
						:style="{ color: getSlaColor(slaIndicator), fontWeight: 600 }">
						{{ formatDateTime(complaintData.slaDeadline) }}
						<span v-if="slaIndicator === 'overdue'" class="sla-tag sla-tag--overdue">
							{{ t('pipelinq', 'OVERDUE') }}
						</span>
						<span v-else-if="slaIndicator === 'approaching'" class="sla-tag sla-tag--approaching">
							{{ t('pipelinq', 'APPROACHING') }}
						</span>
						<span v-else-if="slaIndicator === 'met'" class="sla-tag sla-tag--met">
							{{ t('pipelinq', 'MET') }}
						</span>
					</span>
					<span v-else>-</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Assigned to') }}</label>
					<span>{{ complaintData.assignedTo || t('pipelinq', 'Unassigned') }}</span>
				</div>
			</div>

			<div v-if="complaintData.description" class="info-field info-field--full">
				<label>{{ t('pipelinq', 'Description') }}</label>
				<p class="description-text">
					{{ complaintData.description }}
				</p>
			</div>
		</CnDetailCard>

		<!-- Resolution (only for resolved/rejected) -->
		<CnDetailCard v-if="complaintData.resolution" :title="t('pipelinq', 'Resolution')">
			<div class="info-field">
				<label>{{ t('pipelinq', 'Resolved at') }}</label>
				<span>{{ formatDateTime(complaintData.resolvedAt) }}</span>
			</div>
			<div class="info-field info-field--full">
				<label>{{ t('pipelinq', 'Resolution') }}</label>
				<p class="description-text">
					{{ complaintData.resolution }}
				</p>
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
			<p v-else-if="complaintData.client" class="section-empty orphaned-ref">
				{{ t('pipelinq', '[Deleted client]') }}
			</p>
			<p v-else class="section-empty">
				{{ t('pipelinq', 'No client linked') }}
			</p>

			<div v-if="contactData" class="contact-link">
				<label>{{ t('pipelinq', 'Contact person') }}</label>
				<a href="#" @click.prevent="$router.push({ name: 'ContactDetail', params: { id: contactData.id } })">
					{{ contactData.name }}
				</a>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Assignment')">
			<template #header-actions>
				<NcSelect
					:value="assigneeOption"
					:options="userOptions"
					:clearable="true"
					label="label"
					:reduce="o => o.value"
					:placeholder="t('pipelinq', 'Assign to user')"
					:filterable="true"
					class="assignment-select"
					@input="onAssigneeChange" />
			</template>
		</CnDetailCard>

		<!-- Audit Trail / Status History -->
		<CnDetailCard :title="t('pipelinq', 'Status History')">
			<div v-if="statusHistory.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No status changes yet') }}</p>
			</div>
			<div v-else class="timeline">
				<div
					v-for="(entry, index) in statusHistory"
					:key="index"
					class="timeline-entry">
					<span class="timeline-dot" :style="{ background: getStatusColor(entry.to) }" />
					<div class="timeline-content">
						<span class="timeline-label">
							{{ getStatusLabel(entry.from) }} → {{ getStatusLabel(entry.to) }}
						</span>
						<span class="timeline-meta">
							{{ entry.actor || t('pipelinq', 'System') }} · {{ formatDateTime(entry.timestamp) }}
						</span>
					</div>
				</div>
			</div>
		</CnDetailCard>

		<!-- Resolution dialog -->
		<NcDialog
			v-if="showResolutionDialog"
			:name="resolutionDialogTitle"
			@closing="showResolutionDialog = false">
			<div class="resolution-form">
				<label>{{ t('pipelinq', 'Resolution text') }} *</label>
				<textarea
					v-model="resolutionText"
					class="resolution-textarea"
					:placeholder="t('pipelinq', 'Explain how the complaint was resolved or why it was rejected...')"
					rows="4" />
			</div>
			<template #actions>
				<NcButton @click="showResolutionDialog = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton
					:type="pendingTransition === 'rejected' ? 'error' : 'primary'"
					:disabled="!resolutionText.trim()"
					@click="confirmResolution">
					{{ pendingTransition === 'rejected' ? t('pipelinq', 'Reject') : t('pipelinq', 'Resolve') }}
				</NcButton>
			</template>
		</NcDialog>

		<!-- Delete dialog -->
		<NcDialog
			v-if="showDeleteDialog"
			:name="t('pipelinq', 'Delete complaint')"
			@closing="showDeleteDialog = false">
			<p>{{ t('pipelinq', 'Are you sure you want to delete "{title}"?', { title: complaintData.title }) }}</p>
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
import ComplaintForm from './ComplaintForm.vue'
import { useObjectStore } from '../../store/modules/object.js'
import {
	getAllowedTransitions,
	getStatusLabel,
	getStatusColor,
	getPriorityLabel,
	getPriorityColor,
	getCategoryLabel,
	getChannelLabel,
	getSlaIndicator,
	getSlaColor,
	isTerminalStatus,
	requiresResolution,
} from '../../services/complaintStatus.js'

export default {
	name: 'ComplaintDetail',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
		CnDetailPage,
		CnDetailCard,
		ComplaintForm,
	},
	props: {
		complaintId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			editing: false,
			showDeleteDialog: false,
			showResolutionDialog: false,
			pendingTransition: null,
			resolutionText: '',
			clientData: null,
			contactData: null,
			users: [],
			statusHistory: [],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.complaintId || this.complaintId === 'new'
		},
		preLinkedClient() {
			return this.$route.query.client || null
		},
		loading() {
			return this.objectStore.loading.complaint || false
		},
		complaintData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('complaint', this.complaintId) || {}
		},
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.complaint || {}
			return {
				title: t('pipelinq', 'Complaint'),
				register: config.register || '',
				schema: config.schema || '',
			}
		},
		isTerminal() {
			return isTerminalStatus(this.complaintData.status)
		},
		statusTransitions() {
			return getAllowedTransitions(this.complaintData.status)
		},
		slaIndicator() {
			return getSlaIndicator(this.complaintData.slaDeadline, this.complaintData.status)
		},
		assigneeOption() {
			if (!this.complaintData.assignedTo) return null
			const user = this.users.find(u => u.value === this.complaintData.assignedTo)
			return user || { value: this.complaintData.assignedTo, label: this.complaintData.assignedTo }
		},
		userOptions() {
			return this.users
		},
		resolutionDialogTitle() {
			return this.pendingTransition === 'rejected'
				? t('pipelinq', 'Reject complaint')
				: t('pipelinq', 'Resolve complaint')
		},
	},
	async mounted() {
		this.fetchUsers()
		if (!this.isNew) {
			await this.objectStore.fetchObject('complaint', this.complaintId)
			await this.fetchRelated()
			this.buildStatusHistory()
		}
	},
	methods: {
		getStatusLabel,
		getStatusColor,
		getPriorityLabel,
		getPriorityColor,
		getCategoryLabel,
		getChannelLabel,
		getSlaIndicator,
		getSlaColor,

		getTransitionLabel(status) {
			if (status === 'in_progress') return t('pipelinq', 'In behandeling nemen')
			if (status === 'resolved') return t('pipelinq', 'Afhandelen')
			if (status === 'rejected') return t('pipelinq', 'Afwijzen')
			return getStatusLabel(status)
		},

		getTransitionButtonType(status) {
			if (status === 'rejected') return 'error'
			if (status === 'resolved') return 'success'
			return 'secondary'
		},

		onStatusTransition(targetStatus) {
			if (requiresResolution(targetStatus)) {
				this.pendingTransition = targetStatus
				this.resolutionText = ''
				this.showResolutionDialog = true
			} else {
				this.applyStatusChange(targetStatus)
			}
		},

		async confirmResolution() {
			if (!this.resolutionText.trim()) return

			await this.applyStatusChange(this.pendingTransition, {
				resolution: this.resolutionText.trim(),
				resolvedAt: new Date().toISOString(),
			})

			this.showResolutionDialog = false
			this.pendingTransition = null
			this.resolutionText = ''
		},

		async applyStatusChange(newStatus, extraData = {}) {
			const previousStatus = this.complaintData.status

			await this.objectStore.saveObject('complaint', {
				...this.complaintData,
				status: newStatus,
				...extraData,
			})
			await this.objectStore.fetchObject('complaint', this.complaintId)

			// Add to local status history
			this.statusHistory.unshift({
				from: previousStatus,
				to: newStatus,
				timestamp: new Date().toISOString(),
				actor: OC.currentUser || 'Unknown',
			})
		},

		async fetchRelated() {
			if (this.complaintData.client) {
				const client = await this.objectStore.fetchObject('client', this.complaintData.client)
				this.clientData = client || null
			}
			if (this.complaintData.contact) {
				const contact = await this.objectStore.fetchObject('contact', this.complaintData.contact)
				this.contactData = contact || null
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

		buildStatusHistory() {
			// Try to build from audit trail if available
			const auditTrail = this.complaintData._auditTrail || this.complaintData.auditTrail
			if (Array.isArray(auditTrail)) {
				this.statusHistory = auditTrail
					.filter(entry => entry.changes?.status || entry.field === 'status')
					.map(entry => ({
						from: entry.changes?.status?.from || entry.from || 'unknown',
						to: entry.changes?.status?.to || entry.to || 'unknown',
						timestamp: entry.timestamp || entry.dateCreated,
						actor: entry.actor || entry.userId || 'System',
					}))
					.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp))
				return
			}

			// Fallback: show creation as the only entry if complaint exists
			if (this.complaintData.status) {
				this.statusHistory = [{
					from: '-',
					to: this.complaintData.status,
					timestamp: this.complaintData._dateCreated || this.complaintData.dateCreated || new Date().toISOString(),
					actor: t('pipelinq', 'Created'),
				}]
			}
		},

		async onAssigneeChange(userId) {
			await this.objectStore.saveObject('complaint', {
				...this.complaintData,
				assignedTo: userId || null,
			})
			await this.objectStore.fetchObject('complaint', this.complaintId)
		},

		async onFormSave(formData) {
			const result = await this.objectStore.saveObject('complaint', formData)
			if (result) {
				if (this.isNew) {
					this.$router.push({ name: 'ComplaintDetail', params: { id: result.id } })
				} else {
					await this.objectStore.fetchObject('complaint', this.complaintId)
					await this.fetchRelated()
					this.editing = false
				}
			} else {
				const error = this.objectStore.getError('complaint')
				showError(error?.message || t('pipelinq', 'Failed to save complaint. Please try again.'))
			}
		},

		onFormCancel() {
			if (this.isNew) {
				this.$router.push({ name: 'Complaints' })
			} else {
				this.editing = false
			}
		},

		async confirmDelete() {
			this.showDeleteDialog = false
			const success = await this.objectStore.deleteObject('complaint', this.complaintId)
			if (success) {
				this.$router.push({ name: 'Complaints' })
			} else {
				const error = this.objectStore.getError('complaint')
				showError(error?.message || t('pipelinq', 'Failed to delete complaint.'))
			}
		},

		formatDateTime(dateStr) {
			if (!dateStr) return '-'
			try {
				return new Date(dateStr).toLocaleString()
			} catch {
				return dateStr
			}
		},
	},
}
</script>

<style scoped>
.complaint-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
	padding: 20px 20px 0;
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

.description-text {
	white-space: pre-wrap;
	line-height: 1.5;
}

.status-control {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.status-badge {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.priority-badge {
	font-weight: 600;
	font-size: 14px;
}

.sla-tag {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.5px;
	margin-left: 6px;
}

.sla-tag--overdue {
	background: rgba(233, 50, 45, 0.1);
	color: #e9322d;
	border: 1px solid rgba(233, 50, 45, 0.3);
}

.sla-tag--approaching {
	background: rgba(233, 164, 0, 0.1);
	color: #e9a400;
	border: 1px solid rgba(233, 164, 0, 0.3);
}

.sla-tag--met {
	background: rgba(70, 186, 97, 0.1);
	color: #46ba61;
	border: 1px solid rgba(70, 186, 97, 0.3);
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

.contact-link {
	margin-top: 12px;
}

.contact-link a {
	color: var(--color-primary);
	font-weight: 500;
}

.section-empty {
	color: var(--color-text-maxcontrast);
}

.orphaned-ref {
	font-style: italic;
	color: var(--color-warning);
}

/* Timeline */
.timeline {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.timeline-entry {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	position: relative;
}

.timeline-dot {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex-shrink: 0;
	margin-top: 4px;
}

.timeline-content {
	display: flex;
	flex-direction: column;
}

.timeline-label {
	font-size: 13px;
	font-weight: 500;
}

.timeline-meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

/* Resolution dialog */
.resolution-form {
	padding: 8px 0;
}

.resolution-form label {
	display: block;
	font-weight: bold;
	margin-bottom: 8px;
}

.resolution-textarea {
	width: 100%;
	padding: 8px 12px;
	border: 2px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius);
	font-family: inherit;
	font-size: 14px;
	resize: vertical;
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.resolution-textarea:focus {
	border-color: var(--color-primary-element);
	outline: none;
}
</style>
