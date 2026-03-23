<template>
	<div v-if="editing || isNew">
		<div class="lead-detail__header">
			<NcButton @click="onFormCancel">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New lead') }}
			</h2>
			<h2 v-else>
				{{ leadData.title || t('pipelinq', 'Lead') }}
			</h2>
		</div>
		<LeadForm
			:lead="isNew ? null : leadData"
			@save="onFormSave"
			@cancel="onFormCancel" />
	</div>

	<CnDetailPage
		v-else
		:title="leadData.title || t('pipelinq', 'Lead')"
		:subtitle="t('pipelinq', 'Lead')"
		:back-route="{ name: 'Leads' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="!isNew && !loading"
		object-type="pipelinq_lead"
		:object-id="leadId"
		:sidebar-props="sidebarProps">
		<template #header-actions>
			<NcButton type="primary" @click="editing = true">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton type="error" @click="showDeleteDialog = true">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<!-- Core Info -->
		<CnDetailCard :title="t('pipelinq', 'Core Info')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Value') }}</label>
					<span>{{ formatValue(leadData.value) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Probability') }}</label>
					<span>{{ leadData.probability != null ? leadData.probability + '%' : '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Source') }}</label>
					<span>{{ leadData.source || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Priority') }}</label>
					<span :class="priorityClass">{{ leadData.priority || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Expected Close') }}</label>
					<span>{{ leadData.expectedCloseDate || '-' }}</span>
					<span v-if="overdueDays > 0" class="overdue-badge">
						{{ t('pipelinq', '{days} days overdue', { days: overdueDays }) }}
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Category') }}</label>
					<span>{{ leadData.category || '-' }}</span>
				</div>
			</div>

			<div v-if="leadData.description" class="info-field info-field--full">
				<label>{{ t('pipelinq', 'Description') }}</label>
				<p>{{ leadData.description }}</p>
			</div>
		</CnDetailCard>

		<!-- Client -->
		<CnDetailCard :title="t('pipelinq', 'Client')">
			<div v-if="clientData" class="client-link">
				<a href="#" @click.prevent="$router.push({ name: 'ClientDetail', params: { id: clientData.id } })">
					{{ clientData.name }}
				</a>
				<span v-if="clientData.email" class="client-meta">{{ clientData.email }}</span>
			</div>
			<p v-else-if="leadData.client" class="section-empty orphaned-ref">
				{{ t('pipelinq', '[Deleted client]') }}
			</p>
			<p v-else class="section-empty">
				{{ t('pipelinq', 'No client linked') }}
			</p>
		</CnDetailCard>

		<!-- Contact -->
		<CnDetailCard v-if="contactData || leadData.contact" :title="t('pipelinq', 'Contact')">
			<div v-if="contactData" class="contact-info">
				<strong>{{ contactData.name }}</strong>
				<span v-if="contactData.role" class="contact-meta">{{ contactData.role }}</span>
				<span v-if="contactData.email" class="contact-meta">{{ contactData.email }}</span>
			</div>
			<p v-else class="section-empty orphaned-ref">
				{{ t('pipelinq', '[Deleted contact]') }}
			</p>
		</CnDetailCard>

		<!-- Pipeline -->
		<CnDetailCard v-if="pipelineData" :title="t('pipelinq', 'Pipeline')">
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
		</CnDetailCard>

		<!-- Products -->
		<CnDetailCard :title="t('pipelinq', 'Products')">
			<LeadProducts
				:lead-id="leadId"
				:lead-value="Number(leadData.value) || null"
				@value-changed="onProductValueChanged"
				@sync-value="syncLeadValue" />
		</CnDetailCard>

		<CnDetailCard v-if="!isNew" :title="t('pipelinq', 'Activity Timeline')">
			<ActivityTimeline
				entity-type="lead"
				:entity-id="leadId" />
		</CnDetailCard>

		<!-- Delete dialog -->
		<NcDialog
			v-if="showDeleteDialog"
			:name="t('pipelinq', 'Delete lead')"
			@closing="showDeleteDialog = false">
			<p>{{ t('pipelinq', 'Are you sure you want to delete "{title}"?', { title: leadData.title }) }}</p>
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
import { NcButton, NcDialog } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import LeadForm from './LeadForm.vue'
import LeadProducts from '../../components/LeadProducts.vue'
import ActivityTimeline from '../../components/ActivityTimeline.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatCurrency } from '../../services/localeUtils.js'

export default {
	name: 'LeadDetail',
	components: {
		NcButton,
		NcDialog,
		CnDetailPage,
		CnDetailCard,
		LeadForm,
		LeadProducts,
		ActivityTimeline,
	},
	props: {
		leadId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			editing: false,
			showDeleteDialog: false,
			clientData: null,
			contactData: null,
			pipelineData: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.leadId || this.leadId === 'new'
		},
		loading() {
			return this.objectStore.loading.lead || false
		},
		leadData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('lead', this.leadId) || {}
		},
		sortedStages() {
			if (!this.pipelineData?.stages) return []
			return [...this.pipelineData.stages].sort((a, b) => a.order - b.order)
		},
		currentStageOrder() {
			if (!this.leadData.stage || !this.sortedStages.length) return -1
			const stage = this.sortedStages.find(s => s.name === this.leadData.stage)
			return stage ? stage.order : -1
		},
		priorityClass() {
			const p = this.leadData.priority
			if (p === 'urgent') return 'priority-urgent'
			if (p === 'high') return 'priority-high'
			return ''
		},
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.lead || {}
			return {
				register: config.register || '',
				schema: config.schema || '',
				hiddenTabs: ['tasks'],
			}
		},
		overdueDays() {
			if (!this.leadData.expectedCloseDate) return 0
			if (this.leadData.status === 'won' || this.leadData.status === 'lost') return 0
			const closeDate = new Date(this.leadData.expectedCloseDate)
			const today = new Date()
			today.setHours(0, 0, 0, 0)
			closeDate.setHours(0, 0, 0, 0)
			const diff = Math.floor((today - closeDate) / (1000 * 60 * 60 * 24))
			return diff > 0 ? diff : 0
		},
	},
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('lead', this.leadId)
			await this.fetchRelated()
		}
	},
	methods: {
		async fetchRelated() {
			// Fetch client
			if (this.leadData.client) {
				const client = await this.objectStore.fetchObject('client', this.leadData.client)
				this.clientData = client || null
			}

			// Fetch contact
			if (this.leadData.contact) {
				const contact = await this.objectStore.fetchObject('contact', this.leadData.contact)
				this.contactData = contact || null
			}

			// Fetch pipeline
			if (this.leadData.pipeline) {
				const pipeline = await this.objectStore.fetchObject('pipeline', this.leadData.pipeline)
				this.pipelineData = pipeline || null
			}
		},
		stageClass(stage) {
			if (this.currentStageOrder < 0) return ''
			if (stage.order < this.currentStageOrder) return 'stage-completed'
			if (stage.order === this.currentStageOrder) return 'stage-current'
			return 'stage-future'
		},
		formatValue(value) {
			if (value === null || value === undefined) return '-'
			return formatCurrency(value)
		},
		async onFormSave(formData) {
			const result = await this.objectStore.saveObject('lead', formData)
			if (result) {
				if (this.isNew) {
					this.$router.push({ name: 'LeadDetail', params: { id: result.id } })
				} else {
					await this.objectStore.fetchObject('lead', this.leadId)
					await this.fetchRelated()
					this.editing = false
				}
			} else {
				const error = this.objectStore.getError('lead')
				showError(error?.message || t('pipelinq', 'Failed to save lead. Please try again.'))
			}
		},
		onFormCancel() {
			if (this.isNew) {
				this.$router.push({ name: 'Leads' })
			} else {
				this.editing = false
			}
		},
		async confirmDelete() {
			this.showDeleteDialog = false
			const success = await this.objectStore.deleteObject('lead', this.leadId)
			if (success) {
				this.$router.push({ name: 'Leads' })
			} else {
				const error = this.objectStore.getError('lead')
				showError(error?.message || t('pipelinq', 'Failed to delete lead.'))
			}
		},
		async onProductValueChanged(newTotal) {
			// Auto-update lead value if no manual value was set or if it matches previous auto-calc
			if (!this.leadData.value || Number(this.leadData.value) === 0) {
				await this.syncLeadValue(newTotal)
			}
		},
		async syncLeadValue(value) {
			await this.objectStore.saveObject('lead', {
				id: this.leadId,
				value,
			})
			await this.objectStore.fetchObject('lead', this.leadId)
		},
	},
}
</script>

<style scoped>
.lead-detail__header {
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
}

.priority-urgent {
	color: var(--color-error);
	font-weight: bold;
}

.priority-high {
	color: var(--color-warning);
	font-weight: bold;
}

/* Client / Contact links */
.client-link a {
	font-weight: bold;
	color: var(--color-primary);
}

.client-meta,
.contact-meta {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.contact-info {
	line-height: 1.5;
}

.section-empty {
	color: var(--color-text-maxcontrast);
}

.orphaned-ref {
	font-style: italic;
	color: var(--color-warning);
}

/* Pipeline progress */
.pipeline-name {
	color: var(--color-text-maxcontrast);
	margin: 0 0 12px;
	font-size: 13px;
}

.pipeline-progress {
	display: flex;
	flex-direction: column;
	gap: 0;
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

/* Connector line between stages */
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

.overdue-badge {
	display: inline-block;
	padding: 2px 8px;
	background: #fef2f2;
	color: var(--color-error);
	border: 1px solid #fecaca;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	margin-left: 8px;
}
</style>
