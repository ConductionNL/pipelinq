<template>
	<div class="request-list">
		<div class="request-list__header">
			<h2>{{ t('pipelinq', 'Requests') }}</h2>
			<NcButton type="primary" @click="createNew">
				{{ t('pipelinq', 'New request') }}
			</NcButton>
		</div>

		<div class="request-list__search">
			<NcTextField
				:value="searchTerm"
				:label="t('pipelinq', 'Search')"
				:show-trailing-button="searchTerm !== ''"
				@update:value="onSearch"
				@trailing-button-click="clearSearch" />
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="requests.length === 0" class="request-list__empty">
			<p>{{ t('pipelinq', 'No requests found') }}</p>
		</div>

		<table v-else class="request-list__table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Title') }}</th>
					<th>{{ t('pipelinq', 'Status') }}</th>
					<th>{{ t('pipelinq', 'Priority') }}</th>
					<th>{{ t('pipelinq', 'Requested at') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="request in requests"
					:key="request.id"
					class="request-list__row"
					@click="openRequest(request.id)">
					<td>{{ request.title || '-' }}</td>
					<td>{{ request.status || '-' }}</td>
					<td>{{ request.priority || '-' }}</td>
					<td>{{ formatDate(request.requestedAt) }}</td>
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
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'RequestList',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},
	data() {
		return {
			searchTerm: '',
			searchTimeout: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		requests() {
			return this.objectStore.getCollection('request')
		},
		loading() {
			return this.objectStore.isLoading('request')
		},
		pagination() {
			return this.objectStore.getPagination('request')
		},
	},
	mounted() {
		this.fetchRequests()
	},
	methods: {
		async fetchRequests(params = {}) {
			await this.objectStore.fetchCollection('request', {
				_limit: 20,
				_offset: 0,
				...params,
			})
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
				this.fetchRequests({ _search: this.searchTerm })
			}, 300)
		},
		clearSearch() {
			this.searchTerm = ''
			this.fetchRequests()
		},
		loadPage(page) {
			const offset = (page - 1) * this.pagination.limit
			this.fetchRequests({
				_offset: offset,
				_search: this.searchTerm || undefined,
			})
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

.request-list__search {
	margin-bottom: 16px;
	max-width: 400px;
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

.request-list__row {
	cursor: pointer;
}

.request-list__row:hover {
	background: var(--color-background-hover);
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
