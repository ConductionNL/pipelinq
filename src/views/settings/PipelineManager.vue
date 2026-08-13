<template>
	<CnSettingsSection :name="t('pipelinq', 'Pipelines')">
		<template #actions>
			<NcButton variant="primary" @click="onCreate">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('pipelinq', 'Add pipeline') }}
			</NcButton>
		</template>

		<NcLoadingIcon v-if="loading" />

		<NcEmptyContent
			v-else-if="pipelines.length === 0"
			:name="t('pipelinq', 'No pipelines configured')"
			:description="
				t(
					'pipelinq',
					'Create a pipeline to start tracking leads and requests through stages.',
				)
			">
			<template #icon>
				<ViewColumn :size="20" />
			</template>
			<template #action>
				<NcButton variant="primary" @click="onCreate">
					{{ t('pipelinq', 'Create first pipeline') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<div v-else class="pipeline-list">
			<div
				v-for="pipeline in pipelines"
				:key="pipeline.id"
				class="pipeline-card">
				<div class="pipeline-info">
					<div class="pipeline-title-row">
						<Star
							v-if="pipeline.isDefault"
							:size="16"
							class="default-star" />
						<strong>{{ pipeline.title }}</strong>
						<span class="entity-type-badge">{{
							schemaLabel(pipeline)
						}}</span>
					</div>
					<div class="pipeline-meta">
						{{ stageCount(pipeline) }} &middot;
						{{ stagePreview(pipeline) }}
					</div>
				</div>
				<div class="pipeline-actions">
					<NcButton
						variant="tertiary"
						:aria-label="
							t('pipelinq', 'Edit pipeline {title}', {
								title: pipeline.title,
							})
						"
						@click="onEdit(pipeline)">
						<template #icon>
							<Pencil :size="20" />
						</template>
					</NcButton>
					<NcButton
						variant="tertiary"
						:aria-label="
							t('pipelinq', 'Delete pipeline {title}', {
								title: pipeline.title,
							})
						"
						@click="onDeleteClick(pipeline)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
		</div>

		<PipelineFormDialog
			v-if="showForm"
			:pipeline="editingPipeline"
			@save="onSave"
			@cancel="onFormCancel" />

		<DeletePipelineDialog
			v-if="deletingPipeline"
			:pipeline="deletingPipeline"
			:affected-count="deleteAffectedCount"
			@cancel="deletingPipeline = null"
			@confirm="onDeleteConfirm" />
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../../store/modules/object.js'
import { resolveObjectType } from '../../services/pipelineUtils.js'
import PipelineFormDialog from '../../dialogs/PipelineFormDialog.vue'
import DeletePipelineDialog from '../../dialogs/DeletePipelineDialog.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Star from 'vue-material-design-icons/Star.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'

export default {
	name: 'PipelineManager',
	components: {
		CnSettingsSection,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		PipelineFormDialog,
		DeletePipelineDialog,
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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-46
		 */
		objectStore() {
			return useObjectStore()
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-51
		 */
		pipelines() {
			return this.objectStore.collections.pipeline || []
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-45
		 */
		loading() {
			return this.objectStore.loading.pipeline || false
		},
	},
	async mounted() {
		await this.objectStore.fetchCollection('pipeline', { _limit: 100 })
	},
	methods: {
		/**
		 * @param pipeline
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-52
		 */
		schemaLabel(pipeline) {
			const mappings = pipeline.propertyMappings
			if (mappings && mappings.length > 0) {
				return mappings.map((m) => m.schemaSlug).join(', ')
			}
			// Legacy fallback
			const labels = {
				lead: t('pipelinq', 'Leads'),
				request: t('pipelinq', 'Requests'),
				both: t('pipelinq', 'Leads & Requests'),
			}
			return labels[pipeline.entityType] || pipeline.entityType || ''
		},
		/**
		 * @param pipeline
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-53
		 */
		stageCount(pipeline) {
			const count = (pipeline.stages || []).length
			return n('pipelinq', '%n stage', '%n stages', count)
		},
		/**
		 * @param pipeline
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-54
		 */
		stagePreview(pipeline) {
			const stages = pipeline.stages || []
			if (stages.length === 0) return t('pipelinq', 'No stages')
			const sorted = [...stages].sort((a, b) => a.order - b.order)
			if (sorted.length <= 5) {
				return sorted.map((s) => s.name).join(' → ')
			}
			const first = sorted.slice(0, 2).map((s) => s.name)
			const last = sorted.slice(-2).map((s) => s.name)
			return [...first, '...', ...last].join(' → ')
		},
		/**
		 * @param pipeline
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-49
		 */
		onEdit(pipeline) {
			this.editingPipeline = pipeline
			this.showForm = true
		},
		/**
		 * Open the form for a NEW pipeline.
		 *
		 * Extracted from an inline `@click="showForm = true; editingPipeline = null"`.
		 * Vue's template compiler only treats a handler as raw STATEMENTS when it
		 * contains a `;`, and prettier's `semi: false` strips it — leaving two
		 * newline-separated assignments that the compiler then tries to parse as a
		 * single expression and rejects ("Unexpected token, expected ,").
		 *
		 * @spec exclude formatting-only extraction of an existing inline handler — no behaviour change
		 */
		onCreate() {
			this.editingPipeline = null
			this.showForm = true
		},
		/**
		 * Close the pipeline form without saving. Extracted from an inline
		 * multi-statement handler for the same reason as `onCreate()`.
		 *
		 * @spec exclude formatting-only extraction of an existing inline handler — no behaviour change
		 */
		onFormCancel() {
			this.editingPipeline = null
			this.showForm = false
		},
		/**
		 * @param pipeline
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-47
		 */
		async onDeleteClick(pipeline) {
			// W1: Prevent deleting the default pipeline
			if (pipeline.isDefault) {
				showError(
					t(
						'pipelinq',
						'Cannot delete the default pipeline. Set another pipeline as default first.',
					),
				)
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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-48
		 */
		async onDeleteConfirm() {
			const id = this.deletingPipeline.id
			this.deletingPipeline = null
			this.deleteAffectedCount = 0
			await this.objectStore.deleteObject('pipeline', id)
			await this.objectStore.fetchCollection('pipeline', { _limit: 100 })
		},
		/**
		 * @param pipelineData
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-50
		 */
		async onSave(pipelineData) {
			// W5: Auto-set first pipeline as default
			const isFirstPipeline =
				this.pipelines.length === 0
				|| (this.pipelines.length === 1
					&& pipelineData.id === this.pipelines[0].id)
			if (isFirstPipeline && !pipelineData.id) {
				pipelineData.isDefault = true
			}

			// S3: Prevent unsetting default without a replacement
			if (!pipelineData.isDefault && pipelineData.id) {
				const currentPipeline = this.pipelines.find(
					(p) => p.id === pipelineData.id,
				)
				if (currentPipeline && currentPipeline.isDefault) {
					const otherDefaults = this.pipelines.filter(
						(p) => p.isDefault && p.id !== pipelineData.id,
					)
					if (otherDefaults.length === 0) {
						showError(
							t(
								'pipelinq',
								'At least one pipeline must be set as default',
							),
						)
						pipelineData.isDefault = true
					}
				}
			}

			// If setting as default, unset isDefault on other pipelines
			if (pipelineData.isDefault) {
				const others = this.pipelines.filter(
					(p) => p.isDefault && p.id !== pipelineData.id,
				)
				for (const other of others) {
					await this.objectStore.saveObject('pipeline', {
						...other,
						isDefault: false,
					})
				}
			}

			await this.objectStore.saveObject('pipeline', pipelineData)
			this.showForm = false
			this.editingPipeline = null
			await this.objectStore.fetchCollection('pipeline', { _limit: 100 })
		},
		/**
		 * @param pipelineId
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-44
		 */
		async countAffectedItems(pipelineId) {
			const headers = {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
				'OCS-APIREQUEST': 'true',
			}
			let total = 0

			// Determine which schemas to count from the pipeline's mappings
			const pipeline = this.pipelines.find((p) => p.id === pipelineId)
			const mappings = pipeline?.propertyMappings || []
			const schemaSlugs =
				mappings.length > 0
					? mappings.map((m) => m.schemaSlug)
					: ['lead', 'request'] // Legacy fallback

			for (const slug of schemaSlugs) {
				// `slug` is a *logical* type — stored propertyMappings still say
				// `request` / `complaint` / `contactmoment`, which now live in the
				// `ticket` supertype (unify-ticket-supertype). Resolve it to the
				// registered object type plus its ticketType filter.
				const { objectType, ticketType } = resolveObjectType(slug)
				const config = this.objectStore.objectTypeRegistry[objectType]
				if (!config) continue
				try {
					const ticketFilter = ticketType
						? `ticketType=${ticketType}&`
						: ''
					const url = generateUrl(
						`/apps/openregister/api/objects/${config.register}/${config.schema}?${ticketFilter}pipeline=${pipelineId}&_limit=1`,
					)
					const resp = await fetch(url, { headers })
					if (resp.ok) {
						const data = await resp.json()
						total += data.total || 0
					}
				} catch {
					// Non-blocking
				}
			}

			return total
		},
	},
}
</script>

<style scoped>
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
