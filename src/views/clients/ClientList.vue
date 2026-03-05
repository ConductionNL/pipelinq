<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Clients')"
			:description="t('pipelinq', 'Manage your client relationships')"
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
			@row-click="openClient"
			@page-changed="onPageChange" />
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'

export default {
	name: 'ClientList',
	components: {
		CnIndexPage,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		return useListView('client', { sidebarState })
	},

	methods: {
		openClient(row) {
			this.$router.push({ name: 'ClientDetail', params: { id: row.id } })
		},
		createNew() {
			this.$router.push({ name: 'ClientDetail', params: { id: 'new' } })
		},
	},
}
</script>
