<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Clients')"
			:description="t('pipelinq', 'Manage your client relationships')"
			:schema="schema"
			:objects="clients"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:selectable="true"
			:include-columns="visibleColumns"
			@add="createNew"
			@refresh="fetchClients"
			@sort="onSort"
			@row-click="openClient"
			@page-changed="loadPage" />
	</div>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ClientList',
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
		clients() {
			return this.objectStore.collections.client || []
		},
		loading() {
			return this.objectStore.loading.client || false
		},
		pagination() {
			return this.objectStore.pagination.client || { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	async mounted() {
		this.schema = await this.objectStore.fetchSchema('client')
		this.setupSidebar()
		this.fetchClients()
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
				this.onFilterChange(key, values)
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
		async fetchClients(page = 1) {
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
			await this.objectStore.fetchCollection('client', params)
			if (this.sidebarState) {
				this.sidebarState.facetData = this.objectStore.facets.client || {}
			}
		},
		onFilterChange(key, values) {
			if (!this.sidebarState) return
			this.sidebarState.activeFilters = {
				...this.sidebarState.activeFilters,
				[key]: values && values.length > 0 ? values : undefined,
			}
			this.fetchClients()
		},
		openClient(row) {
			this.$router.push({ name: 'ClientDetail', params: { id: row.id } })
		},
		createNew() {
			this.$router.push({ name: 'ClientDetail', params: { id: 'new' } })
		},
		onSearch(value) {
			this.searchTerm = value
			if (this.sidebarState) {
				this.sidebarState.searchValue = value
			}
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.fetchClients()
			}, 300)
		},
		onSort({ key, order }) {
			this.sortKey = key
			this.sortOrder = order
			this.fetchClients()
		},
		loadPage(page) {
			this.fetchClients(page)
		},
	},
}
</script>
