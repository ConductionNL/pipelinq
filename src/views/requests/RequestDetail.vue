<template>
	<div class="request-detail">
		<div class="request-detail__header">
			<NcButton @click="$emit('navigate', 'requests')">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="!isNew">
				{{ requestData.title || t('pipelinq', 'Request') }}
			</h2>
			<h2 v-else>
				{{ t('pipelinq', 'New request') }}
			</h2>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else class="request-detail__form">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Title') }}</label>
				<NcTextField :value="form.title" @update:value="v => form.title = v" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Description') }}</label>
				<textarea v-model="form.description" rows="4" />
			</div>
			<div class="form-row">
				<div class="form-group">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<NcSelect
						v-model="form.status"
						:options="statusOptions"
						:placeholder="t('pipelinq', 'Status')" />
				</div>
				<div class="form-group">
					<label>{{ t('pipelinq', 'Priority') }}</label>
					<NcSelect
						v-model="form.priority"
						:options="priorityOptions"
						:placeholder="t('pipelinq', 'Priority')" />
				</div>
			</div>
			<div class="form-row">
				<div class="form-group">
					<label>{{ t('pipelinq', 'Category') }}</label>
					<NcTextField :value="form.category" @update:value="v => form.category = v" />
				</div>
				<div class="form-group">
					<label>{{ t('pipelinq', 'Requested at') }}</label>
					<NcTextField
						:value="form.requestedAt"
						type="date"
						@update:value="v => form.requestedAt = v" />
				</div>
			</div>

			<!-- Client link -->
			<div v-if="form.client" class="form-group">
				<label>{{ t('pipelinq', 'Client') }}</label>
				<NcButton @click="$emit('navigate', 'client-detail', form.client)">
					{{ t('pipelinq', 'View client') }}
				</NcButton>
			</div>

			<div class="request-detail__actions">
				<NcButton type="primary" @click="save">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
				<NcButton v-if="!isNew" type="error" @click="confirmDelete">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField, NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'RequestDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
	},
	props: {
		requestId: {
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
				category: '',
				requestedAt: '',
				client: '',
			},
			statusOptions: ['new', 'open', 'in_progress', 'closed'],
			priorityOptions: ['low', 'normal', 'high', 'urgent'],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			if (!this.requestId) return true
			// Handle "new?client=uuid" format
			return this.requestId.startsWith('new')
		},
		loading() {
			return this.objectStore.isLoading('request')
		},
		requestData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('request', this.requestId) || {}
		},
	},
	async mounted() {
		if (this.isNew) {
			// Check for pre-linked client from URL
			if (this.requestId && this.requestId.includes('client=')) {
				const clientId = this.requestId.split('client=')[1]
				this.form.client = clientId
			}
		} else {
			await this.objectStore.fetchObject('request', this.requestId)
			this.populateForm()
		}
	},
	methods: {
		populateForm() {
			const data = this.requestData
			this.form = {
				title: data.title || '',
				description: data.description || '',
				status: data.status || 'new',
				priority: data.priority || 'normal',
				category: data.category || '',
				requestedAt: data.requestedAt || '',
				client: data.client || '',
			}
		},
		async save() {
			const objectData = { ...this.form }
			if (!this.isNew) {
				objectData.id = this.requestId
			}

			const result = await this.objectStore.saveObject('request', objectData)
			if (result) {
				if (this.isNew) {
					this.$emit('navigate', 'request-detail', result.id)
				}
			}
		},
		async confirmDelete() {
			if (confirm(t('pipelinq', 'Are you sure you want to delete this?'))) {
				const success = await this.objectStore.deleteObject('request', this.requestId)
				if (success) {
					this.$emit('navigate', 'requests')
				}
			}
		},
	},
}
</script>

<style scoped>
.request-detail {
	padding: 20px;
	max-width: 800px;
}

.request-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.form-group textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.form-row {
	display: flex;
	gap: 16px;
}

.form-row .form-group {
	flex: 1;
}

.request-detail__actions {
	display: flex;
	gap: 12px;
	margin-top: 20px;
}
</style>
