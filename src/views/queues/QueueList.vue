<template>
	<div class="queue-list">
		<div class="queue-list__header">
			<h2>{{ t('pipelinq', 'Queues') }}</h2>
			<NcButton type="primary" @click="showCreateDialog = true">
				{{ t('pipelinq', 'Add queue') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="queues.length === 0" class="queue-list__empty">
			<p>{{ t('pipelinq', 'No queues configured yet.') }}</p>
		</div>

		<div v-else class="queue-list__grid">
			<div
				v-for="queue in sortedQueues"
				:key="queue.id"
				class="queue-card"
				:class="{ 'queue-card--inactive': queue.isActive === false }"
				tabindex="0"
				@click="openQueue(queue)"
				@keydown.enter="openQueue(queue)">
				<div class="queue-card__header">
					<span class="queue-card__title">{{ queue.title }}</span>
					<span v-if="queue.isActive === false" class="queue-card__badge queue-card__badge--inactive">
						{{ t('pipelinq', 'Inactive') }}
					</span>
				</div>
				<div class="queue-card__stats">
					<div class="stat">
						<span class="stat__value">{{ getItemCount(queue) }}</span>
						<span class="stat__label">
							{{ queue.maxCapacity ? `/ ${queue.maxCapacity}` : '' }}
							{{ t('pipelinq', 'items') }}
						</span>
					</div>
					<div class="stat">
						<span class="stat__value">{{ getAgentCount(queue) }}</span>
						<span class="stat__label">{{ t('pipelinq', 'agents') }}</span>
					</div>
				</div>
				<div v-if="queue.categories && queue.categories.length" class="queue-card__categories">
					<span
						v-for="cat in queue.categories"
						:key="cat"
						class="category-tag">
						{{ cat }}
					</span>
				</div>
			</div>
		</div>

		<!-- Create Dialog -->
		<NcDialog
			v-if="showCreateDialog"
			:name="t('pipelinq', 'Create queue')"
			@closing="resetCreateForm">
			<div class="create-form">
				<label>{{ t('pipelinq', 'Title') }}</label>
				<input v-model="newQueue.title" type="text" :placeholder="t('pipelinq', 'Queue name...')">

				<label>{{ t('pipelinq', 'Description') }}</label>
				<textarea v-model="newQueue.description" :placeholder="t('pipelinq', 'Optional description...')" />

				<label>{{ t('pipelinq', 'Categories (comma-separated)') }}</label>
				<input v-model="newQueue.categoriesInput" type="text" :placeholder="t('pipelinq', 'e.g. vergunningen, omgevingsrecht')">

				<label>{{ t('pipelinq', 'Max capacity (empty = unlimited)') }}</label>
				<input v-model.number="newQueue.maxCapacity" type="number" min="1">
			</div>
			<template #actions>
				<NcButton @click="resetCreateForm">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!newQueue.title" @click="createQueue">
					{{ t('pipelinq', 'Create') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { useQueuesStore } from '../../store/modules/queues.js'

export default {
	name: 'QueueList',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
	},
	data() {
		return {
			showCreateDialog: false,
			newQueue: {
				title: '',
				description: '',
				categoriesInput: '',
				maxCapacity: null,
			},
			itemCounts: {},
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
		sortedQueues() {
			return [...this.queues].sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
		},
	},
	mounted() {
		this.queuesStore.fetchQueues()
	},
	methods: {
		openQueue(queue) {
			this.$router.push({ name: 'QueueDetail', params: { id: queue.id } })
		},
		getItemCount(queue) {
			return this.itemCounts[queue.id] || 0
		},
		getAgentCount(queue) {
			return (queue.assignedAgents || []).length
		},
		async createQueue() {
			const categories = this.newQueue.categoriesInput
				? this.newQueue.categoriesInput.split(',').map(c => c.trim()).filter(Boolean)
				: []

			const data = {
				title: this.newQueue.title,
				description: this.newQueue.description || undefined,
				categories,
				isActive: true,
			}

			if (this.newQueue.maxCapacity) {
				data.maxCapacity = this.newQueue.maxCapacity
			}

			const result = await this.queuesStore.saveQueue(data)
			if (result) {
				this.resetCreateForm()
			}
		},
		resetCreateForm() {
			this.showCreateDialog = false
			this.newQueue = {
				title: '',
				description: '',
				categoriesInput: '',
				maxCapacity: null,
			}
		},
	},
}
</script>

<style scoped>
.queue-list {
	padding: 20px;
	max-width: 1000px;
}

.queue-list__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 20px;
}

.queue-list__empty {
	text-align: center;
	padding: 60px 20px;
	color: var(--color-text-maxcontrast);
}

.queue-list__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 16px;
}

.queue-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
	cursor: pointer;
	transition: box-shadow 0.15s;
}

.queue-card:hover,
.queue-card:focus-visible {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	outline: none;
}

.queue-card--inactive {
	opacity: 0.6;
}

.queue-card__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.queue-card__title {
	font-weight: 700;
	font-size: 16px;
}

.queue-card__badge--inactive {
	font-size: 11px;
	padding: 2px 8px;
	border-radius: 10px;
	background: var(--color-background-darker, rgba(0, 0, 0, 0.07));
	color: var(--color-text-maxcontrast);
}

.queue-card__stats {
	display: flex;
	gap: 24px;
	margin-bottom: 8px;
}

.stat__value {
	font-weight: 700;
	font-size: 18px;
}

.stat__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-left: 4px;
}

.queue-card__categories {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-top: 8px;
}

.category-tag {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.create-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px 0;
}

.create-form label {
	font-weight: 600;
	font-size: 13px;
	margin-top: 4px;
}

.create-form input,
.create-form textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.create-form textarea {
	min-height: 60px;
	resize: vertical;
}
</style>
