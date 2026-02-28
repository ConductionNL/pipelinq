<template>
	<div class="pipeline-manager">
		<div class="pipeline-header">
			<h3>{{ t('pipelinq', 'Pipelines') }}</h3>
			<NcButton type="primary" @click="showForm = true; editingPipeline = null">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('pipelinq', 'Add pipeline') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcEmptyContent v-else-if="pipelines.length === 0"
			:name="t('pipelinq', 'No pipelines configured')"
			:description="t('pipelinq', 'Create a pipeline to start tracking leads and requests through stages.')">
			<template #icon>
				<ViewColumn :size="20" />
			</template>
			<template #action>
				<NcButton type="primary" @click="showForm = true; editingPipeline = null">
					{{ t('pipelinq', 'Create first pipeline') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<div v-else class="pipeline-list">
			<div v-for="pipeline in pipelines"
				:key="pipeline.id"
				class="pipeline-card">
				<div class="pipeline-info">
					<div class="pipeline-title-row">
						<Star v-if="pipeline.isDefault" :size="16" class="default-star" />
						<strong>{{ pipeline.title }}</strong>
						<span class="entity-type-badge">{{ entityTypeLabel(pipeline.entityType) }}</span>
					</div>
					<div class="pipeline-meta">
						{{ stageCount(pipeline) }} &middot; {{ stagePreview(pipeline) }}
					</div>
				</div>
				<div class="pipeline-actions">
					<NcButton type="tertiary" @click="onEdit(pipeline)">
						<template #icon>
							<Pencil :size="20" />
						</template>
					</NcButton>
					<NcButton type="tertiary" @click="onDeleteClick(pipeline)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
		</div>

		<PipelineForm v-if="showForm"
			:pipeline="editingPipeline"
			@save="onSave"
			@cancel="showForm = false; editingPipeline = null" />

		<NcDialog v-if="deletingPipeline"
			:name="t('pipelinq', 'Delete pipeline')"
			@closing="deletingPipeline = null">
			<p>{{ t('pipelinq', 'Are you sure you want to delete "{title}"?', { title: deletingPipeline.title }) }}</p>
			<p v-if="deleteAffectedCount > 0" class="delete-warning">
				{{ t('pipelinq', '{count} leads/requests are on this pipeline. They will be removed from the pipeline but not deleted.', { count: deleteAffectedCount }) }}
			</p>
			<p v-if="deletingPipeline.stages && deletingPipeline.stages.length > 0" class="delete-warning">
				{{ t('pipelinq', 'This pipeline has {count} stages. All stage configuration will be lost.', { count: deletingPipeline.stages.length }) }}
			</p>
			<template #actions>
				<NcButton type="tertiary" @click="deletingPipeline = null">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="onDeleteConfirm">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { useObjectStore } from '../../store/modules/object.js'
import PipelineForm from './PipelineForm.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Star from 'vue-material-design-icons/Star.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'

export default {
	name: 'PipelineManager',
	components: {
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		PipelineForm,
		Delete,
		Pencil,
		Plus,
		Star,
		ViewColumn,
	},
	data() {
		return {
			showForm: false,
			editingPipeline: null,
			deletingPipeline: null,
			deleteAffectedCount: 0,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		pipelines() {
			return this.objectStore.collections.pipeline || []
		},
		loading() {
			return this.objectStore.loading.pipeline || false
		},
	},
	async mounted() {
		await this.objectStore.fetchCollection('pipeline', { _limit: 100 })
	},
	methods: {
		entityTypeLabel(type) {
			const labels = {
				lead: t('pipelinq', 'Leads'),
				request: t('pipelinq', 'Requests'),
				both: t('pipelinq', 'Leads & Requests'),
			}
			return labels[type] || type
		},
		stageCount(pipeline) {
			const count = (pipeline.stages || []).length
			return n('pipelinq', '%n stage', '%n stages', count)
		},
		stagePreview(pipeline) {
			const stages = pipeline.stages || []
			if (stages.length === 0) return t('pipelinq', 'No stages')
			const sorted = [...stages].sort((a, b) => a.order - b.order)
			if (sorted.length <= 5) {
				return sorted.map(s => s.name).join(' → ')
			}
			const first = sorted.slice(0, 2).map(s => s.name)
			const last = sorted.slice(-2).map(s => s.name)
			return [...first, '...', ...last].join(' → ')
		},
		onEdit(pipeline) {
			this.editingPipeline = pipeline
			this.showForm = true
		},
		async onDeleteClick(pipeline) {
			// W1: Prevent deleting the default pipeline
			if (pipeline.isDefault) {
				showError(t('pipelinq', 'Cannot delete the default pipeline. Set another pipeline as default first.'))
				return
			}

			// W4: Count affected leads/requests before showing dialog
			this.deleteAffectedCount = 0
			try {
				const count = await this.countAffectedItems(pipeline.id)
				this.deleteAffectedCount = count
			} catch (e) {
				// Non-blocking — show dialog even if count fails
			}

			this.deletingPipeline = pipeline
		},
		async onDeleteConfirm() {
			const id = this.deletingPipeline.id
			this.deletingPipeline = null
			this.deleteAffectedCount = 0
			await this.objectStore.deleteObject('pipeline', id)
			await this.objectStore.fetchCollection('pipeline', { _limit: 100 })
		},
		async onSave(pipelineData) {
			// W5: Auto-set first pipeline as default
			const isFirstPipeline = this.pipelines.length === 0
				|| (this.pipelines.length === 1 && pipelineData.id === this.pipelines[0].id)
			if (isFirstPipeline && !pipelineData.id) {
				pipelineData.isDefault = true
			}

			// S3: Prevent unsetting default without a replacement
			if (!pipelineData.isDefault && pipelineData.id) {
				const currentPipeline = this.pipelines.find(p => p.id === pipelineData.id)
				if (currentPipeline && currentPipeline.isDefault) {
					const otherDefaults = this.pipelines.filter(
						p => p.entityType === pipelineData.entityType
							&& p.isDefault
							&& p.id !== pipelineData.id,
					)
					if (otherDefaults.length === 0) {
						showError(t('pipelinq', 'At least one pipeline must be set as default'))
						pipelineData.isDefault = true
					}
				}
			}

			// If setting as default, unset isDefault on other pipelines of the same entityType
			if (pipelineData.isDefault) {
				const others = this.pipelines.filter(
					p => p.entityType === pipelineData.entityType
						&& p.isDefault
						&& p.id !== pipelineData.id,
				)
				for (const other of others) {
					await this.objectStore.saveObject('pipeline', { ...other, isDefault: false })
				}
			}

			await this.objectStore.saveObject('pipeline', pipelineData)
			this.showForm = false
			this.editingPipeline = null
			await this.objectStore.fetchCollection('pipeline', { _limit: 100 })
		},
		async countAffectedItems(pipelineId) {
			const headers = {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
				'OCS-APIREQUEST': 'true',
			}
			let total = 0

			// Count leads on this pipeline
			const leadConfig = this.objectStore.objectTypeRegistry.lead
			if (leadConfig) {
				const leadUrl = `/apps/openregister/api/objects/${leadConfig.register}/${leadConfig.schema}?pipeline=${pipelineId}&_limit=1`
				const leadResp = await fetch(leadUrl, { headers })
				if (leadResp.ok) {
					const leadData = await leadResp.json()
					total += leadData.total || 0
				}
			}

			// Count requests on this pipeline
			const requestConfig = this.objectStore.objectTypeRegistry.request
			if (requestConfig) {
				const requestUrl = `/apps/openregister/api/objects/${requestConfig.register}/${requestConfig.schema}?pipeline=${pipelineId}&_limit=1`
				const requestResp = await fetch(requestUrl, { headers })
				if (requestResp.ok) {
					const requestData = await requestResp.json()
					total += requestData.total || 0
				}
			}

			return total
		},
	},
}
</script>

<style scoped>
.pipeline-manager {
	margin-top: 24px;
}

.pipeline-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.pipeline-header h3 {
	margin: 0;
}

.pipeline-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.pipeline-card {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.pipeline-card:hover {
	background: var(--color-background-hover);
}

.pipeline-info {
	flex: 1;
	min-width: 0;
}

.pipeline-title-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.default-star {
	color: var(--color-warning);
	flex-shrink: 0;
}

.entity-type-badge {
	font-size: 12px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.pipeline-meta {
	margin-top: 4px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pipeline-actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.delete-warning {
	color: var(--color-error);
	font-weight: bold;
}
</style>
