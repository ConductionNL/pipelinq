<template>
	<div class="request-form">
		<!-- Title -->
		<div class="form-group">
			<NcTextField
				:value="form.title"
				:label="t('pipelinq', 'Title')"
				:error="!!errors.title"
				:helper-text="errors.title"
				@update:value="v => form.title = v" />
		</div>

		<!-- Description -->
		<div class="form-group">
			<NcTextField
				:value="form.description"
				:label="t('pipelinq', 'Description')"
				@update:value="v => form.description = v" />
		</div>

		<!-- Status + Priority row -->
		<div class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Status') }}</label>
				<NcSelect
					v-model="form.status"
					:options="availableStatuses"
					:clearable="false"
					:placeholder="t('pipelinq', 'Status')" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Priority') }}</label>
				<NcSelect
					v-model="form.priority"
					:options="priorityOptions"
					:clearable="false"
					:placeholder="t('pipelinq', 'Priority')" />
			</div>
		</div>

		<!-- Channel + Category row -->
		<div class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Channel') }}</label>
				<NcSelect
					v-model="form.channel"
					:options="channelOptions"
					:clearable="true"
					:placeholder="t('pipelinq', 'Select channel')" />
			</div>
			<div class="form-group">
				<NcTextField
					:value="form.category"
					:label="t('pipelinq', 'Category')"
					@update:value="v => form.category = v" />
			</div>
		</div>

		<!-- Requested at -->
		<div class="form-group">
			<NcTextField
				:value="form.requestedAt || ''"
				:label="t('pipelinq', 'Requested at')"
				type="date"
				@update:value="v => form.requestedAt = v || null" />
		</div>

		<!-- Client -->
		<div class="form-group">
			<label>{{ t('pipelinq', 'Client') }}</label>
			<NcSelect
				v-model="form.client"
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
				<NcSelect
					v-model="form.pipeline"
					:options="pipelineOptions"
					:clearable="true"
					label="label"
					:reduce="o => o.value"
					:placeholder="t('pipelinq', 'Select pipeline')"
					@input="onPipelineChange" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Stage') }}</label>
				<NcSelect
					v-model="form.stage"
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
import { useRequestChannelsStore } from '../../store/modules/requestChannels.js'
import { getAllowedTransitions } from '../../services/requestStatus.js'

export default {
	name: 'RequestForm',
	components: {
		NcButton,
		NcSelect,
		NcTextField,
	},
	props: {
		request: {
			type: Object,
			default: null,
		},
		preLinkedClient: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			form: {
				title: '',
				description: '',
				status: 'new',
				priority: 'normal',
				channel: null,
				category: '',
				requestedAt: null,
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
		requestChannelsStore() {
			return useRequestChannelsStore()
		},
		channelOptions() {
			return this.requestChannelsStore.channelNames
		},
		isEdit() {
			return !!this.request?.id
		},
		availableStatuses() {
			if (!this.isEdit) return ['new']
			const current = this.request.status || 'new'
			return [current, ...getAllowedTransitions(current)]
		},
		pipelines() {
			return this.objectStore.getCollection('pipeline') || []
		},
		requestPipelines() {
			return this.pipelines.filter(p =>
				p.entityType === 'request' || p.entityType === 'both',
			)
		},
		pipelineOptions() {
			return this.requestPipelines.map(p => ({
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
			return errors
		},
		isValid() {
			return Object.keys(this.errors).length === 0 && this.form.title?.trim()
		},
	},
	async created() {
		await Promise.all([
			this.objectStore.fetchCollection('pipeline', { _limit: 100 }),
			this.objectStore.fetchCollection('client', { _limit: 100 }),
			this.requestChannelsStore.fetchChannels(),
		])

		if (this.request) {
			this.form = {
				id: this.request.id,
				title: this.request.title || '',
				description: this.request.description || '',
				status: this.request.status || 'new',
				priority: this.request.priority || 'normal',
				channel: this.request.channel || null,
				category: this.request.category || '',
				requestedAt: this.request.requestedAt || null,
				client: this.request.client || null,
				pipeline: this.request.pipeline || null,
				stage: this.request.stage || null,
			}
		} else {
			if (this.preLinkedClient) {
				this.form.client = this.preLinkedClient
			}
			this.autoAssignDefaultPipeline()
		}
	},
	methods: {
		autoAssignDefaultPipeline() {
			const defaultPipeline = this.requestPipelines.find(p => p.isDefault)
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
			this.form.stage = null
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
			if (!data.channel) delete data.channel
			if (!data.requestedAt) delete data.requestedAt
			if (!data.client) delete data.client
			if (!data.pipeline) delete data.pipeline
			if (!data.stage) delete data.stage
			if (!data.category) delete data.category

			this.$emit('save', data)
		},
	},
}
</script>

<style scoped>
.request-form {
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
