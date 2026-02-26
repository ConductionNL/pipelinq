<template>
	<div class="pipeline-form-overlay">
		<div class="pipeline-form">
			<h3>{{ isEdit ? t('pipelinq', 'Edit pipeline') : t('pipelinq', 'New pipeline') }}</h3>

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
						<label>{{ t('pipelinq', 'Entity type') }}</label>
						<NcSelect v-model="form.entityType"
							:options="entityTypeOptions"
							:clearable="false"
							label="label"
							:reduce="o => o.value"
							:placeholder="t('pipelinq', 'Select type')" />
						<span v-if="errors.entityType" class="error-text">{{ errors.entityType }}</span>
					</div>

					<div class="form-group">
						<NcCheckboxRadioSwitch :checked.sync="form.isDefault" type="switch">
							{{ t('pipelinq', 'Default pipeline') }}
						</NcCheckboxRadioSwitch>
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
									@click="moveStage(stage, -1)">
									<template #icon>
										<ChevronUp :size="16" />
									</template>
								</NcButton>
								<NcButton type="tertiary"
									:disabled="index === sortedStages.length - 1"
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
							@click="removeStage(index)">
							<template #icon>
								<Delete :size="20" />
							</template>
						</NcButton>
					</div>
				</draggable>
			</div>

			<!-- Actions -->
			<div class="form-actions">
				<NcButton type="tertiary" @click="$emit('cancel')">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!isValid" @click="onSave">
					{{ isEdit ? t('pipelinq', 'Save') : t('pipelinq', 'Create') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import draggable from 'vuedraggable'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronUp from 'vue-material-design-icons/ChevronUp.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'PipelineForm',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
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
				entityType: 'lead',
				isDefault: false,
				stages: [],
			},
			entityTypeOptions: [
				{ value: 'lead', label: t('pipelinq', 'Leads') },
				{ value: 'request', label: t('pipelinq', 'Requests') },
				{ value: 'both', label: t('pipelinq', 'Leads & Requests') },
			],
		}
	},
	computed: {
		isEdit() {
			return !!this.pipeline
		},
		sortedStages() {
			return [...this.form.stages].sort((a, b) => a.order - b.order)
		},
		errors() {
			const errors = {}
			if (!this.form.title.trim()) {
				errors.title = t('pipelinq', 'Pipeline title is required')
			}
			if (!this.form.entityType) {
				errors.entityType = t('pipelinq', 'Entity type is required')
			}
			const nonClosedCount = this.form.stages.filter(s => !s.isClosed).length
			if (this.form.stages.length > 0 && nonClosedCount === 0) {
				errors.stages = t('pipelinq', 'Pipeline must have at least one non-closed stage')
			}
			return errors
		},
		stageErrors() {
			return this.form.stages.map(stage => {
				const errors = {}
				if (!stage.name || !stage.name.trim()) {
					errors.name = t('pipelinq', 'Stage name is required')
				}
				if (stage.isWon && !stage.isClosed) {
					errors.isWon = t('pipelinq', 'A Won stage must also be marked as Closed')
				}
				return Object.keys(errors).length > 0 ? errors : null
			})
		},
		isValid() {
			if (Object.keys(this.errors).length > 0) return false
			if (this.stageErrors.some(e => e !== null)) return false
			if (this.form.stages.length === 0) return false
			return true
		},
	},
	created() {
		if (this.pipeline) {
			this.form = {
				id: this.pipeline.id,
				title: this.pipeline.title || '',
				description: this.pipeline.description || '',
				entityType: this.pipeline.entityType || 'lead',
				isDefault: !!this.pipeline.isDefault,
				stages: (this.pipeline.stages || []).map(s => ({ ...s })),
			}
		}
	},
	methods: {
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
		removeStage(index) {
			const sorted = this.sortedStages
			const stage = sorted[index]
			const stageIndex = this.form.stages.indexOf(stage)
			if (stageIndex !== -1) {
				this.form.stages.splice(stageIndex, 1)
			}
			this.recomputeOrders()
		},
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
		recomputeOrders() {
			const sorted = [...this.form.stages].sort((a, b) => a.order - b.order)
			sorted.forEach((stage, i) => {
				stage.order = i
			})
		},
		onSave() {
			if (!this.isValid) return

			const data = {
				...this.form,
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
.pipeline-form-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.3);
	display: flex;
	justify-content: center;
	align-items: flex-start;
	padding-top: 60px;
	z-index: 1000;
}

.pipeline-form {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	padding: 24px;
	width: 800px;
	max-width: 90vw;
	max-height: 80vh;
	overflow-y: auto;
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
}

.pipeline-form h3 {
	margin: 0 0 16px;
}

.form-section {
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

.stage-color-field input[type="color"] {
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

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.error-text {
	color: var(--color-error);
	font-size: 12px;
	display: block;
	margin-top: 4px;
}
</style>
