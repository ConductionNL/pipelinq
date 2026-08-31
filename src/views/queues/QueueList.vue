<template>
	<div class="queue-list">
		<div class="queue-list__header">
			<h2>{{ t('pipelinq', 'Queues') }}</h2>
			<NcButton variant="primary" @click="showCreateDialog = true">
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
				role="button"
				tabindex="0"
				@click="openQueue(queue)"
				@keydown.enter="openQueue(queue)">
				<div class="queue-card__header">
					<span class="queue-card__title">{{ queue.title }}</span>
					<span
						v-if="queue.isActive === false"
						class="queue-card__badge queue-card__badge--inactive">
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
						<span class="stat__label">{{
							t('pipelinq', 'agents')
						}}</span>
					</div>
				</div>
				<div
					v-if="queue.categories && queue.categories.length"
					class="queue-card__categories">
					<span
						v-for="cat in queue.categories"
						:key="cat"
						class="category-tag">
						{{ cat }}
					</span>
				</div>
			</div>
		</div>

		<!-- Create Dialog — own file per ADR-004 (modal-isolation). -->
		<QueueCreateDialog
			v-if="showCreateDialog"
			@close="resetCreateForm"
			@create="createQueue" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import QueueCreateDialog from '../../dialogs/QueueCreateDialog.vue'
import { useQueuesStore } from '../../store/modules/queues.js'

export default {
	name: 'QueueList',
	components: {
		NcButton,
		NcLoadingIcon,
		QueueCreateDialog,
	},

	data() {
		return {
			showCreateDialog: false,
			itemCounts: {},
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-queues-ui/tasks.md#task-19
		 */
		queuesStore() {
			return useQueuesStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-queues-ui/tasks.md#task-16
		 */
		loading() {
			return this.queuesStore.loading
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-queues-ui/tasks.md#task-18
		 */
		queues() {
			return this.queuesStore.queues
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-queues-ui/tasks.md#task-21
		 */
		sortedQueues() {
			return [...this.queues].sort(
				(a, b) => (a.sortOrder || 0) - (b.sortOrder || 0),
			)
		},
	},

	mounted() {
		this.queuesStore.fetchQueues()
	},

	methods: {
		/**
		 * @param {object} queue The queue to open.
		 * @spec openspec/changes/reverse-2026-05-26-fe-queues-ui/tasks.md#task-17
		 */
		openQueue(queue) {
			this.$router.push({ name: 'QueueDetail', params: { id: queue.id } })
		},

		getItemCount(queue) {
			return this.itemCounts[queue.id] || 0
		},

		getAgentCount(queue) {
			return (queue.assignedAgents || []).length
		},

		/**
		 * @param {object} newQueue Raw form fields emitted by QueueCreateDialog.
		 * @spec openspec/changes/reverse-2026-05-26-fe-queues-ui/tasks.md#task-15
		 */
		async createQueue(newQueue) {
			const categories = newQueue.categoriesInput
				? newQueue.categoriesInput
						.split(',')
						.map((c) => c.trim())
						.filter(Boolean)
				: []

			const data = {
				title: newQueue.title,
				description: newQueue.description || undefined,
				categories,
				isActive: true,
			}

			if (newQueue.maxCapacity) {
				data.maxCapacity = newQueue.maxCapacity
			}

			const result = await this.queuesStore.saveQueue(data)
			if (result) {
				this.resetCreateForm()
			}
		},

		/**
		 * Close the create dialog. `v-if` unmounts QueueCreateDialog, which owns
		 * the form state, so closing is what clears the fields.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-queues-ui/tasks.md#task-20
		 */
		resetCreateForm() {
			this.showCreateDialog = false
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

@media (prefers-reduced-motion: reduce) {
	.queue-card {
		transition: none;
	}
}
</style>
