<template>
	<div class="complaint-form">
		<!-- Heading -->
		<h2>{{ isEdit ? t('pipelinq', 'Edit complaint') : t('pipelinq', 'New complaint') }}</h2>

		<!-- Title -->
		<div class="form-group">
			<NcTextField
				:value="form.title"
				:label="t('pipelinq', 'Title') + ' *'"
				:error="!!errors.title"
				:helper-text="errors.title"
				@update:value="v => form.title = v" />
		</div>

		<!-- Description -->
		<div class="form-group">
			<label>{{ t('pipelinq', 'Description') + ' *' }}</label>
			<textarea
				v-model="form.description"
				class="complaint-textarea"
				:class="{ 'textarea-error': errors.description }"
				:placeholder="t('pipelinq', 'Describe the complaint in detail...')"
				rows="4" />
			<span v-if="errors.description" class="field-error">{{ errors.description }}</span>
		</div>

		<!-- Category + Priority row -->
		<div class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Category') + ' *' }}</label>
				<NcSelect
					v-model="form.category"
					:options="categoryOptions"
					:clearable="false"
					:placeholder="t('pipelinq', 'Select category')" />
				<span v-if="errors.category" class="field-error">{{ errors.category }}</span>
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

		<!-- Channel + Status row -->
		<div class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Channel') }}</label>
				<NcSelect
					v-model="form.channel"
					:options="channelOptions"
					:clearable="true"
					:placeholder="t('pipelinq', 'Select channel')" />
			</div>
			<div v-if="isEdit" class="form-group">
				<label>{{ t('pipelinq', 'Status') }}</label>
				<NcSelect
					v-model="form.status"
					:options="availableStatuses"
					:clearable="false"
					:placeholder="t('pipelinq', 'Status')" />
			</div>
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
				:placeholder="t('pipelinq', 'Search client...')"
				@input="onClientChange" />
		</div>

		<!-- Contact (filtered by selected client) -->
		<div class="form-group">
			<label>{{ t('pipelinq', 'Contact person') }}</label>
			<NcSelect
				v-model="form.contact"
				:options="contactOptions"
				:clearable="true"
				label="label"
				:reduce="o => o.value"
				:disabled="!form.client"
				:placeholder="form.client ? t('pipelinq', 'Select contact') : t('pipelinq', 'Select client first')" />
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
import { useSettingsStore } from '../../store/modules/settings.js'
import { getAllowedTransitions, VALID_CATEGORIES, VALID_CHANNELS } from '../../services/complaintStatus.js'

export default {
	name: 'ComplaintForm',
	components: {
		NcButton,
		NcSelect,
		NcTextField,
	},
	props: {
		complaint: {
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
				category: null,
				priority: 'normal',
				status: 'new',
				channel: null,
				client: null,
				contact: null,
			},
			priorityOptions: ['low', 'normal', 'high', 'urgent'],
			categoryOptions: [...VALID_CATEGORIES],
			channelOptions: [...VALID_CHANNELS],
			allContacts: [],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isEdit() {
			return !!this.complaint?.id
		},
		availableStatuses() {
			if (!this.isEdit) return ['new']
			const current = this.complaint.status || 'new'
			return [current, ...getAllowedTransitions(current)]
		},
		clients() {
			return this.objectStore.collections.client || []
		},
		clientOptions() {
			return this.clients.map(c => ({
				value: c.id,
				label: c.name || c.id,
			}))
		},
		contactOptions() {
			if (!this.form.client) return []
			return this.allContacts
				.filter(c => c.client === this.form.client)
				.map(c => ({
					value: c.id,
					label: c.name || c.id,
				}))
		},
		errors() {
			const errors = {}
			if (!this.form.title || !this.form.title.trim()) {
				errors.title = t('pipelinq', 'Title is required')
			} else if (this.form.title.length > 255) {
				errors.title = t('pipelinq', 'Title must be 255 characters or less')
			}
			if (!this.form.category) {
				errors.category = t('pipelinq', 'Category is required')
			}
			if (!this.form.description || !this.form.description.trim()) {
				errors.description = t('pipelinq', 'Description is required')
			}
			return errors
		},
		isValid() {
			return Object.keys(this.errors).length === 0
				&& this.form.title?.trim()
				&& this.form.category
				&& this.form.description?.trim()
		},
	},
	async created() {
		await Promise.all([
			this.objectStore.fetchCollection('client', { _limit: 100 }),
			this.objectStore.fetchCollection('contact', { _limit: 200 }).then(items => {
				this.allContacts = items || []
			}),
		])

		if (this.complaint) {
			this.form = {
				id: this.complaint.id,
				title: this.complaint.title || '',
				description: this.complaint.description || '',
				category: this.complaint.category || null,
				priority: this.complaint.priority || 'normal',
				status: this.complaint.status || 'new',
				channel: this.complaint.channel || null,
				client: this.complaint.client || null,
				contact: this.complaint.contact || null,
			}
		} else if (this.preLinkedClient) {
			this.form.client = this.preLinkedClient
		}
	},
	methods: {
		onClientChange() {
			// Reset contact when client changes
			this.form.contact = null
		},
		onSave() {
			if (!this.isValid) return

			const data = { ...this.form }
			if (!data.channel) delete data.channel
			if (!data.client) delete data.client
			if (!data.contact) delete data.contact

			// Calculate SLA deadline for new complaints
			if (!this.isEdit && data.category) {
				const settingsStore = useSettingsStore()
				const slaHours = settingsStore.getComplaintSlaHours(data.category)
				if (slaHours > 0) {
					const deadline = new Date()
					deadline.setHours(deadline.getHours() + slaHours)
					data.slaDeadline = deadline.toISOString()
				}
			}

			this.$emit('save', data)
		},
	},
}
</script>

<style scoped>
.complaint-form {
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

.complaint-textarea {
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

.complaint-textarea:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.textarea-error {
	border-color: var(--color-error);
}

.field-error {
	display: block;
	color: var(--color-error);
	font-size: 12px;
	margin-top: 2px;
}
</style>
