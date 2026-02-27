<template>
	<div class="lead-list">
		<div class="lead-list__header">
			<h2>{{ t('pipelinq', 'Leads') }}</h2>
			<NcButton type="primary" @click="createNew">
				{{ t('pipelinq', 'New lead') }}
			</NcButton>
		</div>

		<div class="lead-list__filters">
			<NcTextField
				:value="searchTerm"
				:label="t('pipelinq', 'Search')"
				:show-trailing-button="searchTerm !== ''"
				class="lead-list__search"
				@update:value="onSearch"
				@trailing-button-click="clearSearch" />
			<NcSelect
				v-model="stageFilter"
				:options="stageFilterOptions"
				:placeholder="t('pipelinq', 'All stages')"
				:clearable="true"
				class="lead-list__filter"
				@input="onFilterChange" />
			<NcSelect
				v-model="sourceFilter"
				:options="sourceFilterOptions"
				:placeholder="t('pipelinq', 'All sources')"
				:clearable="true"
				class="lead-list__filter"
				@input="onFilterChange" />
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcNoteCard v-if="error" type="error" class="lead-list__error">
			{{ error.message }}
			<NcButton type="secondary" @click="fetchLeads()">
				{{ t('pipelinq', 'Retry') }}
			</NcButton>
		</NcNoteCard>

		<div v-else-if="leads.length === 0" class="lead-list__empty">
			<NcEmptyContent
				:name="hasFilters ? t('pipelinq', 'No leads match your filters') : t('pipelinq', 'No leads yet')">
				<template #action>
					<NcButton v-if="!hasFilters" type="primary" @click="createNew">
						{{ t('pipelinq', 'Create first lead') }}
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
					<th>{{ t('pipelinq', 'Title') }}</th>
					<th class="lead-list__sortable" @click="toggleSort('value')">
						{{ t('pipelinq', 'Value') }}
						<span v-if="sortField === 'value' && sortDirection === 'asc'" class="sort-icon">&#9650;</span>
						<span v-else-if="sortField === 'value' && sortDirection === 'desc'" class="sort-icon">&#9660;</span>
					</th>
					<th>{{ t('pipelinq', 'Stage') }}</th>
					<th class="lead-list__sortable" @click="toggleSort('priority')">
						{{ t('pipelinq', 'Priority') }}
						<span v-if="sortField === 'priority' && sortDirection === 'asc'" class="sort-icon">&#9650;</span>
						<span v-else-if="sortField === 'priority' && sortDirection === 'desc'" class="sort-icon">&#9660;</span>
					</th>
					<th>{{ t('pipelinq', 'Source') }}</th>
					<th class="lead-list__sortable" @click="toggleSort('expectedCloseDate')">
						{{ t('pipelinq', 'Expected Close') }}
						<span v-if="sortField === 'expectedCloseDate' && sortDirection === 'asc'" class="sort-icon">&#9650;</span>
						<span v-else-if="sortField === 'expectedCloseDate' && sortDirection === 'desc'" class="sort-icon">&#9660;</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="lead in leads"
					:key="lead.id"
					class="viewTableRow"
					@click="openLead(lead.id)">
					<td>{{ lead.title || '-' }}</td>
					<td>{{ formatValue(lead.value) }}</td>
					<td>{{ lead.stage || '-' }}</td>
					<td>
						<span v-if="lead.priority && lead.priority !== 'normal'" class="priority-badge" :class="'priority-' + lead.priority">
							{{ lead.priority }}
						</span>
						<span v-else>{{ lead.priority || '-' }}</span>
					</td>
					<td>{{ lead.source || '-' }}</td>
					<td>{{ lead.expectedCloseDate || '-' }}</td>
				</tr>
			</tbody>
		</table>
	</div>

		<div v-if="pagination.pages > 1" class="lead-list__pagination">
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
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'LeadList',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},
	data() {
		return {
			searchTerm: '',
			searchTimeout: null,
			stageFilter: null,
			sourceFilter: null,
			sortField: null,
			sortDirection: null,
			sourceFilterOptions: [
				'website', 'email', 'phone', 'referral',
				'partner', 'campaign', 'social_media', 'event', 'other',
			],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		leads() {
			return this.objectStore.getCollection('lead')
		},
		loading() {
			return this.objectStore.isLoading('lead')
		},
		pagination() {
			return this.objectStore.getPagination('lead')
		},
		error() {
			return this.objectStore.getError('lead')
		},
		hasFilters() {
			return !!(this.searchTerm || this.stageFilter || this.sourceFilter)
		},
		stageFilterOptions() {
			const pipelines = this.objectStore.getCollection('pipeline') || []
			const stages = new Set()
			for (const pipeline of pipelines) {
				for (const stage of (pipeline.stages || [])) {
					stages.add(stage.name)
				}
			}
			return [...stages].sort()
		},
	},
	mounted() {
		this.objectStore.fetchCollection('pipeline', { _limit: 100 })
		this.fetchLeads()
	},
	methods: {
		async fetchLeads(params = {}) {
			const fetchParams = {
				_limit: 20,
				_offset: 0,
				...params,
			}
			if (this.searchTerm) {
				fetchParams._search = this.searchTerm
			}
			if (this.stageFilter) {
				fetchParams.stage = this.stageFilter
			}
			if (this.sourceFilter) {
				fetchParams.source = this.sourceFilter
			}
			if (this.sortField && this.sortDirection) {
				fetchParams._order = { [this.sortField]: this.sortDirection }
			}
			await this.objectStore.fetchCollection('lead', fetchParams)
		},
		openLead(id) {
			this.$emit('navigate', 'lead-detail', id)
		},
		createNew() {
			this.$emit('navigate', 'lead-detail', 'new')
		},
		onSearch(value) {
			this.searchTerm = value
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.fetchLeads()
			}, 300)
		},
		clearSearch() {
			this.searchTerm = ''
			this.fetchLeads()
		},
		onFilterChange() {
			this.fetchLeads()
		},
		clearAllFilters() {
			this.searchTerm = ''
			this.stageFilter = null
			this.sourceFilter = null
			this.sortField = null
			this.sortDirection = null
			this.fetchLeads()
		},
		toggleSort(field) {
			if (this.sortField !== field) {
				this.sortField = field
				this.sortDirection = 'asc'
			} else if (this.sortDirection === 'asc') {
				this.sortDirection = 'desc'
			} else {
				this.sortField = null
				this.sortDirection = null
			}
			this.fetchLeads()
		},
		loadPage(page) {
			const offset = (page - 1) * this.pagination.limit
			this.fetchLeads({ _offset: offset })
		},
		formatValue(value) {
			if (value === null || value === undefined) return '-'
			return 'EUR ' + Number(value).toLocaleString('nl-NL')
		},
	},
}
</script>

<style scoped>
.lead-list {
	padding: 20px;
}

.lead-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.lead-list__filters {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
	align-items: flex-start;
}

.lead-list__search {
	max-width: 300px;
	flex: 1;
}

.lead-list__filter {
	min-width: 160px;
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

.lead-list__empty {
	padding: 40px;
	text-align: center;
}

.lead-list__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-top: 16px;
}

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
