<template>
	<div class="client-list">
		<div class="client-list__header">
			<h2>{{ t('pipelinq', 'Clients') }}</h2>
			<NcButton type="primary" @click="createNew">
				{{ t('pipelinq', 'New client') }}
			</NcButton>
		</div>

		<div class="client-list__search">
			<NcTextField
				:value="searchTerm"
				:label="t('pipelinq', 'Search')"
				:show-trailing-button="searchTerm !== ''"
				@update:value="onSearch"
				@trailing-button-click="clearSearch" />
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="clients.length === 0" class="client-list__empty">
			<p>{{ t('pipelinq', 'No clients found') }}</p>
		</div>

		<table v-else class="client-list__table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Name') }}</th>
					<th>{{ t('pipelinq', 'Type') }}</th>
					<th>{{ t('pipelinq', 'Email') }}</th>
					<th>{{ t('pipelinq', 'Phone') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="client in clients"
					:key="client.id"
					class="client-list__row"
					@click="openClient(client.id)">
					<td>{{ client.name || '-' }}</td>
					<td>{{ client.type || '-' }}</td>
					<td>{{ client.email || '-' }}</td>
					<td>{{ client.phone || '-' }}</td>
				</tr>
			</tbody>
		</table>

		<div v-if="pagination.pages > 1" class="client-list__pagination">
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
	name: 'ClientList',
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
		clients() {
			return this.objectStore.getCollection('client')
		},
		loading() {
			return this.objectStore.isLoading('client')
		},
		pagination() {
			return this.objectStore.getPagination('client')
		},
	},
	mounted() {
		this.fetchClients()
	},
	methods: {
		async fetchClients(params = {}) {
			await this.objectStore.fetchCollection('client', {
				_limit: 20,
				_offset: 0,
				...params,
			})
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
				this.fetchClients({ _search: this.searchTerm })
			}, 300)
		},
		clearSearch() {
			this.searchTerm = ''
			this.fetchClients()
		},
		loadPage(page) {
			const offset = (page - 1) * this.pagination.limit
			this.fetchClients({
				_offset: offset,
				_search: this.searchTerm || undefined,
			})
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

.client-list__search {
	margin-bottom: 16px;
	max-width: 400px;
}

.client-list__table {
	width: 100%;
	border-collapse: collapse;
}

.client-list__table th,
.client-list__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.client-list__row {
	cursor: pointer;
}

.client-list__row:hover {
	background: var(--color-background-hover);
}

.client-list__empty {
	padding: 40px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.client-list__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-top: 16px;
}
</style>
