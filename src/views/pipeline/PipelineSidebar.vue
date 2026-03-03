<template>
	<NcAppSidebar
		:name="pipeline ? pipeline.title : t('pipelinq', 'Pipeline')"
		:title="pipeline ? pipeline.title : t('pipelinq', 'Pipeline')"
		:subname="pipeline ? pipeline.description : ''"
		:open.sync="internalOpen"
		@close="$emit('update:open', false)">
		<!-- Details Tab -->
		<NcAppSidebarTab
			id="details-tab"
			:name="t('pipelinq', 'Details')"
			:order="1">
			<template #icon>
				<InformationOutline :size="20" />
			</template>

			<div v-if="pipeline" class="pipeline-sidebar__section">
				<div v-if="schemaLabels" class="pipeline-sidebar__detail-row">
					<span class="pipeline-sidebar__label">{{ t('pipelinq', 'Schemas') }}</span>
					<span class="pipeline-sidebar__entity-badge">{{ schemaLabels }}</span>
				</div>

				<div class="pipeline-sidebar__detail-row">
					<span class="pipeline-sidebar__label">{{ t('pipelinq', 'Default pipeline') }}</span>
					<span>
						<Star v-if="pipeline.isDefault" :size="16" class="pipeline-sidebar__star" />
						{{ pipeline.isDefault ? t('pipelinq', 'Yes') : t('pipelinq', 'No') }}
					</span>
				</div>

				<div class="pipeline-sidebar__detail-row">
					<span class="pipeline-sidebar__label">{{ t('pipelinq', 'Stages') }}</span>
					<span>{{ stageCount }}</span>
				</div>

				<div v-if="pipeline.totalsLabel" class="pipeline-sidebar__detail-row">
					<span class="pipeline-sidebar__label">{{ t('pipelinq', 'Totals label') }}</span>
					<span>{{ pipeline.totalsLabel }}</span>
				</div>

				<div v-if="pipeline.color" class="pipeline-sidebar__detail-row">
					<span class="pipeline-sidebar__label">{{ t('pipelinq', 'Color') }}</span>
					<span class="pipeline-sidebar__color-preview" :style="{ background: pipeline.color }" />
				</div>

				<div class="pipeline-sidebar__summary">
					<h4>{{ t('pipelinq', 'Stage flow') }}</h4>
					<p class="pipeline-sidebar__flow">{{ stageFlow }}</p>
				</div>

				<div class="pipeline-sidebar__button-row">
					<NcButton type="secondary" wide @click="onEdit">
						<template #icon>
							<Pencil :size="20" />
						</template>
						{{ t('pipelinq', 'Edit pipeline') }}
					</NcButton>
					<NcButton type="primary" wide @click="onCreate">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('pipelinq', 'New pipeline') }}
					</NcButton>
				</div>
			</div>

			<NcEmptyContent v-else
				:name="t('pipelinq', 'No pipeline selected')"
				:description="t('pipelinq', 'Select a pipeline from the dropdown or create a new one.')">
				<template #icon>
					<ViewColumn :size="20" />
				</template>
				<template #action>
					<NcButton type="primary" @click="onCreate">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('pipelinq', 'New pipeline') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</NcAppSidebarTab>

		<!-- Stages Tab -->
		<NcAppSidebarTab
			id="stages-tab"
			:name="t('pipelinq', 'Stages')"
			:order="2">
			<template #icon>
				<FormatListNumbered :size="20" />
			</template>

			<div v-if="pipeline && sortedStages.length > 0" class="pipeline-sidebar__stages">
				<div
					v-for="stage in sortedStages"
					:key="stage.order"
					class="pipeline-sidebar__stage"
					:class="{ 'pipeline-sidebar__stage--closed': stage.isClosed }">
					<div class="pipeline-sidebar__stage-header">
						<span
							class="pipeline-sidebar__stage-color"
							:style="{ background: stage.color || 'var(--color-primary)' }" />
						<span class="pipeline-sidebar__stage-name">{{ stage.name }}</span>
						<span class="pipeline-sidebar__stage-order">#{{ stage.order }}</span>
					</div>
					<div class="pipeline-sidebar__stage-meta">
						<span v-if="stage.probability != null" class="pipeline-sidebar__stage-prob">
							{{ stage.probability }}%
						</span>
						<span v-if="stage.isClosed" class="pipeline-sidebar__stage-badge pipeline-sidebar__stage-badge--closed">
							{{ t('pipelinq', 'Closed') }}
						</span>
						<span v-if="stage.isWon" class="pipeline-sidebar__stage-badge pipeline-sidebar__stage-badge--won">
							{{ t('pipelinq', 'Won') }}
						</span>
					</div>
				</div>

				<NcButton type="secondary" wide @click="onEdit">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('pipelinq', 'Edit stages') }}
				</NcButton>
			</div>

			<NcEmptyContent v-else-if="pipeline"
				:name="t('pipelinq', 'No stages configured')"
				:description="t('pipelinq', 'This pipeline has no stages. Click edit to add stages.')">
				<template #icon>
					<FormatListNumbered :size="20" />
				</template>
				<template #action>
					<NcButton type="primary" @click="onEdit">
						{{ t('pipelinq', 'Add stages') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<NcEmptyContent v-else
				:name="t('pipelinq', 'No pipeline selected')">
				<template #icon>
					<ViewColumn :size="20" />
				</template>
			</NcEmptyContent>
		</NcAppSidebarTab>

		<!-- Pipeline Form overlay -->
		<PipelineForm
			v-if="showForm"
			:pipeline="formPipeline"
			@save="onSave"
			@cancel="showForm = false" />
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcAppSidebarTab, NcButton, NcEmptyContent } from '@nextcloud/vue'
import InformationOutline from 'vue-material-design-icons/InformationOutline.vue'
import FormatListNumbered from 'vue-material-design-icons/FormatListNumbered.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Star from 'vue-material-design-icons/Star.vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import PipelineForm from '../settings/PipelineForm.vue'

export default {
	name: 'PipelineSidebar',
	components: {
		NcAppSidebar,
		NcAppSidebarTab,
		NcButton,
		NcEmptyContent,
		InformationOutline,
		FormatListNumbered,
		Pencil,
		Plus,
		Star,
		ViewColumn,
		PipelineForm,
	},
	props: {
		pipeline: {
			type: Object,
			default: null,
		},
		open: {
			type: Boolean,
			default: true,
		},
	},
	data() {
		return {
			internalOpen: this.open,
			showForm: false,
			formPipeline: null,
		}
	},
	computed: {
		schemaLabels() {
			const mappings = this.pipeline?.propertyMappings
			if (!mappings || mappings.length === 0) return ''
			return mappings.map(m => m.schemaSlug).join(', ')
		},
		stageCount() {
			if (!this.pipeline?.stages) return '0'
			return n('pipelinq', '%n stage', '%n stages', this.pipeline.stages.length)
		},
		sortedStages() {
			if (!this.pipeline?.stages) return []
			return [...this.pipeline.stages].sort((a, b) => a.order - b.order)
		},
		stageFlow() {
			if (this.sortedStages.length === 0) return t('pipelinq', 'No stages')
			return this.sortedStages.map(s => s.name).join(' \u2192 ')
		},
	},
	watch: {
		open(val) {
			this.internalOpen = val
		},
		internalOpen(val) {
			this.$emit('update:open', val)
		},
	},
	methods: {
		onEdit() {
			this.formPipeline = this.pipeline
			this.showForm = true
		},
		onCreate() {
			this.formPipeline = null
			this.showForm = true
		},
		onSave(pipelineData) {
			this.showForm = false
			this.formPipeline = null
			this.$emit('save', pipelineData)
		},
	},
}
</script>

<style scoped>
.pipeline-sidebar__section {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.pipeline-sidebar__detail-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.pipeline-sidebar__label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-weight: 500;
}

.pipeline-sidebar__entity-badge {
	font-size: 12px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.pipeline-sidebar__star {
	color: var(--color-warning);
	vertical-align: middle;
	margin-right: 4px;
}

.pipeline-sidebar__color-preview {
	display: inline-block;
	width: 24px;
	height: 24px;
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
}

.pipeline-sidebar__button-row {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.pipeline-sidebar__summary {
	margin-top: 8px;
}

.pipeline-sidebar__summary h4 {
	margin: 0 0 4px;
	font-size: 13px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.pipeline-sidebar__flow {
	font-size: 13px;
	color: var(--color-main-text);
	line-height: 1.5;
	margin: 0;
}

/* Stages tab */
.pipeline-sidebar__stages {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.pipeline-sidebar__stage {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 10px 12px;
}

.pipeline-sidebar__stage--closed {
	opacity: 0.7;
}

.pipeline-sidebar__stage-header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.pipeline-sidebar__stage-color {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	flex-shrink: 0;
}

.pipeline-sidebar__stage-name {
	font-weight: 600;
	font-size: 13px;
	flex: 1;
}

.pipeline-sidebar__stage-order {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.pipeline-sidebar__stage-meta {
	display: flex;
	gap: 6px;
	margin-top: 4px;
	margin-left: 18px;
}

.pipeline-sidebar__stage-prob {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.pipeline-sidebar__stage-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.5px;
}

.pipeline-sidebar__stage-badge--closed {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.pipeline-sidebar__stage-badge--won {
	background: #dcfce7;
	color: #166534;
}
</style>
