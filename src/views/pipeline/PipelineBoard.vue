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
					v-if="hasMultipleSchemas"
					v-model="showFilter"
					:options="showFilterOptions"
					:clearable="false"
					class="show-filter" />
				<NcTextField
					:value="searchQuery"
					:placeholder="t('pipelinq', 'Search items...')"
					class="pipeline-search"
					@update:value="v => searchQuery = v" />
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
				<NcButton
					type="tertiary"
					:aria-label="t('pipelinq', 'Pipeline settings')"
					@click="toggleSidebar">
					<template #icon>
						<Cog :size="20" />
					</template>
				</NcButton>
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
					<span v-if="hasTotals" class="column-value">
						{{ selectedPipeline.totalsLabel || '' }} {{ getStageTotalValue(stage.name).toLocaleString() }}
					</span>
				</div>
				<div class="kanban-column__body">
					<PipelineCard
						v-for="item in getStageItems(stage.name)"
						:key="item.id"
						:item="item"
						:entity-type="item._schemaSlug"
						:stages="sortedStages"
						:column-property="getColumnProperty(item)"
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
							:entity-type="item._schemaSlug"
							:stages="sortedStages"
							:column-property="getColumnProperty(item)"
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
						<th class="sortable" @click="toggleSort('schemaSlug')">
							{{ t('pipelinq', 'Type') }}
							<span v-if="sortBy === 'schemaSlug'" class="sort-indicator">{{ sortDir === 'asc' ? '\u25B2' : '\u25BC' }}</span>
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
							<span class="entity-badge" :class="'badge--' + item._schemaSlug">
								{{ item._schemaSlug.toUpperCase().slice(0, 4) }}
							</span>
						</td>
						<td>{{ getItemColumnValue(item) }}</td>
						<td>{{ item.assignee || '\u2014' }}</td>
						<td>
							<span v-if="getItemTotalsValue(item) !== null">
								{{ selectedPipeline.totalsLabel || '' }} {{ Number(getItemTotalsValue(item)).toLocaleString() }}
							</span>
							<span v-else>&#x2014;</span>
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
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import ViewColumn from 'vue-material-design-icons/ViewColumn.vue'
import FormatListBulleted from 'vue-material-design-icons/FormatListBulleted.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import PipelineCard from './PipelineCard.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { getPriorityLabel, getPriorityColor } from '../../services/requestStatus.js'
import { getDaysAge, isStale, getAgingClass, formatAge } from '../../services/pipelineUtils.js'
import { formatDate } from '../../services/localeUtils.js'

