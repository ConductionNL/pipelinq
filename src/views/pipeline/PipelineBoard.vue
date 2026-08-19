<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<template>
	<div class="pipeline-board" data-walkthrough-id="pipeline-board">
		<div class="pipeline-board__header">
			<h2>{{ t('pipelinq', 'Pipeline') }}</h2>
			<div class="pipeline-board__controls">
				<NcSelect
					v-model="selectedPipelineId"
					:options="pipelineSelectOptions"
					:clearable="false"
					label="label"
					:input-label="t('pipelinq', 'Select pipeline')"
					:placeholder="t('pipelinq', 'Select pipeline')"
					:reduce="o => o.value"
					class="pipeline-selector"
					@update:model-value="onPipelineChange" />
				<NcSelect
					v-if="hasMultipleSchemas"
					v-model="showFilter"
					:options="showFilterOptions"
					:clearable="false"
					:input-label="t('pipelinq', 'Filter by type')"
					class="show-filter" />
				<NcTextField
					:model-value="searchQuery"
					type="search"
					label-outside
					:placeholder="t('pipelinq', 'Search pipeline...')"
					:aria-label="t('pipelinq', 'Search pipeline...')"
					class="pipeline-search"
					@update:model-value="v => searchQuery = v" />
				<div class="view-toggle">
					<NcButton
						:variant="viewMode === 'kanban' ? 'primary' : 'tertiary'"
						:aria-label="t('pipelinq', 'Kanban view')"
						@click="viewMode = 'kanban'">
						<template #icon>
							<ViewColumn :size="20" />
						</template>
					</NcButton>
					<NcButton
						:variant="viewMode === 'list' ? 'primary' : 'tertiary'"
						:aria-label="t('pipelinq', 'List view')"
						@click="viewMode = 'list'">
						<template #icon>
							<FormatListBulleted :size="20" />
						</template>
					</NcButton>
				</div>
				<NcButton
					variant="tertiary"
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
					<div v-if="hasTotals" class="column-value-wrapper">
						<button
							type="button"
							class="column-value column-value--clickable"
							:aria-label="t('pipelinq', 'Show product breakdown for {stage}', { stage: stage.name })"
							:aria-expanded="String(expandedBreakdownStage === stage.name)"
							@click.stop="toggleStageBreakdown(stage.name)">
							{{ selectedPipeline.totalsLabel || '' }} {{ getStageTotalValue(stage.name).toLocaleString() }}
						</button>
						<div
							v-if="expandedBreakdownStage === stage.name"
							class="stage-breakdown"
							role="dialog"
							:aria-label="t('pipelinq', 'Product breakdown for {stage}', { stage: stage.name })"
							@click.stop>
							<div class="stage-breakdown__header">
								<span class="stage-breakdown__title">
									{{ t('pipelinq', 'Top products in {stage}', { stage: stage.name }) }}
								</span>
								<button
									type="button"
									class="stage-breakdown__close"
									:aria-label="t('pipelinq', 'Close')"
									@click.stop="expandedBreakdownStage = null">
									&times;
								</button>
							</div>
							<div v-if="getStageBreakdown(stage.name).items.length === 0" class="stage-breakdown__empty">
								{{ t('pipelinq', 'No product breakdown available for this stage') }}
							</div>
							<ul v-else class="stage-breakdown__list">
								<li
									v-for="entry in getStageBreakdown(stage.name).items"
									:key="entry.product"
									class="stage-breakdown__row">
									<span class="stage-breakdown__name">{{ entry.name }}</span>
									<span class="stage-breakdown__count">{{ entry.count }}&times;</span>
									<span class="stage-breakdown__total">
										{{ selectedPipeline.totalsLabel || '' }} {{ entry.total.toLocaleString() }}
									</span>
								</li>
								<li
									v-if="getStageBreakdown(stage.name).remaining > 0"
									class="stage-breakdown__more">
									{{ t('pipelinq', 'and {count} more', { count: getStageBreakdown(stage.name).remaining }) }}
								</li>
							</ul>
						</div>
					</div>
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
					role="button"
					tabindex="0"
					:aria-expanded="expandedClosed === stage.name"
					:aria-label="t('pipelinq', 'Toggle closed stage {name}', { name: stage.name })"
					@click="toggleClosedStage(stage.name)"
					@keydown.enter.prevent="toggleClosedStage(stage.name)"
					@keydown.space.prevent="toggleClosedStage(stage.name)"
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
						<th scope="col" class="sortable" @click="toggleSort('title')">
							{{ t('pipelinq', 'Title') }}
							<span v-if="sortBy === 'title'" class="sort-indicator">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
						</th>
						<th scope="col" class="sortable" @click="toggleSort('schemaSlug')">
							{{ t('pipelinq', 'Type') }}
							<span v-if="sortBy === 'schemaSlug'" class="sort-indicator">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
						</th>
						<th scope="col" class="sortable" @click="toggleSort('stage')">
							{{ t('pipelinq', 'Stage') }}
							<span v-if="sortBy === 'stage'" class="sort-indicator">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
						</th>
						<th scope="col" class="sortable" @click="toggleSort('assignee')">
							{{ t('pipelinq', 'Assignee') }}
							<span v-if="sortBy === 'assignee'" class="sort-indicator">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
						</th>
						<th scope="col" class="sortable" @click="toggleSort('value')">
							{{ t('pipelinq', 'Value') }}
							<span v-if="sortBy === 'value'" class="sort-indicator">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
						</th>
						<th scope="col" class="sortable" @click="toggleSort('dueDate')">
							{{ t('pipelinq', 'Due Date') }}
							<span v-if="sortBy === 'dueDate'" class="sort-indicator">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
						</th>
						<th scope="col" class="sortable" @click="toggleSort('priority')">
							{{ t('pipelinq', 'Priority') }}
							<span v-if="sortBy === 'priority'" class="sort-indicator">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
						</th>
						<th scope="col" class="sortable" @click="toggleSort('age')">
							{{ t('pipelinq', 'Age') }}
							<span v-if="sortBy === 'age'" class="sort-indicator">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
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
						<td>{{ item.assignee || '—' }}</td>
						<td>
							<span v-if="getItemTotalsValue(item) !== null">
								{{ selectedPipeline.totalsLabel || '' }} {{ Number(getItemTotalsValue(item)).toLocaleString() }}
							</span>
							<span v-else>&#x2014;</span>
						</td>
						<td :class="{ 'overdue-date': isItemOverdue(item) }">
							{{ formatDate(item.expectedCloseDate || item.occurredAt) }}
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
import { initializeStores } from '../../store/store.js'
import { getPriorityLabel, getPriorityColor } from '../../services/requestStatus.js'
import { getDaysAge, isStale, getAgingClass, formatAge, resolveObjectType } from '../../services/pipelineUtils.js'
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
			/**
			 * @spec openspec/changes/2026-03-20-pipeline/tasks.md#task-1.1
			 */
			searchQuery: '',
			expandedClosed: null,
			loading: false,
			items: [],
			viewMode: 'kanban',
			sortBy: 'title',
			sortDir: 'asc',
			/**
			 * Live-updates handles for the or-collection-{register}-{schema}
			 * subscriptions of every object type mapped into the selected
			 * pipeline (nc-vue liveUpdatesPlugin, default-on since
			 * beta.212). Managed by syncLiveSubscriptions(). liveTypesKey
			 * marks the (possibly in-flight) subscribed scope so a
			 * concurrent same-scope call doesn't double-subscribe;
			 * liveEpoch invalidates in-flight resolutions after a release
			 * (pipeline switch / destroy). Events are refetch HINTS only:
			 * the board re-runs fetchPipelineItems() (debounced via
			 * liveRefetchTimer) rather than patching from any payload.
			 */
			liveHandles: [],
			liveTypesKey: '',
			liveEpoch: 0,
			liveRefetchTimer: null,
			/**
			 * Map of leadId → LeadProduct[] for the visible pipeline. Populated
			 * after fetchPipelineItems so stage breakdowns are aggregated client-side.
			 *
			 * @spec openspec/changes/lead-product-link/tasks.md#task-4.1
			 */
			leadProductsByLead: {},
			/**
			 * Map of productId → product name, populated alongside leadProductsByLead.
			 *
			 * @spec openspec/changes/lead-product-link/tasks.md#task-4.2
			 */
			productNamesById: {},
			/**
			 * Name of the stage whose product-breakdown popover is open, or null.
			 *
			 * @spec openspec/changes/lead-product-link/tasks.md#task-4.3
			 */
			expandedBreakdownStage: null,
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-16
		 */
		objectStore() {
			return useObjectStore()
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-23
		 */
		pipelines() {
			return this.objectStore.collections.pipeline || []
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-22
		 */
		pipelineSelectOptions() {
			return this.pipelines.map(p => ({
				value: p.id,
				label: p.title,
			}))
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-25
		 */
		selectedPipeline() {
			if (!this.selectedPipelineId) return null
			return this.pipelines.find(p => p.id === this.selectedPipelineId) || null
		},
		/**
		 * The registered object types the selected pipeline renders, used
		 * to scope the live collection subscriptions. Logical mapping
		 * slugs (request/complaint/contactmoment) resolve onto their
		 * registered supertype via resolveObjectType, mirroring
		 * fetchSchemaItems. Reactive on both the selected pipeline and
		 * the (async) type registration in initializeStores().
		 *
		 * @return {Array<string>} Sorted unique registered type slugs
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 */
		liveTypes() {
			const pipeline = this.selectedPipeline
			if (!pipeline) return []
			let slugs = []
			if (pipeline.propertyMappings && pipeline.propertyMappings.length > 0) {
				slugs = pipeline.propertyMappings.map(m => m.schemaSlug)
			} else if (pipeline.entityType === 'both') {
				slugs = ['lead', 'request']
			} else if (pipeline.entityType) {
				slugs = [pipeline.entityType]
			}
			const types = new Set()
			for (const slug of slugs) {
				const { objectType } = resolveObjectType(slug)
				if (this.objectStore.objectTypeRegistry[objectType]) {
					types.add(objectType)
				}
			}
			return [...types].sort()
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-24
		 */
		propertyMappings() {
			return this.selectedPipeline?.propertyMappings || []
		},
		hasMultipleSchemas() {
			return this.propertyMappings.length > 1
		},
		hasTotals() {
			return this.propertyMappings.some(m => m.totalsProperty)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-27
		 */
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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-29
		 */
		sortedStages() {
			if (!this.selectedPipeline?.stages) return []
			return [...this.selectedPipeline.stages].sort((a, b) => a.order - b.order)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-21
		 */
		openStages() {
			return this.sortedStages.filter(s => !s.isClosed)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-3
		 */
		closedStages() {
			return this.sortedStages.filter(s => s.isClosed)
		},
		/**
		 * Schema-filtered merged array of all pipeline items; no search applied.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-1
		 */
		allItems() {
			let result = this.items
			const filter = this.showFilter?.id || this.showFilter || 'all'
			if (filter !== 'all') {
				result = result.filter(i => i._schemaSlug === filter)
			}
			return result
		},
		/**
		 * allItems filtered by searchQuery (case-insensitive title match).
		 * Empty searchQuery passes all items through unchanged.
		 *
		 * @spec openspec/changes/2026-03-20-pipeline/tasks.md#task-1.2
		 */
		filteredItems() {
			if (!this.searchQuery.trim()) return this.allItems
			const query = this.searchQuery.trim().toLowerCase()
			return this.allItems.filter(i => (i.title || '').toLowerCase().includes(query))
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-28
		 */
		sortedListItems() {
			const items = [...this.filteredItems]
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
					valA = a.expectedCloseDate || a.occurredAt || ''
					valB = b.expectedCloseDate || b.occurredAt || ''
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
		/**
		 * @param {object|null} val The newly selected pipeline object
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-25
		 */
		selectedPipeline(val) {
			this.syncSidebarState(val)
		},
		/**
		 * Re-scope the live collection subscriptions when the selected
		 * pipeline (or the async type registration) changes.
		 *
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 */
		liveTypes() {
			this.syncLiveSubscriptions()
		},
		/**
		 * Live event hint received on the store (or-collection event →
		 * liveUpdatesPlugin) — refresh the board through the existing
		 * fetch path, debounced, never patched from a payload.
		 *
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 */
		'objectStore.liveLastEventAt'() {
			this.onLiveEvent()
		},
	},
	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-15
	 */
	async mounted() {
		if (this.pipelineSidebarState) {
			this.pipelineSidebarState.active = true
			this.pipelineSidebarState.onSave = this.onSidebarSave
		}

		this.loading = true

		// Ensure object types are registered (by slug) before fetching. Shared,
		// memoised bootstrap — registers every type the app uses.
		await initializeStores()

		await this.objectStore.fetchCollection('pipeline', { _limit: 100 })

		if (this.pipelines.length > 0) {
			const defaultPipeline = this.pipelines.find(p => p.isDefault) || this.pipelines[0]
			this.selectedPipelineId = defaultPipeline.id
			await this.fetchPipelineItems()
		}
		this.loading = false
		this.syncLiveSubscriptions()
	},
	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-2
	 */
	beforeUnmount() {
		clearTimeout(this.liveRefetchTimer)
		this.releaseLiveSubscriptions()
		if (this.pipelineSidebarState) {
			this.pipelineSidebarState.active = false
			this.pipelineSidebarState.pipeline = null
			this.pipelineSidebarState.onSave = null
		}
	},
	methods: {
		getPriorityLabel,
		getPriorityColor,

		/**
		 * Subscribe to live updates for every object type the selected
		 * pipeline renders (or-collection-{register}-{schema} via
		 * notify_push, visibility-gated polling fallback). Idempotent per
		 * scope; releases the previous subscriptions when the pipeline
		 * changes. Guarded with a pending-scope marker (liveTypesKey set
		 * before the awaits) plus an epoch counter so a release during an
		 * in-flight subscribe drops the stale handles instead of leaking
		 * them.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 */
		async syncLiveSubscriptions() {
			const store = this.objectStore
			if (typeof store.subscribe !== 'function') {
				return
			}
			const types = this.liveTypes
			const key = types.join(',')
			if (key === this.liveTypesKey) {
				// Same scope already subscribed (or subscribe in flight).
				return
			}
			this.releaseLiveSubscriptions()
			if (types.length === 0) {
				return
			}
			this.liveTypesKey = key
			const epoch = this.liveEpoch
			const handles = []
			try {
				for (const type of types) {
					handles.push(await store.subscribe(type))
				}
			} catch (e) {
				console.warn('[PipelineBoard] live subscription failed:', e?.message ?? e)
			}
			if (this.liveEpoch !== epoch) {
				// Released while awaiting (pipeline switch / destroy) — drop
				// the now-stale subscriptions instead of leaking them.
				handles.forEach((h) => store.unsubscribe(h))
				return
			}
			this.liveHandles = handles
			if (handles.length === 0) {
				this.liveTypesKey = ''
			}
		},

		/**
		 * Release the current live subscriptions, if any, and invalidate
		 * any in-flight subscribe (its resolution unsubscribes itself via
		 * the epoch check).
		 *
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 */
		releaseLiveSubscriptions() {
			this.liveEpoch += 1
			this.liveTypesKey = ''
			if (typeof this.objectStore.unsubscribe === 'function') {
				this.liveHandles.forEach((h) => this.objectStore.unsubscribe(h))
			}
			this.liveHandles = []
		},

		/**
		 * Debounced refetch on a live event hint. The board keeps its own
		 * filtered item set (pipeline + ticketType filters), so re-run the
		 * existing fetchPipelineItems() path instead of reading the
		 * store's generic collection refetch. Skipped while a fetch is
		 * already in flight.
		 *
		 * @spec openspec/specs/realtime-updates-ui/spec.md
		 */
		onLiveEvent() {
			if (this.liveHandles.length === 0) {
				return
			}
			clearTimeout(this.liveRefetchTimer)
			this.liveRefetchTimer = setTimeout(() => {
				if (this.loading) {
					return
				}
				this.fetchPipelineItems()
			}, 500)
		},

		/**
		 * @param {object|null} pipeline The pipeline to mirror into the sidebar state
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-30
		 */
		syncSidebarState(pipeline) {
			if (this.pipelineSidebarState) {
				this.pipelineSidebarState.pipeline = pipeline
			}
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-32
		 */
		toggleSidebar() {
			if (this.pipelineSidebarState) {
				this.pipelineSidebarState.open = !this.pipelineSidebarState.open
			}
		},

		/**
		 * @param {object} pipelineData The edited pipeline payload to save
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-19
		 */
		async onSidebarSave(pipelineData) {
			await this.objectStore.saveObject('pipeline', pipelineData)
			await this.objectStore.fetchCollection('pipeline', { _limit: 100 })
			this.syncSidebarState(this.selectedPipeline)
			await this.fetchPipelineItems()
		},

		getMappingForItem(item) {
			return this.propertyMappings.find(m => m.schemaSlug === item._schemaSlug) || null
		},

		/**
		 * @param {object} item The board item
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-10
		 */
		getColumnProperty(item) {
			const mapping = this.getMappingForItem(item)
			return mapping?.columnProperty || 'stage'
		},

		getItemColumnValue(item) {
			return item[this.getColumnProperty(item)] || ''
		},

		/**
		 * @param {object} item The board item
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-11
		 */
		getItemTotalsValue(item) {
			const mapping = this.getMappingForItem(item)
			if (!mapping?.totalsProperty) return null
			const val = item[mapping.totalsProperty]
			return val !== undefined && val !== null ? val : null
		},

		/**
		 * Returns items in the given stage, filtered by searchQuery via filteredItems.
		 * Empty columns remain visible even when search is active.
		 *
		 * @param {string} stageName The stage (column) name
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-12
		 * @spec openspec/changes/2026-03-20-pipeline/tasks.md#task-1.2
		 */
		getStageItems(stageName) {
			return this.filteredItems
				.filter(item => {
					const colValue = this.getItemColumnValue(item)
					if (colValue === stageName) return true
					if (!colValue && this.openStages.length > 0 && this.openStages[0].name === stageName) return true
					return false
				})
				.sort((a, b) => (a.stageOrder || 0) - (b.stageOrder || 0))
		},

		/**
		 * @param {string} stageName The stage (column) name
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-13
		 */
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

		/**
		 * Fetches items for the selected pipeline and resets the search query.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-18
		 * @spec openspec/changes/2026-03-20-pipeline/tasks.md#task-1.1
		 */
		async onPipelineChange() {
			this.searchQuery = ''
			await this.fetchPipelineItems()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-7
		 */
		async fetchPipelineItems() {
			if (!this.selectedPipelineId) return
			this.loading = true

			const pipeline = this.selectedPipeline
			if (pipeline?.propertyMappings && pipeline.propertyMappings.length > 0) {
				await this.fetchItemsViaMappings(pipeline)
			} else {
				await this.fetchItemsLegacy(pipeline)
			}

			await this.fetchLeadProductsForStages()

			this.loading = false
		},

		/**
		 * Batch-fetch all LeadProduct objects for leads in the visible pipeline so
		 * the per-stage product-value breakdown can be computed client-side.
		 *
		 * @spec openspec/changes/lead-product-link/tasks.md#task-4.1
		 */
		async fetchLeadProductsForStages() {
			const leadIds = new Set(this.items
				.filter(i => i._schemaSlug === 'lead')
				.map(i => i.id)
				.filter(Boolean))

			if (leadIds.size === 0) {
				this.leadProductsByLead = {}
				this.productNamesById = {}
				return
			}

			try {
				// Bulk fetch — filter client-side by leadId so we only emit one
				// network call per board load. Backend `findAll` parameter for
				// arrays is not contractually stable across OR versions.
				const lpCollection = await this.objectStore.fetchCollection('leadProduct', {
					_limit: 500,
				})
				const byLead = {}
				const productIds = new Set()
				for (const lp of (lpCollection || [])) {
					if (!lp.lead || !leadIds.has(lp.lead)) continue
					if (!byLead[lp.lead]) byLead[lp.lead] = []
					byLead[lp.lead].push(lp)
					if (lp.product) productIds.add(lp.product)
				}
				this.leadProductsByLead = byLead

				if (productIds.size > 0) {
					const products = await this.objectStore.fetchCollection('product', {
						_limit: 500,
					})
					const names = {}
					for (const p of (products || [])) {
						if (productIds.has(p.id)) {
							names[p.id] = p.name || p.id
						}
					}
					this.productNamesById = names
				} else {
					this.productNamesById = {}
				}
			} catch {
				this.leadProductsByLead = {}
				this.productNamesById = {}
			}
		},

		/**
		 * Aggregates LeadProduct objects in the given stage by product UUID,
		 * returning the top 5 by aggregate value plus a "remaining" count.
		 *
		 * @param {string} stageName Stage to aggregate.
		 * @return {{items: Array<{product: string, name: string, count: number, total: number}>, remaining: number}} Top-5 entries plus remaining count.
		 * @spec openspec/changes/lead-product-link/tasks.md#task-4.2
		 */
		getStageBreakdown(stageName) {
			const stageLeads = this.getStageItems(stageName).filter(i => i._schemaSlug === 'lead')
			const aggregate = new Map()
			for (const lead of stageLeads) {
				const lineItems = this.leadProductsByLead[lead.id] || []
				for (const lp of lineItems) {
					if (!lp.product) continue
					const current = aggregate.get(lp.product) || {
						product: lp.product,
						name: this.productNamesById[lp.product] || lp.product,
						count: 0,
						total: 0,
					}
					current.count += 1
					current.total += Number(lp.total) || 0
					aggregate.set(lp.product, current)
				}
			}
			const sorted = Array.from(aggregate.values()).sort((a, b) => b.total - a.total)
			const top = sorted.slice(0, 5)
			const remaining = Math.max(0, sorted.length - top.length)
			return { items: top, remaining }
		},

		/**
		 * Toggles the breakdown popover for the given stage.
		 *
		 * @param {string} stageName Stage whose breakdown to show or hide.
		 * @spec openspec/changes/lead-product-link/tasks.md#task-4.3
		 */
		toggleStageBreakdown(stageName) {
			this.expandedBreakdownStage = this.expandedBreakdownStage === stageName ? null : stageName
		},

		/**
		 * @param {object} pipeline The pipeline whose propertyMappings drive the fetch
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-6
		 */
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

		/**
		 * @param {object|null} pipeline The pipeline whose legacy entityType drives the fetch
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-5
		 */
		async fetchItemsLegacy(pipeline) {
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

		/**
		 * Fetch the pipeline's items for one logical schema slug.
		 *
		 * `schemaSlug` is a *logical* type — it comes from a pipeline's stored
		 * propertyMappings, so it can still read `request` / `complaint` /
		 * `contactmoment`. Those three now live in the `ticket` supertype, so the
		 * slug is resolved to its registered object type plus a `ticketType`
		 * filter (unify-ticket-supertype). Items keep the logical slug in
		 * `_schemaSlug` so mappings, badges, filters and routing still line up.
		 *
		 * @param {string} schemaSlug The logical schema slug from the pipeline's propertyMappings
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-8
		 */
		async fetchSchemaItems(schemaSlug) {
			const { objectType, ticketType } = resolveObjectType(schemaSlug)
			const config = this.objectStore.objectTypeRegistry[objectType]
			if (!config) return []

			try {
				const ticketFilter = ticketType ? `ticketType=${ticketType}&` : ''
				const url = `/apps/openregister/api/objects/${config.register}/${config.schema}?${ticketFilter}pipeline=${this.selectedPipelineId}&_limit=200`
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

		/**
		 * @param {DragEvent} event The drop event carrying the dragged item JSON
		 * @param {object} targetStage The stage the item was dropped on
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-17
		 */
		async onDrop(event, targetStage) {
			try {
				const data = JSON.parse(event.dataTransfer.getData('application/json'))
				const mapping = this.propertyMappings.find(m => m.schemaSlug === data._schemaSlug)
				const columnProp = mapping?.columnProperty || 'stage'

				if (data[columnProp] === targetStage.name) return

				const update = { id: data.id }
				update[columnProp] = targetStage.name
				update.stageOrder = targetStage.order

				// Resolve the logical slug onto its registered object type; a
				// request/complaint/contactmoment writes to `ticket` and must carry
				// its ticketType discriminator (unify-ticket-supertype).
				const { objectType, ticketType } = resolveObjectType(data._schemaSlug)
				if (ticketType) update.ticketType = ticketType

				await this.objectStore.saveObject(objectType, update)
				await this.fetchPipelineItems()
			} catch {
				// Invalid drop
			}
		},

		/**
		 * @param {string} stageName The closed stage to expand or collapse
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-31
		 */
		toggleClosedStage(stageName) {
			this.expandedClosed = this.expandedClosed === stageName ? null : stageName
		},

		/**
		 * @param {string} column The list-view column key to sort by
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-33
		 */
		toggleSort(column) {
			if (this.sortBy === column) {
				this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'
			} else {
				this.sortBy = column
				this.sortDir = 'asc'
			}
		},

		/**
		 * @param {object} item The board item
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-14
		 */
		isItemOverdue(item) {
			const dateStr = item.expectedCloseDate || item.occurredAt
			if (!dateStr) return false
			return new Date(dateStr) < new Date()
		},

		/**
		 * @param {string|null} dateStr The ISO date string to format
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-9
		 */
		formatDate(dateStr) {
			if (!dateStr) return '—'
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

		/**
		 * @param {object} item The board item to open
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-20
		 */
		openItem(item) {
			if (item._schemaSlug === 'lead') {
				this.$router.push({ name: 'LeadDetail', params: { id: item.id } })
			} else if (item._schemaSlug === 'request') {
				// `_schemaSlug` keeps the LOGICAL slug ('request'), but the row is
				// stored as a `ticket` (unify-ticket-supertype) and opens on the
				// unified TicketDetail page.
				this.$router.push({ name: 'TicketDetail', params: { id: item.id } })
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

.column-value-wrapper {
	position: relative;
	margin-top: 2px;
}

.column-value--clickable {
	background: none;
	border: none;
	padding: 0;
	cursor: pointer;
	font: inherit;
	color: inherit;
	text-align: left;
	width: 100%;
}

.column-value--clickable:hover,
.column-value--clickable:focus-visible {
	color: var(--color-primary);
	outline: none;
	text-decoration: underline;
}

.stage-breakdown {
	position: absolute;
	top: calc(100% + 6px);
	left: 0;
	right: 0;
	z-index: 20;
	min-width: 220px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 6px 18px var(--color-box-shadow, rgba(0, 0, 0, 0.18));
	padding: 8px 10px;
}

.stage-breakdown__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding-bottom: 6px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 6px;
}

.stage-breakdown__title {
	font-size: 12px;
	font-weight: 700;
	color: var(--color-text-maxcontrast);
}

.stage-breakdown__close {
	background: none;
	border: none;
	font-size: 16px;
	line-height: 1;
	cursor: pointer;
	padding: 0 4px;
	color: var(--color-text-maxcontrast);
}

.stage-breakdown__empty {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 4px 0;
}

.stage-breakdown__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.stage-breakdown__row {
	display: grid;
	grid-template-columns: 1fr auto auto;
	gap: 6px;
	align-items: baseline;
	font-size: 12px;
	padding: 3px 0;
}

.stage-breakdown__name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.stage-breakdown__count {
	color: var(--color-text-maxcontrast);
}

.stage-breakdown__total {
	font-weight: 600;
}

.stage-breakdown__more {
	margin-top: 4px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.kanban-column__body {
	padding: 4px;
	display: flex;
	flex-direction: column;
	gap: 1px;
	overflow-y: auto;
	flex: 1;
}

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

@media (prefers-reduced-motion: reduce) {
	.kanban-closed-column,
	.list-row {
		transition: none;
	}
}
</style>
