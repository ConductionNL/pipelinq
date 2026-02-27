<template>
	<div class="client-list">
		<div class="client-list__header">
			<h2>{{ t('pipelinq', 'Clients') }}</h2>
			<div class="client-list__header-actions">
				<NcButton @click="showImportDialog = true">
					{{ t('pipelinq', 'Import from Contacts') }}
				</NcButton>
				<NcButton type="primary" @click="createNew">
					{{ t('pipelinq', 'New client') }}
				</NcButton>
			</div>
		</div>

		<ContactImportDialog
			v-if="showImportDialog"
			import-type="client"
			@imported="onImported"
			@close="showImportDialog = false" />

		<div class="client-list__filters">
			<NcTextField
				:value="searchTerm"
				:label="t('pipelinq', 'Search')"
				:show-trailing-button="searchTerm !== ''"
				class="client-list__search"
				@update:value="onSearch"
				@trailing-button-click="clearSearch" />
			<NcSelect
				v-model="typeFilter"
				:options="typeFilterOptions"
				:placeholder="t('pipelinq', 'All types')"
				:clearable="true"
				class="client-list__type-filter"
				@input="onTypeFilterChange" />
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcNoteCard v-if="error" type="error" class="client-list__error">
			{{ error.message }}
			<NcButton type="secondary" @click="fetchClients()">
				{{ t('pipelinq', 'Retry') }}
			</NcButton>
		</NcNoteCard>

		<div v-else-if="clients.length === 0" class="client-list__empty">
			<NcEmptyContent
				:name="searchTerm || typeFilter ? t('pipelinq', 'No clients match your filters') : t('pipelinq', 'No clients yet')">
				<template #action>
					<NcButton v-if="!searchTerm && !typeFilter" type="primary" @click="createNew">
						{{ t('pipelinq', 'Create your first client') }}
					</NcButton>
					<NcButton v-else @click="clearAllFilters">
						{{ t('pipelinq', 'Clear filters') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<div v-else class="viewTableContainer">
			<table class="viewTable">
			<thead>
				<tr>
					<th class="client-list__sortable" @click="toggleSort">
						{{ t('pipelinq', 'Name') }}
						<span v-if="sortDirection === 'asc'" class="sort-icon">&#9650;</span>
						<span v-else-if="sortDirection === 'desc'" class="sort-icon">&#9660;</span>
					</th>
					<th>{{ t('pipelinq', 'Type') }}</th>
					<th>{{ t('pipelinq', 'Email') }}</th>
					<th>{{ t('pipelinq', 'Phone') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="client in clients"
					:key="client.id"
					class="viewTableRow"
					@click="openClient(client.id)">
					<td>{{ client.name || '-' }}</td>
					<td>{{ client.type || '-' }}</td>
					<td>{{ client.email || '-' }}</td>
					<td>{{ client.phone || '-' }}</td>
				</tr>
			</tbody>
		</table>
	</div>

		<div v-if="pagination.pages > 1" class="client-list__pagination">
			<NcButton
				:disabled="pagination.page <= 1"
				@click="loadPage(pagination.page - 1)">
				{{ t('pipelinq', 'Previous') }}
			</NcButton>
			<span>{{ t('pipelinq', 'Page {page} of {pages} ({total} total)', { page: pagination.page, pages: pagination.pages, total: pagination.total }) }}</span>
			<NcButton
				:disabled="pagination.page >= pagination.pages"
				@click="loadPage(pagination.page + 1)">
				{{ t('pipelinq', 'Next') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField, NcSelect, NcEmptyContent } from '@nextcloud/vue'
import ContactImportDialog from '../../components/ContactImportDialog.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ClientList',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		NcSelect,
		NcEmptyContent,
		ContactImportDialog,
	},
	data() {
		return {
			searchTerm: '',
			searchTimeout: null,
			typeFilter: null,
			typeFilterOptions: ['person', 'organization'],
			sortDirection: null,
			showImportDialog: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		clients() {
			return this.objectStore.getCollection('client')
		},
		loading() {
			return this.objectStore.isLoading('client')
		},
		pagination() {
			return this.objectStore.getPagination('client')
		},
		error() {
			return this.objectStore.getError('client')
		},
	},
	mounted() {
		this.fetchClients()
	},
	methods: {
		async fetchClients(params = {}) {
			const fetchParams = {
				_limit: 20,
				_offset: 0,
				...params,
			}
			if (this.searchTerm) {
				fetchParams._search = this.searchTerm
			}
			if (this.typeFilter) {
				fetchParams.type = this.typeFilter
			}
			if (this.sortDirection) {
				fetchParams._order = { name: this.sortDirection }
			}
			await this.objectStore.fetchCollection('client', fetchParams)
		},
		openClient(id) {
			this.$emit('navigate', 'client-detail', id)
		},
		createNew() {
			this.$emit('navigate', 'client-detail', 'new')
		},
		onSearch(value) {
			this.searchTerm = value
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.fetchClients()
			}, 300)
		},
		clearSearch() {
			this.searchTerm = ''
			this.fetchClients()
		},
		onTypeFilterChange() {
			this.fetchClients()
		},
		clearAllFilters() {
			this.searchTerm = ''
			this.typeFilter = null
			this.sortDirection = null
			this.fetchClients()
		},
		toggleSort() {
			if (!this.sortDirection) {
				this.sortDirection = 'asc'
			} else if (this.sortDirection === 'asc') {
				this.sortDirection = 'desc'
			} else {
				this.sortDirection = null
			}
			this.fetchClients()
		},
		loadPage(page) {
			const offset = (page - 1) * this.pagination.limit
			this.fetchClients({ _offset: offset })
		},
		onImported() {
			this.showImportDialog = false
			this.fetchClients()
		},
	},
}
</script>

<style scoped>
.client-list {
	padding: 20px;
}

.client-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.client-list__header-actions {
	display: flex;
	gap: 8px;
}

.client-list__filters {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
	align-items: flex-start;
}

.client-list__search {
	max-width: 300px;
	flex: 1;
}

.client-list__type-filter {
	min-width: 180px;
}

.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
	background-color: var(--color-main-background);
}

.viewTable th,
.viewTable td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.viewTable th.sortable {
	cursor: pointer;
	user-select: none;
}

.viewTable th.sortable:hover {
	color: var(--color-primary);
}

.sort-icon {
	font-size: 10px;
	margin-left: 4px;
}

.viewTableRow {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background: var(--color-background-hover);
}

.client-list__empty {
	padding: 40px;
	text-align: center;
}

.client-list__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-top: 16px;
}
</style>
