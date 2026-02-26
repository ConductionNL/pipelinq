<template>
	<div class="lead-form">
		<!-- Title -->
		<div class="form-group">
			<NcTextField :value="form.title"
				:label="t('pipelinq', 'Title')"
				:error="!!errors.title"
				:helper-text="errors.title"
				@update:value="v => form.title = v" />
		</div>

		<!-- Description -->
		<div class="form-group">
			<NcTextField :value="form.description"
				:label="t('pipelinq', 'Description')"
				@update:value="v => form.description = v" />
		</div>

		<!-- Value + Probability row -->
		<div class="form-row">
			<div class="form-group">
				<NcTextField :value="form.value === null ? '' : String(form.value)"
					:label="t('pipelinq', 'Value (EUR)')"
					type="number"
					:error="!!errors.value"
					:helper-text="errors.value"
					@update:value="v => form.value = v === '' ? null : Number(v)" />
			</div>
			<div class="form-group">
				<NcTextField :value="form.probability === null ? '' : String(form.probability)"
					:label="t('pipelinq', 'Probability %')"
					type="number"
					:error="!!errors.probability"
					:helper-text="errors.probability"
					@update:value="v => form.probability = v === '' ? null : Number(v)" />
			</div>
		</div>

		<!-- Source + Priority row -->
		<div class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Source') }}</label>
				<NcSelect v-model="form.source"
					:options="sourceOptions"
					:clearable="true"
					:placeholder="t('pipelinq', 'Select source')" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Priority') }}</label>
				<NcSelect v-model="form.priority"
					:options="priorityOptions"
					:clearable="false"
					:placeholder="t('pipelinq', 'Select priority')" />
			</div>
		</div>

		<!-- Expected Close Date -->
		<div class="form-group">
			<NcTextField :value="form.expectedCloseDate || ''"
				:label="t('pipelinq', 'Expected close date')"
				type="date"
				@update:value="v => form.expectedCloseDate = v || null" />
		</div>

		<!-- Client -->
		<div class="form-group">
			<label>{{ t('pipelinq', 'Client') }}</label>
			<NcSelect v-model="form.client"
				:options="clientOptions"
				:clearable="true"
				label="label"
				:reduce="o => o.value"
				:placeholder="t('pipelinq', 'Select client')" />
		</div>

		<!-- Pipeline + Stage row -->
		<div class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Pipeline') }}</label>
				<NcSelect v-model="form.pipeline"
					:options="pipelineOptions"
					:clearable="true"
					label="label"
					:reduce="o => o.value"
					:placeholder="t('pipelinq', 'Select pipeline')"
					@input="onPipelineChange" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Stage') }}</label>
				<NcSelect v-model="form.stage"
					:options="stageOptions"
					:clearable="true"
					:disabled="!form.pipeline"
					:placeholder="form.pipeline ? t('pipelinq', 'Select stage') : t('pipelinq', 'Select pipeline first')" />
			</div>
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
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'
import { useLeadSourcesStore } from '../../store/modules/leadSources.js'

