<template>
	<CnIndexPage
		:title="t('pipelinq', 'Contacts')"
		:description="t('pipelinq', 'Manage your contacts')"
		:schema="schema"
		:objects="contacts"
		:pagination="pagination"
		:loading="loading"
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:selectable="true"
		:include-columns="visibleColumns"
		@add="createNew"
		@refresh="fetchContacts"
		@sort="onSort"
		@row-click="openContact"
		@page-changed="loadPage">
		<template #column-client="{ row }">
			<a
				class="client-link"
				@click.stop="navigateToClient(row.client)">
				{{ getClientName(row.client) }}
			</a>
		</template>
	</CnIndexPage>
</template>

<script>
import { CnIndexPage } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ContactList',
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
			clientNames: {},
			schema: null,
			visibleColumns: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		contacts() {
			return this.objectStore.collections.contact || []
		},
		loading() {
			return this.objectStore.loading.contact || false
		},
		pagination() {
			return this.objectStore.pagination.contact || { total: 0, page: 1, pages: 1, limit: 20 }
		},
	},
	async mounted() {
		this.schema = await this.objectStore.fetchSchema('contact')
		this.setupSidebar()
		await this.fetchContacts()
		this.loadClientNames()
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
		async fetchContacts(page = 1) {
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
			await this.objectStore.fetchCollection('contact', params)
			if (this.sidebarState) {
				this.sidebarState.facetData = this.objectStore.facets.contact || {}
			}
			this.loadClientNames()
		},
		onFilterChange(key, values) {
			if (!this.sidebarState) return
			this.sidebarState.activeFilters = {
				...this.sidebarState.activeFilters,
				[key]: values && values.length > 0 ? values : undefined,
			}
			this.fetchContacts()
		},
		async loadClientNames() {
			const clientIds = this.contacts
				.map(c => c.client)
				.filter(Boolean)
			const uniqueIds = [...new Set(clientIds)]
			if (uniqueIds.length === 0) return
			const resolved = await this.objectStore.resolveReferences('client', uniqueIds)
			for (const id of uniqueIds) {
				this.$set(this.clientNames, id, resolved[id]?.name || t('pipelinq', '[Deleted]'))
			}
		},
		getClientName(clientId) {
			if (!clientId) return '-'
			return this.clientNames[clientId] || t('pipelinq', '[Loading...]')
		},
		openContact(row) {
			this.$router.push({ name: 'ContactDetail', params: { id: row.id } })
		},
		navigateToClient(clientId) {
			if (clientId) {
				this.$router.push({ name: 'ClientDetail', params: { id: clientId } })
			}
		},
		createNew() {
			this.$router.push({ name: 'ContactDetail', params: { id: 'new' } })
		},
		onSearch(value) {
			this.searchTerm = value
			if (this.sidebarState) {
				this.sidebarState.searchValue = value
			}
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.fetchContacts()
			}, 300)
		},
		onSort({ key, order }) {
			this.sortKey = key
			this.sortOrder = order
			this.fetchContacts()
		},
		loadPage(page) {
			this.fetchContacts(page)
		},
	},
}
</script>

<style scoped>
.client-link {
	color: var(--color-primary);
	cursor: pointer;
	text-decoration: underline;
}

.client-link:hover {
	color: var(--color-primary-hover);
}
</style>
