<template>
	<div class="pipeline-board">
		<div class="pipeline-board__header">
			<h2>{{ t('pipelinq', 'Pipeline') }}</h2>
			<div class="pipeline-board__controls">
				<NcSelect
					v-model="selectedPipelineId"
					:options="pipelineSelectOptions"
					:clearable="false"
					label="label"
					:reduce="o => o.value"
					:placeholder="t('pipelinq', 'Select pipeline')"
					class="pipeline-selector"
					@input="onPipelineChange" />
				<NcSelect
					v-if="isMixed"
					v-model="showFilter"
					:options="showFilterOptions"
					:clearable="false"
					class="show-filter" />
				<div class="view-toggle">
					<NcButton
						:type="viewMode === 'kanban' ? 'primary' : 'tertiary'"
						:aria-label="t('pipelinq', 'Kanban view')"
						@click="viewMode = 'kanban'">
						<template #icon>
							<ViewColumn :size="20" />
						</template>
					</NcButton>
					<NcButton
						:type="viewMode === 'list' ? 'primary' : 'tertiary'"
						:aria-label="t('pipelinq', 'List view')"
						@click="viewMode = 'list'">
						<template #icon>
							<FormatListBulleted :size="20" />
						</template>
					</NcButton>
				</div>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="!selectedPipeline" class="pipeline-board__empty">
			<p>{{ t('pipelinq', 'Select a pipeline to view the board') }}</p>
		</div>

		<!-- Kanban view -->
		<div v-else-if="viewMode === 'kanban'" class="pipeline-board__columns">
			<div
				v-for="stage in openStages"
				:key="stage.name"
				class="kanban-column"
				@dragover.prevent
				@drop="onDrop($event, stage)">
				<div class="kanban-column__header" :style="stage.color ? { borderTopColor: stage.color } : {}">
					<div class="column-header-top">
						<span class="column-title">{{ stage.name }}</span>
						<span class="column-count">{{ getStageItems(stage.name).length }}</span>
					</div>
					<span class="column-value">
						EUR {{ getStageTotalValue(stage.name).toLocaleString('nl-NL') }}
					</span>
				</div>
				<div class="kanban-column__body">
					<PipelineCard
						v-for="item in getStageItems(stage.name)"
						:key="item.id"
						:item="item"
						:entity-type="item._entityType"
						:stages="sortedStages"
						@open="openItem"
						@refresh="fetchPipelineItems" />
				</div>
			</div>

			<!-- Collapsed closed stages -->
			<div v-if="closedStages.length > 0" class="kanban-closed">
				<div
					v-for="stage in closedStages"
					:key="stage.name"
					class="kanban-closed-column"
					:class="{ expanded: expandedClosed === stage.name }"
					@click="toggleClosedStage(stage.name)"
					@dragover.prevent
					@drop="onDrop($event, stage)">
					<span class="closed-title">{{ stage.name.toUpperCase() }}</span>
					<span class="closed-count">{{ getStageItems(stage.name).length }}</span>
					<div v-if="expandedClosed === stage.name" class="closed-items" @click.stop>
						<PipelineCard
							v-for="item in getStageItems(stage.name)"
							:key="item.id"
							:item="item"
							:entity-type="item._entityType"
							:stages="sortedStages"
							@open="openItem"
							@refresh="fetchPipelineItems" />
					</div>
				</div>
			</div>
		</div>

		<!-- List view -->
		<div v-else class="pipeline-board__list">
			<table class="list-table">
				<thead>
					<tr>
						<th class="sortable" @click="toggleSort('title')">
							{{ t('pipelinq', 'Title') }}
							<span v-if="sortBy === 'title'" class="sort-indicator">{{ sortDir === 'asc' ? '\u25B2' : '\u25BC' }}</span>
						</th>
						<th class="sortable" @click="toggleSort('entityType')">
							{{ t('pipelinq', 'Type') }}
							<span v-if="sortBy === 'entityType'" class="sort-indicator">{{ sortDir === 'asc' ? '\u25B2' : '\u25BC' }}</span>
						</th>
						<th class="sortable" @click="toggleSort('stage')">
							{{ t('pipelinq', 'Stage') }}
							<span v-if="sortBy === 'stage'" class="sort-indicator">{{ sortDir === 'asc' ? '\u25B2' : '\u25BC' }}</span>
						</th>
						<th class="sortable" @click="toggleSort('assignee')">
							{{ t('pipelinq', 'Assignee') }}
							<span v-if="sortBy === 'assignee'" class="sort-indicator">{{ sortDir === 'asc' ? '\u25B2' : '\u25BC' }}</span>
						</th>
						<th class="sortable" @click="toggleSort('value')">
							{{ t('pipelinq', 'Value') }}
							<span v-if="sortBy === 'value'" class="sort-indicator">{{ sortDir === 'asc' ? '\u25B2' : '\u25BC' }}</span>
						</th>
						<th class="sortable" @click="toggleSort('dueDate')">
							{{ t('pipelinq', 'Due Date') }}
							<span v-if="sortBy === 'dueDate'" class="sort-indicator">{{ sortDir === 'asc' ? '\u25B2' : '\u25BC' }}</span>
						</th>
						<th class="sortable" @click="toggleSort('priority')">
							{{ t('pipelinq', 'Priority') }}
							<span v-if="sortBy === 'priority'" class="sort-indicator">{{ sortDir === 'asc' ? '\u25B2' : '\u25BC' }}</span>
						</th>
						<th class="sortable" @click="toggleSort('age')">
							{{ t('pipelinq', 'Age') }}
							<span v-if="sortBy === 'age'" class="sort-indicator">{{ sortDir === 'asc' ? '\u25B2' : '\u25BC' }}</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="item in sortedListItems"
						:key="item.id"
						class="list-row"
						:class="{ 'list-row--overdue': isItemOverdue(item) }"
						@click="openItem(item)">
						<td class="list-title">
							{{ item.title }}
							<span v-if="isItemStale(item)" class="stale-badge">
								{{ t('pipelinq', 'Stale') }}
							</span>
						</td>
						<td>
							<span class="entity-badge" :class="'badge--' + item._entityType">
								{{ item._entityType === 'lead' ? 'LEAD' : 'REQ' }}
							</span>
						</td>
						<td>{{ item.stage }}</td>
						<td>{{ item.assignee || '\u2014' }}</td>
						<td>
							<span v-if="item._entityType === 'lead' && item.value">
								EUR {{ Number(item.value).toLocaleString('nl-NL') }}
							</span>
							<span v-else>\u2014</span>
						</td>
						<td :class="{ 'overdue-date': isItemOverdue(item) }">
							{{ formatDate(item.expectedCloseDate || item.requestedAt) }}
						</td>
						<td>
							<span v-if="item.priority" :style="{ color: getPriorityColor(item.priority) }">
								{{ getPriorityLabel(item.priority) }}
							</span>
						</td>
						<td>
							<span class="aging-badge" :class="getItemAgingClass(item)">
								{{ getItemAgeLabel(item) }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-if="sortedListItems.length === 0" class="list-empty">
				{{ t('pipelinq', 'No items in this pipeline') }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import PipelineCard from './PipelineCard.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { getPriorityLabel, getPriorityColor } from '../../services/requestStatus.js'
import { getDaysAge, isStale, getAgingClass, formatAge } from '../../services/pipelineUtils.js'

export default {
	name: 'PipelineBoard',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		PipelineCard,
		ViewColumn,
		FormatListBulleted,
	},
	data() {
		return {
			selectedPipelineId: null,
			showFilter: 'all',
			expandedClosed: null,
			loading: false,
			leads: [],
			requests: [],
			viewMode: 'kanban',
			sortBy: 'title',
			sortDir: 'asc',
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		pipelines() {
			return this.objectStore.collections.pipeline || []
		},
		pipelineSelectOptions() {
			return this.pipelines.map(p => ({
				value: p.id,
				label: `${p.title} (${this.entityTypeLabel(p.entityType)})`,
			}))
		},
		selectedPipeline() {
			if (!this.selectedPipelineId) return null
			return this.pipelines.find(p => p.id === this.selectedPipelineId) || null
		},
		isMixed() {
			return this.selectedPipeline?.entityType === 'both'
		},
		showFilterOptions() {
			return [
				{ id: 'all', label: t('pipelinq', 'All') },
				{ id: 'lead', label: t('pipelinq', 'Leads only') },
				{ id: 'request', label: t('pipelinq', 'Requests only') },
			]
		},
		sortedStages() {
			if (!this.selectedPipeline?.stages) return []
			return [...this.selectedPipeline.stages].sort((a, b) => a.order - b.order)
		},
		openStages() {
			return this.sortedStages.filter(s => !s.isClosed)
		},
		closedStages() {
			return this.sortedStages.filter(s => s.isClosed)
		},
		allItems() {
			let items = []
			const et = this.selectedPipeline?.entityType

			if (et === 'lead' || et === 'both') {
				items = items.concat(this.leads.map(l => ({ ...l, _entityType: 'lead' })))
			}
			if (et === 'request' || et === 'both') {
				items = items.concat(this.requests.map(r => ({ ...r, _entityType: 'request' })))
			}

			// Apply show filter
			const filter = this.showFilter?.id || this.showFilter || 'all'
			if (filter !== 'all') {
				items = items.filter(i => i._entityType === filter)
			}

			return items
		},
		sortedListItems() {
			const items = [...this.allItems]
			const priorityOrder = { urgent: 0, high: 1, normal: 2, low: 3 }
			items.sort((a, b) => {
				let valA, valB
				switch (this.sortBy) {
				case 'title':
					valA = (a.title || '').toLowerCase()
					valB = (b.title || '').toLowerCase()
					break
				case 'entityType':
					valA = a._entityType
					valB = b._entityType
					break
				case 'stage':
					valA = (a.stage || '').toLowerCase()
					valB = (b.stage || '').toLowerCase()
					break
				case 'assignee':
					valA = (a.assignee || '').toLowerCase()
					valB = (b.assignee || '').toLowerCase()
					break
				case 'value':
					valA = Number(a.value) || 0
					valB = Number(b.value) || 0
					break
				case 'dueDate':
					valA = a.expectedCloseDate || a.requestedAt || ''
					valB = b.expectedCloseDate || b.requestedAt || ''
					break
				case 'priority':
					valA = priorityOrder[a.priority] ?? 2
					valB = priorityOrder[b.priority] ?? 2
					break
				case 'age':
					valA = getDaysAge(a)
					valB = getDaysAge(b)
					break
				default:
					return 0
				}
				if (valA < valB) return this.sortDir === 'asc' ? -1 : 1
				if (valA > valB) return this.sortDir === 'asc' ? 1 : -1
				return 0
			})
			return items
		},
	},
	async mounted() {
		this.loading = true
		await this.objectStore.fetchCollection('pipeline', { _limit: 100 })

		// Auto-select first pipeline
		if (this.pipelines.length > 0) {
			const defaultPipeline = this.pipelines.find(p => p.isDefault) || this.pipelines[0]
			this.selectedPipelineId = defaultPipeline.id
			await this.fetchPipelineItems()
		}
		this.loading = false
	},
	methods: {
		getPriorityLabel,
		getPriorityColor,

		entityTypeLabel(type) {
			if (type === 'both') return t('pipelinq', 'Leads, Requests')
			if (type === 'request') return t('pipelinq', 'Requests')
			return t('pipelinq', 'Leads')
		},

		getStageItems(stageName) {
			return this.allItems
				.filter(i => i.stage === stageName)
				.sort((a, b) => (a.stageOrder || 0) - (b.stageOrder || 0))
		},

		getStageTotalValue(stageName) {
			return this.getStageItems(stageName)
				.filter(i => i._entityType === 'lead' && i.value)
				.reduce((sum, i) => sum + Number(i.value), 0)
		},

		async onPipelineChange() {
			await this.fetchPipelineItems()
		},

		async fetchPipelineItems() {
			if (!this.selectedPipelineId) return
			this.loading = true

			const et = this.selectedPipeline?.entityType
			const promises = []

			if (et === 'lead' || et === 'both') {
				promises.push(
					this.fetchItems('lead').then(items => { this.leads = items }),
				)
			} else {
				this.leads = []
			}

			if (et === 'request' || et === 'both') {
				promises.push(
					this.fetchItems('request').then(items => { this.requests = items }),
				)
			} else {
				this.requests = []
			}

			await Promise.all(promises)
			this.loading = false
		},

		async fetchItems(type) {
			const config = this.objectStore.objectTypeRegistry[type]
			if (!config) return []

			try {
				const url = `/apps/openregister/api/objects/${config.register}/${config.schema}?pipeline=${this.selectedPipelineId}&_limit=200`
				const response = await fetch(url, {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})
				if (!response.ok) return []
				const data = await response.json()
				return data.results || data || []
			} catch {
				return []
			}
		},

		async onDrop(event, targetStage) {
			try {
				const data = JSON.parse(event.dataTransfer.getData('application/json'))
				if (data.stage === targetStage.name) return

				await this.objectStore.saveObject(data.entityType, {
					id: data.id,
					stage: targetStage.name,
					stageOrder: targetStage.order,
				})
				await this.fetchPipelineItems()
			} catch {
				// Invalid drop
			}
		},

		toggleClosedStage(stageName) {
			this.expandedClosed = this.expandedClosed === stageName ? null : stageName
		},

		toggleSort(column) {
			if (this.sortBy === column) {
				this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'
			} else {
				this.sortBy = column
				this.sortDir = 'asc'
			}
		},

		isItemOverdue(item) {
			const dateStr = item.expectedCloseDate || item.requestedAt
			if (!dateStr) return false
			return new Date(dateStr) < new Date()
		},

		formatDate(dateStr) {
			if (!dateStr) return '\u2014'
			try {
				return new Date(dateStr).toLocaleDateString('nl-NL', { month: 'short', day: 'numeric' })
			} catch {
				return dateStr
			}
		},

		isItemStale(item) {
			return isStale(item, item._entityType)
		},

		getItemAgingClass(item) {
			return getAgingClass(getDaysAge(item))
		},

		getItemAgeLabel(item) {
			return formatAge(getDaysAge(item))
		},

		openItem(item) {
			if (item._entityType === 'lead') {
				this.$router.push({ name: 'LeadDetail', params: { id: item.id } })
			} else {
				this.$router.push({ name: 'RequestDetail', params: { id: item.id } })
			}
		},
	},
}
</script>

<style scoped>
.pipeline-board {
	padding: 20px;
	height: 100%;
	display: flex;
	flex-direction: column;
}

.pipeline-board__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
	flex-shrink: 0;
}

.pipeline-board__controls {
	display: flex;
	gap: 12px;
	align-items: center;
}

.pipeline-selector {
	min-width: 250px;
}

.show-filter {
	min-width: 140px;
}

.view-toggle {
	display: flex;
	gap: 2px;
	margin-left: 8px;
}

.pipeline-board__empty {
	padding: 60px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

/* Kanban styles */
.pipeline-board__columns {
	display: flex;
	gap: 12px;
	overflow-x: auto;
	flex: 1;
	align-items: flex-start;
}

.kanban-column {
	min-width: 260px;
	max-width: 300px;
	flex-shrink: 0;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	display: flex;
	flex-direction: column;
	max-height: calc(100vh - 200px);
}

.kanban-column__header {
	padding: 12px;
	border-top: 3px solid var(--color-primary);
	border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
}

.column-header-top {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.column-title {
	font-weight: 700;
	font-size: 13px;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.column-count {
	display: inline-block;
	min-width: 20px;
	text-align: center;
	padding: 0 5px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	background: var(--color-background-darker, rgba(0,0,0,0.07));
	color: var(--color-text-maxcontrast);
}

.column-value {
	display: block;
	font-size: 14px;
	font-weight: 700;
	color: var(--color-text-light);
	margin-top: 2px;
}

.kanban-column__body {
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	overflow-y: auto;
	flex: 1;
}

/* Closed stages */
.kanban-closed {
	display: flex;
	gap: 8px;
	margin-left: 8px;
	flex-shrink: 0;
}

.kanban-closed-column {
	min-width: 100px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
	padding: 12px;
	cursor: pointer;
	text-align: center;
}

.kanban-closed-column.expanded {
	min-width: 240px;
}

.closed-title {
	font-weight: 700;
	font-size: 12px;
	letter-spacing: 0.5px;
}

.closed-count {
	display: block;
	font-size: 18px;
	font-weight: 700;
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
}

.closed-items {
	margin-top: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

/* List view styles */
.pipeline-board__list {
	flex: 1;
	overflow: auto;
}

.list-table {
	width: 100%;
	border-collapse: collapse;
}

.list-table th {
	text-align: left;
	padding: 10px 12px;
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: var(--color-text-maxcontrast);
	border-bottom: 2px solid var(--color-border);
	white-space: nowrap;
	user-select: none;
}

.list-table th.sortable {
	cursor: pointer;
}

.list-table th.sortable:hover {
	color: var(--color-main-text);
}

.sort-indicator {
	font-size: 10px;
	margin-left: 4px;
}

.list-row {
	cursor: pointer;
	transition: background 0.15s;
}

.list-row:hover {
	background: var(--color-background-hover);
}

.list-row td {
	padding: 10px 12px;
	font-size: 13px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.list-title {
	font-weight: 600;
}

.entity-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.5px;
}

.badge--lead {
	background: #dbeafe;
	color: #1d4ed8;
	border: 1px solid #93c5fd;
}

.badge--request {
	background: #ffedd5;
	color: #c2410c;
	border: 1px solid #fdba74;
}

.overdue-date {
	color: var(--color-error);
	font-weight: 600;
}

.list-row--overdue {
	background: rgba(220, 38, 38, 0.04);
}

.stale-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	background: #fff7ed;
	color: #c2410c;
	border: 1px solid #fdba74;
	margin-left: 6px;
	vertical-align: middle;
}

.aging-badge {
	display: inline-block;
	padding: 1px 5px;
	border-radius: 4px;
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
}

.aging-badge.aging-warning {
	color: #92400e;
	background: #fef3c7;
}

.aging-badge.aging-alert {
	color: #991b1b;
	background: #fee2e2;
}

.list-empty {
	padding: 40px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>