export default {
	name: 'LeadForm',
	components: {
		NcButton,
		NcSelect,
		NcTextField,
	},
	props: {
		lead: {
			type: Object,
			default: null,
		},
	},
	data() {
		return {
			form: {
				title: '',
				description: '',
				value: null,
				probability: null,
				source: null,
				priority: 'normal',
				expectedCloseDate: null,
				client: null,
				pipeline: null,
				stage: null,
			},
			priorityOptions: ['low', 'normal', 'high', 'urgent'],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		leadSourcesStore() {
			return useLeadSourcesStore()
		},
		sourceOptions() {
			return this.leadSourcesStore.sourceNames
		},
		isEdit() {
			return !!this.lead?.id
		},
		pipelines() {
			return this.objectStore.getCollection('pipeline') || []
		},
		leadPipelines() {
			return this.pipelines.filter(p =>
				p.entityType === 'lead' || p.entityType === 'both',
			)
		},
		pipelineOptions() {
			return this.leadPipelines.map(p => ({
				value: p.id,
				label: p.title,
			}))
		},
		selectedPipeline() {
			if (!this.form.pipeline) return null
			return this.pipelines.find(p => p.id === this.form.pipeline) || null
		},
		stageOptions() {
			if (!this.selectedPipeline?.stages) return []
			return [...this.selectedPipeline.stages]
				.sort((a, b) => a.order - b.order)
				.map(s => s.name)
		},
		clients() {
			return this.objectStore.getCollection('client') || []
		},
		clientOptions() {
			return this.clients.map(c => ({
				value: c.id,
				label: c.name || c.id,
			}))
		},
		errors() {
			const errors = {}
			if (!this.form.title || !this.form.title.trim()) {
				errors.title = t('pipelinq', 'Title is required')
			}
			if (this.form.value !== null && this.form.value < 0) {
				errors.value = t('pipelinq', 'Value must be non-negative')
			}
			if (this.form.probability !== null && (this.form.probability < 0 || this.form.probability > 100)) {
				errors.probability = t('pipelinq', 'Probability must be between 0 and 100')
			}
			return errors
		},
		isValid() {
			return Object.keys(this.errors).length === 0 && this.form.title?.trim()
		},
	},
	async created() {
		// Load pipelines, clients, and lead sources for dropdowns
		await Promise.all([
			this.objectStore.fetchCollection('pipeline', { _limit: 100 }),
			this.objectStore.fetchCollection('client', { _limit: 100 }),
			this.leadSourcesStore.fetchSources(),
		])

		if (this.lead) {
			// Edit mode: populate from existing lead
			this.form = {
				id: this.lead.id,
				title: this.lead.title || '',
				description: this.lead.description || '',
				value: this.lead.value ?? null,
				probability: this.lead.probability ?? null,
				source: this.lead.source || null,
				priority: this.lead.priority || 'normal',
				expectedCloseDate: this.lead.expectedCloseDate || null,
				client: this.lead.client || null,
				pipeline: this.lead.pipeline || null,
				stage: this.lead.stage || null,
			}
		} else {
			// Create mode: auto-assign default pipeline
			this.autoAssignDefaultPipeline()
		}
	},
	methods: {
		autoAssignDefaultPipeline() {
			const defaultPipeline = this.leadPipelines.find(p => p.isDefault)
			if (defaultPipeline) {
				this.form.pipeline = defaultPipeline.id
				const stages = [...(defaultPipeline.stages || [])].sort((a, b) => a.order - b.order)
				const firstOpen = stages.find(s => !s.isClosed)
				if (firstOpen) {
					this.form.stage = firstOpen.name
				}
			}
		},
		onPipelineChange() {
			// Reset stage when pipeline changes
			this.form.stage = null
			// Auto-select first non-closed stage
			if (this.selectedPipeline) {
				const stages = [...(this.selectedPipeline.stages || [])].sort((a, b) => a.order - b.order)
				const firstOpen = stages.find(s => !s.isClosed)
				if (firstOpen) {
					this.form.stage = firstOpen.name
				}
			}
		},
		onSave() {
			if (!this.isValid) return

			const data = { ...this.form }
			// Clean null values
			if (data.value === null) delete data.value
			if (data.probability === null) delete data.probability
			if (!data.source) delete data.source
			if (!data.expectedCloseDate) delete data.expectedCloseDate
			if (!data.client) delete data.client
			if (!data.pipeline) delete data.pipeline
			if (!data.stage) delete data.stage

			this.$emit('save', data)
		},
	},
}
</script>

<style scoped>
.lead-form {
	max-width: 600px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	font-weight: bold;
	margin-bottom: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.form-row {
	display: flex;
	gap: 16px;
}

.form-row .form-group {
	flex: 1;
}

.form-actions {
	display: flex;
	gap: 8px;
	margin-top: 20px;
}
</style>
