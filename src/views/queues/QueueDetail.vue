<template>
	<div class="queue-detail">
		<div class="queue-detail__header">
			<NcButton @click="$router.push({ name: 'Queues' })">
				{{ t('pipelinq', 'Back to queues') }}
			</NcButton>
			<h2>{{ queue ? queue.title : t('pipelinq', 'Queue') }}</h2>
			<div v-if="queue" class="queue-detail__actions">
				<NcButton type="primary" :disabled="!nextItem" @click="pickNext">
					{{ t('pipelinq', 'Pick next') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" />

		<template v-else-if="queue">
			<!-- Queue metadata -->
			<div class="queue-detail__meta">
				<span class="meta-stat">
					{{ items.length }} {{ t('pipelinq', 'items') }}
					<template v-if="queue.maxCapacity"> / {{ queue.maxCapacity }}</template>
				</span>
				<span class="meta-stat">{{ agentCount }} {{ t('pipelinq', 'agents') }}</span>
				<span v-if="queue.isActive === false" class="inactive-badge">{{ t('pipelinq', 'Inactive') }}</span>
			</div>

			<!-- Category tags -->
			<div v-if="queue.categories && queue.categories.length" class="queue-detail__categories">
				<span v-for="cat in queue.categories" :key="cat" class="category-tag">{{ cat }}</span>
			</div>

			<!-- Items -->
			<div v-if="sortedItems.length === 0" class="queue-detail__empty">
				<p>{{ t('pipelinq', 'This queue is empty.') }}</p>
			</div>

			<div v-else class="queue-detail__items">
				<div
					v-for="(item, index) in sortedItems"
					:key="item.id"
					class="queue-item"
					:class="{ 'queue-item--selected': selectedIds.has(item.id) }"
					tabindex="0"
					@click.exact="openItem(item)"
					@click.ctrl="toggleSelect(item.id)"
					@keydown.enter="openItem(item)">
					<div class="queue-item__position">
						{{ index + 1 }}
					</div>
					<div class="queue-item__content">
						<div class="queue-item__top">
							<span class="entity-badge badge--request">REQ</span>
							<span
								v-if="item.priority && item.priority !== 'normal'"
								class="priority-badge"
								:style="{ color: getPriorityColor(item.priority) }">
								{{ getPriorityLabel(item.priority) }}
							</span>
						</div>
						<div class="queue-item__title">
							{{ item.title }}
						</div>
						<div class="queue-item__meta">
							<span v-if="item.category" class="meta-tag">{{ item.category }}</span>
							<span class="meta-waiting">{{ getWaitingTime(item.requestedAt || item.dateCreated) }}</span>
							<span v-if="item.assignee" class="meta-assignee">{{ item.assignee }}</span>
							<span v-else class="meta-unassigned">{{ t('pipelinq', 'Unassigned') }}</span>
						</div>
					</div>
					<div class="queue-item__actions" @click.stop>
						<NcButton
							v-if="!item.assignee"
							:aria-label="t('pipelinq', 'Pick')"
							@click="assignToMe(item)">
							{{ t('pipelinq', 'Pick') }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Bulk actions -->
			<div v-if="selectedIds.size > 0" class="queue-detail__bulk">
				<NcButton @click="bulkAssignToMe">
					{{ t('pipelinq', 'Assign {count} to me', { count: selectedIds.size }) }}
				</NcButton>
				<NcButton @click="selectedIds.clear()">
					{{ t('pipelinq', 'Clear selection') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useQueuesStore } from '../../store/modules/queues.js'
import { useObjectStore } from '../../store/modules/object.js'
import { prioritySortComparator, getWaitingTime } from '../../services/queueUtils.js'
import { getPriorityLabel, getPriorityColor } from '../../services/requestStatus.js'

export default {
	name: 'QueueDetail',
	components: {
		NcButton,
		NcLoadingIcon,
	},
	props: {
		queueId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			selectedIds: new Set(),
		}
	},
	computed: {
		queuesStore() {
			return useQueuesStore()
		},
		objectStore() {
			return useObjectStore()
		},
		loading() {
			return this.queuesStore.loading
		},
		queue() {
			return this.queuesStore.currentQueue
		},
		items() {
			return this.queuesStore.queueItems
		},
		sortedItems() {
			return [...this.items].sort(prioritySortComparator)
		},
		nextItem() {
			return this.sortedItems.find(item => !item.assignee) || null
		},
		agentCount() {
			return (this.queue?.assignedAgents || []).length
		},
	},
	async mounted() {
		await this.queuesStore.fetchQueue(this.queueId)
		await this.queuesStore.fetchQueueItems(this.queueId)
	},
	methods: {
		getPriorityLabel,
		getPriorityColor,
		getWaitingTime,

		openItem(item) {
			this.$router.push({ name: 'RequestDetail', params: { id: item.id } })
		},

		toggleSelect(id) {
			if (this.selectedIds.has(id)) {
				this.selectedIds.delete(id)
			} else {
				this.selectedIds.add(id)
			}
		},

		async assignToMe(item) {
			await this.objectStore.saveObject('request', {
				...item,
				assignee: OC.currentUser,
			})
			await this.queuesStore.fetchQueueItems(this.queueId)
		},

		async pickNext() {
			if (!this.nextItem) return
			await this.assignToMe(this.nextItem)
		},

		async bulkAssignToMe() {
			const promises = this.sortedItems
				.filter(item => this.selectedIds.has(item.id))
				.map(item => this.objectStore.saveObject('request', {
					...item,
					assignee: OC.currentUser,
				}))

			await Promise.all(promises)
			this.selectedIds.clear()
			await this.queuesStore.fetchQueueItems(this.queueId)
		},
	},
}
</script>

<style scoped>
.queue-detail {
	padding: 20px;
	max-width: 900px;
}

.queue-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 16px;
}

.queue-detail__actions {
	margin-left: auto;
}

.queue-detail__meta {
	display: flex;
	gap: 16px;
	margin-bottom: 12px;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.meta-stat {
	font-weight: 600;
}

.inactive-badge {
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	background: var(--color-background-darker, rgba(0, 0, 0, 0.07));
}

.queue-detail__categories {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-bottom: 16px;
}

.category-tag {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 11px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.queue-detail__empty {
	text-align: center;
	padding: 40px 20px;
	color: var(--color-text-maxcontrast);
}

.queue-detail__items {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.queue-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: box-shadow 0.15s;
}

.queue-item:hover,
.queue-item:focus-visible {
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
	outline: none;
}

.queue-item--selected {
	border-color: var(--color-primary);
	background: var(--color-primary-element-light);
}

.queue-item__position {
	font-weight: 700;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	min-width: 28px;
	text-align: center;
}

.queue-item__content {
	flex: 1;
	min-width: 0;
}

.queue-item__top {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 2px;
}

.entity-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.5px;
}

.badge--request {
	background: #ffedd5;
	color: #c2410c;
	border: 1px solid #fdba74;
}

.priority-badge {
	font-size: 11px;
	font-weight: 600;
}

.queue-item__title {
	font-weight: 600;
	font-size: 14px;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.queue-item__meta {
	display: flex;
	gap: 8px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.meta-tag {
	padding: 0 6px;
	border-radius: 8px;
	background: var(--color-background-darker, rgba(0, 0, 0, 0.05));
}

.meta-unassigned {
	font-style: italic;
}

.queue-item__actions {
	flex-shrink: 0;
}

.queue-detail__bulk {
	position: sticky;
	bottom: 0;
	display: flex;
	gap: 8px;
	padding: 12px 16px;
	background: var(--color-main-background);
	border-top: 1px solid var(--color-border);
	margin-top: 16px;
}
</style>
