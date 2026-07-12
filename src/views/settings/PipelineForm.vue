<template>
	<NcDialog
		size="large"
		:name="isEdit ? t('pipelinq', 'Edit pipeline') : t('pipelinq', 'New pipeline')"
		@closing="$emit('cancel')">
		<div class="pipeline-form">
			<!-- Pipeline properties -->
			<div class="form-section">
				<div class="form-group">
					<NcTextField :value="form.title"
						:label="t('pipelinq', 'Title')"
						:error="!!errors.title"
						:helper-text="errors.title"
						@update:value="v => form.title = v" />
				</div>

				<div class="form-group">
					<NcTextField :value="form.description"
						:label="t('pipelinq', 'Description')"
						@update:value="v => form.description = v" />
				</div>

				<div class="form-row">
					<div class="form-group">
						<label>{{ t('pipelinq', 'View') }}</label>
						<NcSelect v-model="form.viewId"
							:options="viewOptions"
							:aria-label-combobox="t('pipelinq', 'View')"
							:clearable="true"
							label="label"
							:reduce="o => o.value"
							:loading="loadingViews"
							:placeholder="t('pipelinq', 'Select a view')" />
						<span class="help-text">{{ t('pipelinq', 'Select a saved view to define which schemas are shown in this pipeline.') }}</span>
					</div>

					<div class="form-group">
						<NcCheckboxRadioSwitch :checked.sync="form.isDefault" type="switch">
							{{ t('pipelinq', 'Default pipeline') }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>

				<div class="form-group">
					<NcTextField :value="form.totalsLabel"
						:label="t('pipelinq', 'Totals label')"
						:placeholder="t('pipelinq', 'e.g. EUR, hours, items')"
						@update:value="v => form.totalsLabel = v" />
					<span class="help-text">{{ t('pipelinq', 'Label shown next to column totals. Leave empty to hide totals.') }}</span>
				</div>
			</div>

			<!-- Property Mappings -->
			<div class="form-section">
				<div class="mappings-header">
					<h4>{{ t('pipelinq', 'Property mappings') }}</h4>
					<NcButton type="secondary" @click="addMapping">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('pipelinq', 'Add mapping') }}
					</NcButton>
				</div>

				<span class="help-text mapping-help">
					{{ t('pipelinq', 'Configure which property determines the column placement for each schema, and optionally which property to sum in column totals.') }}
				</span>

				<div v-if="form.propertyMappings.length === 0" class="mappings-empty">
					{{ t('pipelinq', 'No mappings yet. Add at least one to map schema properties to pipeline columns.') }}
				</div>

				<div v-else class="mappings-list">
					<div v-for="(mapping, index) in form.propertyMappings"
						:key="index"
						class="mapping-row">
						<div class="mapping-fields">
							<div class="mapping-field">
								<NcTextField :value="mapping.schemaSlug"
									:label="t('pipelinq', 'Schema slug')"
									:placeholder="t('pipelinq', 'e.g. lead, request')"
									@update:value="v => mapping.schemaSlug = v" />
							</div>
							<div class="mapping-field">
								<NcTextField :value="mapping.columnProperty"
									:label="t('pipelinq', 'Column property')"
									:placeholder="t('pipelinq', 'e.g. stage, status')"
									@update:value="v => mapping.columnProperty = v" />
							</div>
							<div class="mapping-field">
								<NcTextField :value="mapping.totalsProperty || ''"
									:label="t('pipelinq', 'Totals property')"
									:placeholder="t('pipelinq', 'e.g. value (optional)')"
									@update:value="v => mapping.totalsProperty = v || null" />
							</div>
						</div>
						<NcButton type="tertiary"
							class="mapping-delete"
							:aria-label="t('pipelinq', 'Remove this property mapping')"
							@click="removeMapping(index)">
							<template #icon>
								<Delete :size="20" />
							</template>
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Stages -->
			<div class="form-section">
				<div class="stages-header">
					<h4>{{ t('pipelinq', 'Stages') }}</h4>
					<NcButton type="secondary" @click="addStage">
						<template #icon>
							<Plus :size="20" />
						</template>
						{{ t('pipelinq', 'Add stage') }}
					</NcButton>
				</div>

				<span v-if="errors.stages" class="error-text">{{ errors.stages }}</span>

				<div v-if="form.stages.length === 0" class="stages-empty">
					{{ t('pipelinq', 'No stages yet. Add at least one stage.') }}
				</div>

				<draggable v-else
					v-model="form.stages"
					class="stages-list"
					handle=".drag-handle"
					@end="recomputeOrders">
					<div v-for="(stage, index) in sortedStages"
						:key="index"
						class="stage-row">
						<div class="stage-order">
							<span class="drag-handle" :title="t('pipelinq', 'Drag to reorder')">&#x2630;</span>
							<div class="stage-reorder-buttons">
								<NcButton type="tertiary"
									:disabled="index === 0"
									:aria-label="t('pipelinq', 'Move stage {name} up', { name: stage.name })"
									@click="moveStage(stage, -1)">
									<template #icon>
										<ChevronUp :size="16" />
									</template>
								</NcButton>
								<NcButton type="tertiary"
									:disabled="index === sortedStages.length - 1"
									:aria-label="t('pipelinq', 'Move stage {name} down', { name: stage.name })"
									@click="moveStage(stage, 1)">
									<template #icon>
										<ChevronDown :size="16" />
									</template>
								</NcButton>
							</div>
							<span class="order-number">{{ stage.order }}</span>
						</div>

						<div class="stage-fields">
							<NcTextField :value="stage.name"
								:label="t('pipelinq', 'Stage name')"
								:error="!!stageErrors[index]?.name"
								:helper-text="stageErrors[index]?.name"
								class="stage-name-field"
								@update:value="v => stage.name = v" />

							<NcTextField :value="String(stage.probability ?? '')"
								:label="t('pipelinq', 'Probability %')"
								type="number"
								:error="!!stageErrors[index]?.probability"
								:helper-text="stageErrors[index]?.probability || ''"
								class="stage-probability-field"
								@update:value="v => stage.probability = v === '' ? null : Number(v)" />

							<div class="stage-color-field">
								<label>{{ t('pipelinq', 'Color') }}</label>
								<input type="color"
									:value="stage.color || '#6b7280'"
									@input="e => stage.color = e.target.value">
							</div>
						</div>

						<div class="stage-flags">
							<NcCheckboxRadioSwitch :checked.sync="stage.isClosed" type="switch">
								{{ t('pipelinq', 'Closed') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch :checked.sync="stage.isWon"
								:disabled="!stage.isClosed"
								type="switch">
								{{ t('pipelinq', 'Won') }}
							</NcCheckboxRadioSwitch>
							<span v-if="stageErrors[index]?.isWon" class="error-text">{{ stageErrors[index].isWon }}</span>
						</div>

						<NcButton type="tertiary"
							class="stage-delete"
							:aria-label="t('pipelinq', 'Remove stage {name}', { name: stage.name })"
							@click="removeStage(index)">
							<template #icon>
								<Delete :size="20" />
							</template>
						</NcButton>
					</div>
				</draggable>
			</div>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="!isValid" @click="onSave">
				{{ isEdit ? t('pipelinq', 'Save') : t('pipelinq', 'Create') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import draggable from 'vuedraggable'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { getViews } from '../../services/viewService.js'

export default {
	name: 'PipelineForm',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcSelect,
		NcTextField,
		draggable,
		ChevronDown,
		ChevronUp,
		Delete,
		Plus,
	},
	props: {
		pipeline: {
			type: Object,
			default: null,
		},
	},
	data() {
		return {
			form: {
				title: '',
				description: '',
				viewId: null,
				isDefault: false,
				totalsLabel: '',
				propertyMappings: [],
				stages: [],
			},
			views: [],
			loadingViews: false,
		}
	},
	computed: {
		isEdit() {
			return !!this.pipeline
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-43
		 */
		viewOptions() {
			return this.views.map(v => ({
				value: v.id || v.uuid,
				label: v.name || v.slug || v.id,
			}))
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-41
		 */
		sortedStages() {
			return [...this.form.stages].sort((a, b) => a.order - b.order)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-33
		 */
		errors() {
			const errors = {}
			if (!this.form.title.trim()) {
				errors.title = t('pipelinq', 'Pipeline title is required')
			}
			const nonClosedCount = this.form.stages.filter(s => !s.isClosed).length
			if (this.form.stages.length > 0 && nonClosedCount === 0) {
				errors.stages = t('pipelinq', 'Pipeline must have at least one non-closed stage')
			}
			return errors
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-42
		 * @spec openspec/changes/2026-03-20-pipeline/tasks.md#task-2.2
		 */
		stageErrors() {
			return this.form.stages.map(stage => {
				const errors = {}
				if (!stage.name || !stage.name.trim()) {
					errors.name = t('pipelinq', 'Stage name is required')
				}
				if (stage.isWon && !stage.isClosed) {
					errors.isWon = t('pipelinq', 'A Won stage must also be marked as Closed')
				}
				if (stage.probability != null && stage.probability !== '' && (Number(stage.probability) < 0 || Number(stage.probability) > 100)) {
					errors.probability = t('pipelinq', 'Probability must be between 0 and 100')
				}
				return Object.keys(errors).length > 0 ? errors : null
			})
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-34
		 */
		isValid() {
			if (Object.keys(this.errors).length > 0) return false
			if (this.stageErrors.some(e => e !== null)) return false
			if (this.form.stages.length === 0) return false
			return true
		},
	},
	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-32
	 */
	async created() {
		if (this.pipeline) {
			this.form = {
				id: this.pipeline.id,
				title: this.pipeline.title || '',
				description: this.pipeline.description || '',
				viewId: this.pipeline.viewId || null,
				isDefault: !!this.pipeline.isDefault,
				totalsLabel: this.pipeline.totalsLabel || '',
				propertyMappings: (this.pipeline.propertyMappings || []).map(m => ({ ...m })),
				stages: (this.pipeline.stages || []).map(s => ({ ...s })),
			}
		}
		await this.loadViews()
	},
	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-35
		 */
		async loadViews() {
			this.loadingViews = true
			try {
				this.views = await getViews()
			} catch {
				this.views = []
			}
			this.loadingViews = false
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-30
		 */
		addMapping() {
			this.form.propertyMappings.push({
				schemaSlug: '',
				columnProperty: 'stage',
				totalsProperty: null,
			})
		},

		/**
		 * @param index
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-39
		 */
		removeMapping(index) {
			this.form.propertyMappings.splice(index, 1)
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-31
		 */
		addStage() {
			const maxOrder = this.form.stages.reduce((max, s) => Math.max(max, s.order), -1)
			this.form.stages.push({
				name: '',
				order: maxOrder + 1,
				probability: null,
				isClosed: false,
				isWon: false,
				color: null,
			})
		},
		/**
		 * @param index
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-40
		 */
		removeStage(index) {
			const sorted = this.sortedStages
			const stage = sorted[index]
			const stageIndex = this.form.stages.indexOf(stage)
			if (stageIndex !== -1) {
				this.form.stages.splice(stageIndex, 1)
			}
			this.recomputeOrders()
		},
		/**
		 * @param stage
		 * @param direction
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-36
		 */
		moveStage(stage, direction) {
			const sorted = this.sortedStages
			const currentIndex = sorted.indexOf(stage)
			const targetIndex = currentIndex + direction

			if (targetIndex < 0 || targetIndex >= sorted.length) return

			const otherStage = sorted[targetIndex]
			const tempOrder = stage.order
			stage.order = otherStage.order
			otherStage.order = tempOrder
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-38
		 */
		recomputeOrders() {
			const sorted = [...this.form.stages].sort((a, b) => a.order - b.order)
			sorted.forEach((stage, i) => {
				stage.order = i
			})
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-37
		 */
		onSave() {
			if (!this.isValid) return

			const data = {
				...this.form,
				propertyMappings: this.form.propertyMappings.map(m => ({
					schemaSlug: m.schemaSlug,
					columnProperty: m.columnProperty,
					totalsProperty: m.totalsProperty || null,
				})),
				stages: this.form.stages.map(s => ({
					name: s.name,
					order: s.order,
					probability: s.probability,
					isClosed: !!s.isClosed,
					isWon: !!s.isWon,
					color: s.color || null,
				})),
			}

			this.$emit('save', data)
		},
	},
}
</script>

<style scoped>
.pipeline-form {
	padding: 24px;
}

.form-section:not(:last-child) {
	margin-bottom: 24px;
}

.form-group {
	margin-bottom: 12px;
}

.form-row {
	display: flex;
	gap: 16px;
	align-items: flex-start;
}

.form-row .form-group {
	flex: 1;
}

.help-text {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	display: block;
	margin-top: 4px;
}

/* Property Mappings */
.mappings-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.mappings-header h4 {
	margin: 0;
}

.mapping-help {
	margin-bottom: 12px;
}

.mappings-empty {
	text-align: center;
	padding: 24px;
	color: var(--color-text-maxcontrast);
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius-large);
}

.mappings-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.mapping-row {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.mapping-fields {
	display: flex;
	gap: 8px;
	flex: 1;
	min-width: 0;
}

.mapping-field {
	flex: 1;
}

.mapping-delete {
	flex-shrink: 0;
	align-self: center;
}

/* Stages */
.stages-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.stages-header h4 {
	margin: 0;
}

.stages-empty {
	text-align: center;
	padding: 24px;
	color: var(--color-text-maxcontrast);
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius-large);
}

.stages-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.stage-row {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.stage-order {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
	padding-top: 4px;
}

.drag-handle {
	cursor: grab;
	font-size: 16px;
	color: var(--color-text-maxcontrast);
	user-select: none;
	padding: 2px 4px;
}

.drag-handle:active {
	cursor: grabbing;
}

.stage-reorder-buttons {
	display: flex;
	flex-direction: column;
}

.order-number {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	min-width: 16px;
	text-align: center;
}

.stage-fields {
	display: flex;
	gap: 8px;
	flex: 1;
	min-width: 0;
}

.stage-name-field {
	flex: 2;
}

.stage-probability-field {
	flex: 1;
	max-width: 120px;
}

.stage-color-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex-shrink: 0;
}

.stage-color-field label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.stage-color-field input[type='color'] {
	width: 32px;
	height: 32px;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
}

.stage-flags {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex-shrink: 0;
}

.stage-delete {
	flex-shrink: 0;
	align-self: center;
}

.error-text {
	color: var(--color-error);
	font-size: 12px;
	display: block;
	margin-top: 4px;
}
</style>
