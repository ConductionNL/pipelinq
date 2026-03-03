<template>
	<div class="lead-detail">
		<div class="lead-detail__header">
			<NcButton @click="$router.push({ name: 'Leads' })">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New lead') }}
			</h2>
			<h2 v-else>
				{{ leadData.title || t('pipelinq', 'Lead') }}
			</h2>
		</div>

		<NcLoadingIcon v-if="loading" />

		<!-- Edit / Create mode -->
		<LeadForm
			v-else-if="editing || isNew"
			:lead="isNew ? null : leadData"
			@save="onFormSave"
			@cancel="onFormCancel" />

		<!-- View mode -->
		<div v-else class="lead-detail__content">
			<div class="lead-detail__actions">
				<NcButton type="primary" @click="editing = true">
					{{ t('pipelinq', 'Edit') }}
				</NcButton>
				<NcButton type="error" @click="showDeleteDialog = true">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</div>

			<div class="lead-detail__layout">
				<!-- Left column: info -->
				<div class="lead-detail__info">
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

					<!-- Client link -->
					<div class="lead-detail__section">
						<h3>{{ t('pipelinq', 'Client') }}</h3>
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
					</div>

					<!-- Contact display -->
					<div v-if="contactData" class="lead-detail__section">
						<h3>{{ t('pipelinq', 'Contact') }}</h3>
						<div class="contact-info">
							<strong>{{ contactData.name }}</strong>
							<span v-if="contactData.role" class="contact-meta">{{ contactData.role }}</span>
							<span v-if="contactData.email" class="contact-meta">{{ contactData.email }}</span>
						</div>
					</div>
					<div v-else-if="leadData.contact" class="lead-detail__section">
						<h3>{{ t('pipelinq', 'Contact') }}</h3>
						<p class="section-empty orphaned-ref">{{ t('pipelinq', '[Deleted contact]') }}</p>
					</div>
				</div>

				<!-- Right column: pipeline progress -->
				<div v-if="pipelineData" class="lead-detail__pipeline">
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
				</div>
			</div>
		</div>

		<!-- Notes section -->
		<EntityNotes
			v-if="!isNew && !loading && !editing"
			object-type="pipelinq_lead"
			:object-id="leadId" />

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
	</div>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import LeadForm from './LeadForm.vue'
import EntityNotes from '../../components/EntityNotes.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'LeadDetail',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		LeadForm,
		EntityNotes,
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
			return 'EUR ' + Number(value).toLocaleString('nl-NL')
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
			this.cleanupNotes('pipelinq_lead', this.leadId)
			const success = await this.objectStore.deleteObject('lead', this.leadId)
			if (success) {
				this.$router.push({ name: 'Leads' })
			} else {
				const error = this.objectStore.getError('lead')
				showError(error?.message || t('pipelinq', 'Failed to delete lead.'))
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
	},
}
</script>

<style scoped>
.lead-detail {
	padding: 20px;
	max-width: 900px;
}

.lead-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
}

.lead-detail__actions {
	display: flex;
	gap: 12px;
	margin-bottom: 20px;
}

.lead-detail__layout {
	display: flex;
	gap: 32px;
}

.lead-detail__info {
	flex: 1;
	min-width: 0;
}

.lead-detail__pipeline {
	width: 240px;
	flex-shrink: 0;
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
.lead-detail__section {
	margin-top: 24px;
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.lead-detail__section h3 {
	margin: 0 0 8px;
}

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
</style>