export default {
	name: 'PipelineBoard',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		PipelineCard,
		ViewColumn,
		FormatListBulleted,
		Cog,
	},
	inject: {
		pipelineSidebarState: { default: null },
	},
	data() {
		return {
			selectedPipelineId: null,
			showFilter: 'all',
			searchQuery: '',
			expandedClosed: null,
			loading: false,
			items: [],
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
				label: p.title,
			}))
		},
		selectedPipeline() {
			if (!this.selectedPipelineId) return null
			return this.pipelines.find(p => p.id === this.selectedPipelineId) || null
		},
		propertyMappings() {
			return this.selectedPipeline?.propertyMappings || []
		},
		hasMultipleSchemas() {
			return this.propertyMappings.length > 1
		},
		hasTotals() {
			return this.propertyMappings.some(m => m.totalsProperty)
		},
		showFilterOptions() {
			const options = [{ id: 'all', label: t('pipelinq', 'All') }]
			for (const mapping of this.propertyMappings) {
				options.push({
					id: mapping.schemaSlug,
					label: mapping.schemaSlug.charAt(0).toUpperCase() + mapping.schemaSlug.slice(1) + 's',
				})
			}
			return options
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
			let result = this.items
			const filter = this.showFilter?.id || this.showFilter || 'all'
			if (filter !== 'all') {
				result = result.filter(i => i._schemaSlug === filter)
			}
			if (this.searchQuery.trim()) {
				const query = this.searchQuery.trim().toLowerCase()
				result = result.filter(i => (i.title || '').toLowerCase().includes(query))
			}
			return result
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
				case 'schemaSlug':
					valA = a._schemaSlug
					valB = b._schemaSlug
					break
				case 'stage':
					valA = (this.getItemColumnValue(a) || '').toLowerCase()
					valB = (this.getItemColumnValue(b) || '').toLowerCase()
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
	watch: {
		selectedPipeline(val) {
			this.syncSidebarState(val)
		},
	},
	async mounted() {
		// Activate pipeline sidebar
		if (this.pipelineSidebarState) {
			this.pipelineSidebarState.active = true
			this.pipelineSidebarState.onSave = this.onSidebarSave
		}

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
	beforeDestroy() {
		if (this.pipelineSidebarState) {
			this.pipelineSidebarState.active = false
			this.pipelineSidebarState.pipeline = null
			this.pipelineSidebarState.onSave = null
		}
	},
	methods: {
		getPriorityLabel,
		getPriorityColor,

		syncSidebarState(pipeline) {
			if (this.pipelineSidebarState) {
				this.pipelineSidebarState.pipeline = pipeline
			}
		},

		toggleSidebar() {
			if (this.pipelineSidebarState) {
				this.pipelineSidebarState.open = !this.pipelineSidebarState.open
			}
		},

		async onSidebarSave(pipelineData) {
			await this.objectStore.saveObject('pipeline', pipelineData)
			await this.objectStore.fetchCollection('pipeline', { _limit: 100 })
			this.syncSidebarState(this.selectedPipeline)
			await this.fetchPipelineItems()
		},

		getMappingForItem(item) {
			return this.propertyMappings.find(m => m.schemaSlug === item._schemaSlug) || null
		},

		getColumnProperty(item) {
			const mapping = this.getMappingForItem(item)
			return mapping?.columnProperty || 'stage'
		},

		getItemColumnValue(item) {
			return item[this.getColumnProperty(item)] || ''
		},

		getItemTotalsValue(item) {
			const mapping = this.getMappingForItem(item)
			if (!mapping?.totalsProperty) return null
			const val = item[mapping.totalsProperty]
			return val !== undefined && val !== null ? val : null
		},

		getStageItems(stageName) {
			return this.allItems
				.filter(item => {
					const colValue = this.getItemColumnValue(item)
					if (colValue === stageName) return true
					// Items with no matching column go to first non-closed stage
					if (!colValue && this.openStages.length > 0 && this.openStages[0].name === stageName) return true
					return false
				})
				.sort((a, b) => (a.stageOrder || 0) - (b.stageOrder || 0))
		},

		getStageTotalValue(stageName) {
			const stageItems = this.getStageItems(stageName)
			let total = 0
			for (const item of stageItems) {
				const mapping = this.getMappingForItem(item)
				if (mapping?.totalsProperty && item[mapping.totalsProperty]) {
					total += Number(item[mapping.totalsProperty])
				}
			}
			return total
		},

		async onPipelineChange() {
			await this.fetchPipelineItems()
		},

		async fetchPipelineItems() {
			if (!this.selectedPipelineId) return
			this.loading = true

			const pipeline = this.selectedPipeline
			if (pipeline?.propertyMappings && pipeline.propertyMappings.length > 0) {
				await this.fetchItemsViaMappings(pipeline)
			} else {
				// Legacy fallback for pipelines without propertyMappings
				await this.fetchItemsLegacy(pipeline)
			}

			this.loading = false
		},

		async fetchItemsViaMappings(pipeline) {
			const mappings = pipeline.propertyMappings || []
			const promises = mappings.map(async (mapping) => {
				const rawItems = await this.fetchSchemaItems(mapping.schemaSlug)
				return rawItems.map(item => ({
					...item,
					_schemaSlug: mapping.schemaSlug,
					_entityType: mapping.schemaSlug,
				}))
			})
			const results = await Promise.all(promises)
			this.items = results.flat()
		},

		async fetchItemsLegacy(pipeline) {
			// Fallback for old pipelines with entityType
			const et = pipeline?.entityType
			const promises = []
			let leads = []
			let requests = []

			if (et === 'lead' || et === 'both') {
				promises.push(this.fetchSchemaItems('lead').then(items => { leads = items }))
			}
			if (et === 'request' || et === 'both') {
				promises.push(this.fetchSchemaItems('request').then(items => { requests = items }))
			}

			await Promise.all(promises)
			this.items = [
				...leads.map(l => ({ ...l, _schemaSlug: 'lead', _entityType: 'lead' })),
				...requests.map(r => ({ ...r, _schemaSlug: 'request', _entityType: 'request' })),
			]
		},

		async fetchSchemaItems(schemaSlug) {
			const config = this.objectStore.objectTypeRegistry[schemaSlug]
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
				const mapping = this.propertyMappings.find(m => m.schemaSlug === data._schemaSlug)
				const columnProp = mapping?.columnProperty || 'stage'

				if (data[columnProp] === targetStage.name) return

				const update = { id: data.id }
				update[columnProp] = targetStage.name
				update.stageOrder = targetStage.order

				await this.objectStore.saveObject(data._schemaSlug, update)
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
			return formatDate(dateStr)
		},

		isItemStale(item) {
			return isStale(item, item._schemaSlug)
		},

		getItemAgingClass(item) {
			return getAgingClass(getDaysAge(item))
		},

		getItemAgeLabel(item) {
			return formatAge(getDaysAge(item))
		},

		openItem(item) {
			if (item._schemaSlug === 'lead') {
				this.$router.push({ name: 'LeadDetail', params: { id: item.id } })
			} else if (item._schemaSlug === 'request') {
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

.pipeline-search {
	min-width: 200px;
	max-width: 300px;
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

/* Kanban styles — dashboard widget container pattern */
.pipeline-board__columns {
	display: flex;
	gap: 12px;
	overflow-x: auto;
	flex: 1;
	align-items: flex-start;
	padding-bottom: 8px;
}

.kanban-column {
	min-width: 260px;
	max-width: 300px;
	flex-shrink: 0;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	display: flex;
	flex-direction: column;
	max-height: calc(100vh - 200px);
}

.kanban-column__header {
	padding: 10px 12px;
	border-top: 3px solid var(--color-primary);
	border-radius: var(--border-radius-large) var(--border-radius-large) 0 0;
	border-bottom: 1px solid var(--color-border);
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
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 20px;
	height: 20px;
	padding: 0 6px;
	border-radius: 10px;
	font-size: 11px;
	font-weight: 600;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.column-value {
	display: block;
	font-size: 13px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.kanban-column__body {
	padding: 4px;
	display: flex;
	flex-direction: column;
	gap: 1px;
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
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	cursor: pointer;
	text-align: center;
	transition: background 0.15s;
}

.kanban-closed-column:hover {
	background: var(--color-background-hover);
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
	gap: 1px;
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
