<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Queues')"
		:description="t('pipelinq', 'Manage work queues for request routing and workload distribution')">
		<NcLoadingIcon v-if="loading" />

		<div v-else class="queue-settings">
			<div v-for="queue in queues" :key="queue.id" class="queue-item">
				<div v-if="editingId !== queue.id" class="queue-item__display">
					<div class="queue-item__info">
						<span class="queue-title">{{ queue.title }}</span>
						<span v-if="queue.isActive === false" class="inactive-tag">{{ t('pipelinq', 'Inactive') }}</span>
						<span class="queue-meta">
							{{ (queue.categories || []).join(', ') || t('pipelinq', 'No categories') }}
							· {{ (queue.assignedAgents || []).length }} {{ t('pipelinq', 'agents') }}
							<template v-if="queue.maxCapacity">· {{ t('pipelinq', 'max {n}', { n: queue.maxCapacity }) }}</template>
						</span>
					</div>
					<div class="queue-item__actions">
						<NcButton @click="startEdit(queue)">
							{{ t('pipelinq', 'Edit') }}
						</NcButton>
						<NcButton type="error" @click="deleteQueue(queue)">
							{{ t('pipelinq', 'Delete') }}
						</NcButton>
					</div>
				</div>

				<div v-else class="queue-item__edit">
					<div class="edit-field">
						<label>{{ t('pipelinq', 'Title') }}</label>
						<input v-model="editForm.title" type="text">
					</div>
					<div class="edit-field">
						<label>{{ t('pipelinq', 'Description') }}</label>
						<textarea v-model="editForm.description" />
					</div>
					<div class="edit-field">
						<label>{{ t('pipelinq', 'Categories (comma-separated)') }}</label>
						<input v-model="editForm.categoriesInput" type="text">
					</div>
					<div class="edit-row">
						<div class="edit-field">
							<label>{{ t('pipelinq', 'Max capacity') }}</label>
							<input v-model.number="editForm.maxCapacity" type="number" min="1">
						</div>
						<div class="edit-field">
							<label>
								<input v-model="editForm.isActive" type="checkbox">
								{{ t('pipelinq', 'Active') }}
							</label>
						</div>
					</div>
					<div class="edit-field">
						<label>{{ t('pipelinq', 'Assigned agents (comma-separated user IDs)') }}</label>
						<input v-model="editForm.agentsInput" type="text">
					</div>
					<div class="edit-actions">
						<NcButton @click="cancelEdit">
							{{ t('pipelinq', 'Cancel') }}
						</NcButton>
						<NcButton type="primary" @click="saveEdit">
							{{ t('pipelinq', 'Save') }}
						</NcButton>
					</div>
				</div>
			</div>

			<div class="queue-add">
				<NcButton @click="addQueue">
					{{ t('pipelinq', '+ Add Queue') }}
				</NcButton>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSettingsSection } from '@nextcloud/vue'
import { useQueuesStore } from '../../store/modules/queues.js'

export default {
	name: 'QueueSettings',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSettingsSection,
	},
	data() {
		return {
			editingId: null,
			editForm: {},
		}
	},
	computed: {
		queuesStore() {
			return useQueuesStore()
		},
		loading() {
			return this.queuesStore.loading
		},
		queues() {
			return this.queuesStore.queues
		},
	},
	mounted() {
		this.queuesStore.fetchQueues()
	},
	methods: {
		startEdit(queue) {
			this.editingId = queue.id
			this.editForm = {
				...queue,
				categoriesInput: (queue.categories || []).join(', '),
				agentsInput: (queue.assignedAgents || []).join(', '),
			}
		},
		cancelEdit() {
			this.editingId = null
			this.editForm = {}
		},
		async saveEdit() {
			const data = {
				...this.editForm,
				categories: this.editForm.categoriesInput
					? this.editForm.categoriesInput.split(',').map(c => c.trim()).filter(Boolean)
					: [],
				assignedAgents: this.editForm.agentsInput
					? this.editForm.agentsInput.split(',').map(a => a.trim()).filter(Boolean)
					: [],
			}
			delete data.categoriesInput
			delete data.agentsInput
			await this.queuesStore.saveQueue(data)
			this.cancelEdit()
		},
		async addQueue() {
			await this.queuesStore.saveQueue({
				title: t('pipelinq', 'New Queue'),
				isActive: true,
				categories: [],
			})
		},
		async deleteQueue(queue) {
			if (confirm(t('pipelinq', 'Delete queue "{title}"? Items will be unqueued.', { title: queue.title }))) {
				await this.queuesStore.deleteQueue(queue.id)
			}
		},
	},
}
</script>

<style scoped>
.queue-settings {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.queue-item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px 16px;
}

.queue-item__display {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
}

.queue-title {
	font-weight: 700;
}

.inactive-tag {
	font-size: 11px;
	padding: 1px 6px;
	border-radius: 8px;
	background: var(--color-background-darker);
	color: var(--color-text-maxcontrast);
	margin-left: 6px;
}

.queue-meta {
	display: block;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.queue-item__actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.queue-item__edit {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.edit-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.edit-field label {
	font-weight: 600;
	font-size: 13px;
}

.edit-field input,
.edit-field textarea {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.edit-row {
	display: flex;
	gap: 16px;
}

.edit-actions {
	display: flex;
	gap: 4px;
	justify-content: flex-end;
}

.queue-add {
	margin-top: 8px;
}
</style>
