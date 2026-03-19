<template>
	<CnIndexPage
		:title="t('pipelinq', 'Contacts')"
		:description="t('pipelinq', 'Manage your contacts')"
		:schema="schema"
		:objects="objects"
		:pagination="pagination"
		:loading="loading"
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:selectable="true"
		:include-columns="visibleColumns"
		@add="createNew"
		@refresh="refresh"
		@sort="onSort"
		@row-click="openContact"
		@page-changed="onPageChange">
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
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ContactList',
	components: {
		CnIndexPage,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('contact', { sidebarState, objectStore })
	},

	data() {
		return {
			clientNames: {},
		}
	},

	watch: {
		objects() {
			this.loadClientNames()
		},
	},

	methods: {
		async loadClientNames() {
			const objectStore = useObjectStore()
			const clientIds = this.objects
				.map(c => c.client)
				.filter(Boolean)
			const uniqueIds = [...new Set(clientIds)]
			if (uniqueIds.length === 0) return
			const resolved = await objectStore.resolveReferences('client', uniqueIds)
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
