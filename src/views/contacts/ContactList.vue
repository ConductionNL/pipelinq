<template>
	<div class="contact-list">
		<div class="contact-list__header">
			<h2>{{ t('pipelinq', 'Contacts') }}</h2>
			<NcButton type="primary" @click="createNew">
				{{ t('pipelinq', 'New contact') }}
			</NcButton>
		</div>

		<div class="contact-list__search">
			<NcTextField
				:value="searchTerm"
				:label="t('pipelinq', 'Search')"
				:show-trailing-button="searchTerm !== ''"
				@update:value="onSearch"
				@trailing-button-click="clearSearch" />
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcNoteCard v-if="error" type="error" class="contact-list__error">
			{{ error.message }}
			<NcButton type="secondary" @click="fetchContacts()">
				{{ t('pipelinq', 'Retry') }}
			</NcButton>
		</NcNoteCard>

		<div v-else-if="contacts.length === 0" class="contact-list__empty">
			<NcEmptyContent
				:name="searchTerm ? t('pipelinq', 'No contacts match your search') : t('pipelinq', 'No contacts yet')">
				<template #action>
					<NcButton v-if="!searchTerm" type="primary" @click="createNew">
						{{ t('pipelinq', 'Create your first contact') }}
					</NcButton>
					<NcButton v-else @click="clearSearch">
						{{ t('pipelinq', 'Clear search') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<div v-else class="viewTableContainer">
			<table class="viewTable">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Name') }}</th>
					<th>{{ t('pipelinq', 'Role') }}</th>
					<th>{{ t('pipelinq', 'Client') }}</th>
					<th>{{ t('pipelinq', 'Email') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="contact in contacts"
					:key="contact.id"
					class="viewTableRow"
					@click="openContact(contact.id)">
					<td>{{ contact.name || '-' }}</td>
					<td>{{ contact.role || '-' }}</td>
					<td>
						<a
							class="client-link"
							@click.stop="navigateToClient(contact.client)">
							{{ getClientName(contact.client) }}
						</a>
					</td>
					<td>{{ contact.email || '-' }}</td>
				</tr>
			</tbody>
		</table>
	</div>

		<div v-if="pagination.pages > 1" class="contact-list__pagination">
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
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField, NcEmptyContent } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ContactList',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		NcEmptyContent,
	},
	data() {
		return {
			searchTerm: '',
			searchTimeout: null,
			clientNames: {},
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		contacts() {
			return this.objectStore.getCollection('contact')
		},
		loading() {
			return this.objectStore.isLoading('contact')
		},
		error() {
			return this.objectStore.getError('contact')
		},
		pagination() {
			return this.objectStore.getPagination('contact')
		},
	},
	async mounted() {
		await this.fetchContacts()
		this.loadClientNames()
	},
	methods: {
		async fetchContacts(params = {}) {
			const fetchParams = {
				_limit: 20,
				_offset: 0,
				...params,
			}
			if (this.searchTerm) {
				fetchParams._search = this.searchTerm
			}
			await this.objectStore.fetchCollection('contact', fetchParams)
		},
		async loadClientNames() {
			const clientIds = this.contacts
				.map(c => c.client)
				.filter(Boolean)
			const uniqueIds = [...new Set(clientIds)]
			const resolved = await this.objectStore.resolveReferences('client', uniqueIds)
			for (const id of uniqueIds) {
				if (resolved[id]) {
					this.$set(this.clientNames, id, resolved[id].name || '-')
				} else {
					this.$set(this.clientNames, id, t('pipelinq', '[Deleted]'))
				}
			}
		},
		getClientName(clientId) {
			if (!clientId) return '-'
			return this.clientNames[clientId] || t('pipelinq', '[Loading...]')
		},
		openContact(id) {
			this.$emit('navigate', 'contact-detail', id)
		},
		navigateToClient(clientId) {
			if (clientId) {
				this.$emit('navigate', 'client-detail', clientId)
			}
		},
		createNew() {
			this.$emit('navigate', 'contact-detail', 'new')
		},
		onSearch(value) {
			this.searchTerm = value
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(async () => {
				await this.fetchContacts()
				this.loadClientNames()
			}, 300)
		},
		clearSearch() {
			this.searchTerm = ''
			this.fetchContacts()
		},
		loadPage(page) {
			const offset = (page - 1) * this.pagination.limit
			this.fetchContacts({ _offset: offset })
		},
	},
}
</script>

<style scoped>
.contact-list {
	padding: 20px;
}

.contact-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.contact-list__search {
	margin-bottom: 16px;
	max-width: 400px;
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

.viewTableRow {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background: var(--color-background-hover);
}

.client-link {
	color: var(--color-primary);
	cursor: pointer;
	text-decoration: underline;
}

.client-link:hover {
	color: var(--color-primary-hover);
}

.contact-list__empty {
	padding: 40px;
	text-align: center;
}

.contact-list__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-top: 16px;
}
</style>
