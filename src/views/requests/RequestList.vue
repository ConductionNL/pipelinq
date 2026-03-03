<template>
	<CnIndexPage
		:title="t('pipelinq', 'Requests')"
		:description="t('pipelinq', 'Handle incoming requests')"
		:schema="schema"
		:objects="requests"
		:pagination="pagination"
		:loading="loading"
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:selectable="true"
		:include-columns="visibleColumns"
		@add="createNew"
		@refresh="fetchRequests"
		@sort="onSort"
		@row-click="openRequest"
		@page-changed="loadPage">

		<template #column-status="{ row }">
			<div class="status-cell" @click.stop>
				<span
					class="status-badge"
					:style="{ background: getStatusColor(row.status), color: '#fff' }">
					{{ getStatusLabel(row.status) }}
				</span>
				<NcSelect
					v-if="getAllowedTransitions(row.status).length > 0"
					:value="null"
					:options="getTransitionOptions(row.status)"
					:placeholder="'\u2192'"
					:clearable="false"
					class="status-quick-change"
					@input="v => quickStatusChange(row, v)" />
			</div>
		</template>

		<template #column-priority="{ row }">
			<span
				class="priority-text"
				:style="{ color: getPriorityColor(row.priority) }">
				{{ getPriorityLabel(row.priority) }}
			</span>
		</template>

		<template #column-requestedAt="{ value }">
			{{ formatDate(value) }}
		</template>
	</CnIndexPage>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import {
	getAllowedTransitions,
	getStatusLabel,
	getStatusColor,
	getPriorityLabel,
	getPriorityColor,
} from '../../services/requestStatus.js'

export default {
	name: 'RequestList',
	components: {
		NcSelect,
		CnIndexPage,
	},

	inject: {
		sidebarState: { default: null },
	},

	data() {
		return {
			searchTerm: '',
			searchTimeout: null,
			sortKey: 'requestedAt',
			sortOrder: 'desc',
			schema: null,
			visibleColumns: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		requests() {
			return this.objectStore.collections.request || []
		},
		loading() {
			return this.objectStore.loading.request || false
		},
		pagination() {
			return this.objectStore.pagination.request || { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	async mounted() {
		this.schema = await this.objectStore.fetchSchema('request')
		this.setupSidebar()
		this.fetchRequests()
	},
	beforeDestroy() {
		this.teardownSidebar()
	},
	methods: {
		getAllowedTransitions,
		getStatusLabel,
		getStatusColor,
		getPriorityLabel,
		getPriorityColor,

		setupSidebar() {
			if (!this.sidebarState) return
			this.sidebarState.active = true
			this.sidebarState.schema = this.schema
			this.sidebarState.searchValue = this.searchTerm
			this.sidebarState.activeFilters = {}
			this.sidebarState.onSearch = (value) => {
				this.onSearch(value)
			}
			this.sidebarState.onColumnsChange = (columns) => {
				this.visibleColumns = columns
			}
			this.sidebarState.onFilterChange = ({ key, values }) => {
				this.onFacetFilterChange(key, values)
			}
		},
		teardownSidebar() {
			if (!this.sidebarState) return
			this.sidebarState.active = false
			this.sidebarState.schema = null
			this.sidebarState.activeFilters = {}
			this.sidebarState.facetData = {}
			this.sidebarState.onSearch = null
			this.sidebarState.onColumnsChange = null
			this.sidebarState.onFilterChange = null
		},

		getTransitionOptions(currentStatus) {
			return getAllowedTransitions(currentStatus).map(s => ({
				id: s,
				label: getStatusLabel(s),
			}))
		},

		async quickStatusChange(request, option) {
			if (!option) return
			const newStatus = option.id || option
			await this.objectStore.saveObject('request', {
				...request,
				status: newStatus,
			})
			this.fetchRequests()
		},

		async fetchRequests(page = 1) {
			const params = {
				_limit: 20,
				_page: page,
			}
			if (this.searchTerm) {
				params._search = this.searchTerm
			}
			if (this.sortKey) {
				params._order = { [this.sortKey]: this.sortOrder }
			}
			if (this.sidebarState?.activeFilters) {
				for (const [key, values] of Object.entries(this.sidebarState.activeFilters)) {
					if (values && values.length > 0) {
						params[key] = values.length === 1 ? values[0] : values
					}
				}
			}
			await this.objectStore.fetchCollection('request', params)
			if (this.sidebarState) {
				this.sidebarState.facetData = this.objectStore.facets.request || {}
			}
		},

		openRequest(row) {
			this.$router.push({ name: 'RequestDetail', params: { id: row.id } })
		},
		createNew() {
			this.$router.push({ name: 'RequestDetail', params: { id: 'new' } })
		},
		onSearch(value) {
			this.searchTerm = value
			if (this.sidebarState) {
				this.sidebarState.searchValue = value
			}
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.fetchRequests()
			}, 300)
		},
		onSort({ key, order }) {
			this.sortKey = key
			this.sortOrder = order
			this.fetchRequests()
		},
		onFacetFilterChange(key, values) {
			if (!this.sidebarState) return
			this.sidebarState.activeFilters = {
				...this.sidebarState.activeFilters,
				[key]: values && values.length > 0 ? values : undefined,
			}
			this.fetchRequests()
		},
		loadPage(page) {
			this.fetchRequests(page)
		},
		formatDate(dateStr) {
			if (!dateStr) return '-'
			try {
				return new Date(dateStr).toLocaleDateString()
			} catch {
				return dateStr
			}
		},
	},
}
</script>

<style scoped>
.status-cell {
	display: flex;
	align-items: center;
	gap: 6px;
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.status-quick-change {
	width: 44px;
	min-width: 44px;
}

.priority-text {
	font-weight: 600;
	font-size: 13px;
}
</style>
