<template>
	<div class="request-list">
		<div class="request-list__header">
			<h2>{{ t('pipelinq', 'Requests') }}</h2>
			<NcButton type="primary" @click="createNew">
				{{ t('pipelinq', 'New request') }}
			</NcButton>
		</div>

		<!-- Search + Filters -->
		<div class="request-list__filters">
			<NcTextField
				:value="searchTerm"
				:label="t('pipelinq', 'Search')"
				:show-trailing-button="searchTerm !== ''"
				class="filter-search"
				@update:value="onSearch"
				@trailing-button-click="clearSearch" />
			<NcSelect
				v-model="filters.status"
				:options="statusFilterOptions"
				:placeholder="t('pipelinq', 'Status')"
				:clearable="true"
				class="filter-select"
				@input="applyFilters" />
			<NcSelect
				v-model="filters.priority"
				:options="priorityFilterOptions"
				:placeholder="t('pipelinq', 'Priority')"
				:clearable="true"
				class="filter-select"
				@input="applyFilters" />
			<NcSelect
				v-model="filters.channel"
				:options="channelFilterOptions"
				:placeholder="t('pipelinq', 'Channel')"
				:clearable="true"
				class="filter-select"
				@input="applyFilters" />
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcNoteCard v-if="error" type="error" class="request-list__error">
			{{ error.message }}
			<NcButton type="secondary" @click="fetchRequests()">
				{{ t('pipelinq', 'Retry') }}
			</NcButton>
		</NcNoteCard>

		<div v-else-if="requests.length === 0" class="request-list__empty">
			<p>{{ t('pipelinq', 'No requests found') }}</p>
		</div>

		<table v-else class="request-list__table">
			<thead>
				<tr>
					<th class="col-title sortable" @click="toggleSort('title')">
						{{ t('pipelinq', 'Title') }}
						<span v-if="sortField === 'title'" class="sort-icon">{{ sortDir === 'asc' ? '\u25B2' : '\u25BC' }}</span>
					</th>
					<th class="col-status">{{ t('pipelinq', 'Status') }}</th>
					<th class="col-priority sortable" @click="toggleSort('priority')">
						{{ t('pipelinq', 'Priority') }}
						<span v-if="sortField === 'priority'" class="sort-icon">{{ sortDir === 'asc' ? '\u25B2' : '\u25BC' }}</span>
					</th>
					<th class="col-channel">{{ t('pipelinq', 'Channel') }}</th>
					<th class="col-assignee">{{ t('pipelinq', 'Assigned to') }}</th>
					<th class="col-date sortable" @click="toggleSort('requestedAt')">
						{{ t('pipelinq', 'Requested at') }}
						<span v-if="sortField === 'requestedAt'" class="sort-icon">{{ sortDir === 'asc' ? '\u25B2' : '\u25BC' }}</span>
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="request in requests"
					:key="request.id"
					class="request-list__row"
					@click="openRequest(request.id)">
					<td class="col-title">{{ request.title || '-' }}</td>
					<td class="col-status" @click.stop>
						<span
							class="status-badge"
							:style="{ background: getStatusColor(request.status), color: '#fff' }">
							{{ getStatusLabel(request.status) }}
						</span>
						<NcSelect
							v-if="getAllowedTransitions(request.status).length > 0"
							:value="null"
							:options="getTransitionOptions(request.status)"
							:placeholder="'\u2192'"
							:clearable="false"
							class="status-quick-change"
							@input="v => quickStatusChange(request, v)" />
					</td>
					<td class="col-priority">
						<span
							class="priority-badge"
							:style="{ color: getPriorityColor(request.priority) }">
							{{ getPriorityLabel(request.priority) }}
						</span>
					</td>
					<td class="col-channel">{{ request.channel || '-' }}</td>
					<td class="col-assignee">{{ request.assignee || '-' }}</td>
					<td class="col-date">{{ formatDate(request.requestedAt) }}</td>
				</tr>
			</tbody>
		</table>

		<div v-if="pagination.pages > 1" class="request-list__pagination">
			<NcButton
				:disabled="pagination.page <= 1"
				@click="loadPage(pagination.page - 1)">
				{{ t('pipelinq', 'Previous') }}
			</NcButton>
			<span>{{ pagination.page }} / {{ pagination.pages }}</span>
			<NcButton
				:disabled="pagination.page >= pagination.pages"
				@click="loadPage(pagination.page + 1)">
				{{ t('pipelinq', 'Next') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'
import { useRequestChannelsStore } from '../../store/modules/requestChannels.js'
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
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},
	data() {
		return {
			searchTerm: '',
			searchTimeout: null,
			filters: {
				status: null,
				priority: null,
				channel: null,
			},
			sortField: 'requestedAt',
			sortDir: 'desc',
			statusFilterOptions: ['new', 'in_progress', 'completed', 'rejected', 'converted'],
			priorityFilterOptions: ['low', 'normal', 'high', 'urgent'],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		requestChannelsStore() {
			return useRequestChannelsStore()
		},
		channelFilterOptions() {
			return this.requestChannelsStore.channelNames
		},
		requests() {
			return this.objectStore.getCollection('request')
		},
		loading() {
			return this.objectStore.isLoading('request')
		},
		error() {
			return this.objectStore.getError('request')
		},
		pagination() {
			return this.objectStore.getPagination('request')
		},
	},
	mounted() {
		this.requestChannelsStore.fetchChannels()
		this.fetchRequests()
	},
	methods: {
		getAllowedTransitions,
		getStatusLabel,
		getStatusColor,
		getPriorityLabel,
		getPriorityColor,

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

		buildParams() {
			const params = {
				_limit: 20,
				_offset: 0,
			}
			if (this.searchTerm) {
				params._search = this.searchTerm
			}
			if (this.filters.status) {
				params.status = this.filters.status
			}
			if (this.filters.priority) {
				params.priority = this.filters.priority
			}
			if (this.filters.channel) {
				params.channel = this.filters.channel
			}
			if (this.sortField) {
				params._order = { [this.sortField]: this.sortDir }
			}
			return params
		},

		async fetchRequests(extraParams = {}) {
			await this.objectStore.fetchCollection('request', {
				...this.buildParams(),
				...extraParams,
			})
		},

		applyFilters() {
			this.fetchRequests()
		},

		toggleSort(field) {
			if (this.sortField === field) {
				this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'
			} else {
				this.sortField = field
				this.sortDir = 'asc'
			}
			this.fetchRequests()
		},

		openRequest(id) {
			this.$emit('navigate', 'request-detail', id)
		},
		createNew() {
			this.$emit('navigate', 'request-detail', 'new')
		},
		onSearch(value) {
			this.searchTerm = value
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(() => {
				this.fetchRequests()
			}, 300)
		},
		clearSearch() {
			this.searchTerm = ''
			this.fetchRequests()
		},
		loadPage(page) {
			const offset = (page - 1) * this.pagination.limit
			this.fetchRequests({ _offset: offset })
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
.request-list {
	padding: 20px;
}

.request-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.request-list__filters {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
	flex-wrap: wrap;
	align-items: flex-end;
}

.filter-search {
	flex: 1;
	min-width: 200px;
	max-width: 300px;
}

.filter-select {
	min-width: 140px;
	max-width: 180px;
}

.request-list__table {
	width: 100%;
	border-collapse: collapse;
}

.request-list__table th,
.request-list__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.request-list__table th.sortable {
	cursor: pointer;
	user-select: none;
}

.request-list__table th.sortable:hover {
	color: var(--color-main-text);
	background: var(--color-background-hover);
}

.sort-icon {
	font-size: 10px;
	margin-left: 4px;
}

.request-list__row {
	cursor: pointer;
}

.request-list__row:hover {
	background: var(--color-background-hover);
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.priority-badge {
	font-weight: 600;
	font-size: 13px;
}

.col-status {
	display: flex;
	align-items: center;
	gap: 6px;
}

.status-quick-change {
	width: 44px;
	min-width: 44px;
}

.request-list__empty {
	padding: 40px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.request-list__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-top: 16px;
}
</style>
