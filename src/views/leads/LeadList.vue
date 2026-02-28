<template>
	<CnIndexPage
		:title="t('pipelinq', 'Leads')"
		:description="t('pipelinq', 'Track and manage sales leads')"
		:schema="schema"
		:objects="leads"
		:pagination="pagination"
		:loading="loading"
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:selectable="true"
		:include-columns="visibleColumns"
		@add="createNew"
		@refresh="fetchLeads"
		@sort="onSort"
		@row-click="openLead"
		@page-changed="loadPage">
		<template #column-value="{ value }">
			{{ formatValue(value) }}
		</template>

		<template #column-priority="{ value }">
			<span v-if="value && value !== 'normal'" class="priority-badge" :class="'priority-' + value">
				{{ value }}
			</span>
			<span v-else>{{ value || '-' }}</span>
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'LeadList',
	components: {
		CnIndexPage,
	},

	inject: {
		sidebarState: { default: null },
	},

	data() {
		return {
			searchTerm: '',
			searchTimeout: null,
			sortKey: null,
			sortOrder: 'asc',
			schema: null,
			visibleColumns: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		leads() {
			return this.objectStore.collections.lead || []
		},
		loading() {
			return this.objectStore.loading.lead || false
		},
		pagination() {
			return this.objectStore.pagination.lead || { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	async mounted() {
		this.schema = await this.objectStore.fetchSchema('lead')
		this.setupSidebar()
		this.fetchLeads()
	},
	beforeDestroy() {
		this.teardownSidebar()
	},
	methods: {
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
		async fetchLeads(page = 1) {
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
			await this.objectStore.fetchCollection('lead', params)
			if (this.sidebarState) {
				this.sidebarState.facetData = this.objectStore.facets.lead || {}
			}
		},
		openLead(row) {
			this.$router.push({ name: 'LeadDetail', params: { id: row.id } })
		},
		createNew() {
			this.$router.push({ name: 'LeadDetail', params: { id: 'new' } })
		},
		onSearch(value) {
			this.searchTerm = value
			if (this.sidebarState) {
				this.sidebarState.searchValue = value
			}
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.fetchLeads()
			}, 300)
		},
		onSort({ key, order }) {
			this.sortKey = key
			this.sortOrder = order
			this.fetchLeads()
		},
		onFacetFilterChange(key, values) {
			if (!this.sidebarState) return
			this.sidebarState.activeFilters = {
				...this.sidebarState.activeFilters,
				[key]: values && values.length > 0 ? values : undefined,
			}
			this.fetchLeads()
		},
		loadPage(page) {
			this.fetchLeads(page)
		},
		formatValue(value) {
			if (value === null || value === undefined) return '-'
			return 'EUR ' + Number(value).toLocaleString('nl-NL')
		},
	},
}
</script>

<style scoped>
.priority-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: bold;
	text-transform: capitalize;
}

.priority-urgent {
	background: var(--color-error);
	color: white;
}

.priority-high {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.priority-low {
	color: var(--color-text-maxcontrast);
}
</style>
