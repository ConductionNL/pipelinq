<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Clients')"
			:description="t('pipelinq', 'Manage your client relationships')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:refreshing="refreshing"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:selectable="true"
			:include-columns="visibleColumns"
			@add="createNew"
			@refresh="onRefresh"
			@sort="onSort"
			@view="openClient"
			@page-changed="onPageChange" />
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ClientList',
	components: {
		CnIndexPage,
	},

	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-34
	 */
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('client', { sidebarState, objectStore })
	},

	data() {
		return {
			refreshing: false,
		}
	},

	methods: {
		/**
		 * Refresh handler for the Actions-menu Refresh item. Drives the
		 * CnIndexPage `:refreshing` spinner around the underlying fetch.
		 */
		async onRefresh() {
			this.refreshing = true
			try {
				await this.refresh()
			} finally {
				this.refreshing = false
			}
		},
		/**
		 * Open the detail view for a client (triggered by the View row action).
		 *
		 * @param {object} row The client object whose detail view to open.
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-33
		 */
		openClient(row) {
			this.$router.push({ name: 'ClientDetail', params: { id: row.id } })
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-32
		 */
		createNew() {
			this.$router.push({ name: 'ClientDetail', params: { id: 'new' } })
		},
	},
}
</script>
