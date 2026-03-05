<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Products')"
			:description="t('pipelinq', 'Manage your product and service catalog')"
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
			@row-click="openProduct"
			@page-changed="onPageChange" />
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'

export default {
	name: 'ProductList',
	components: {
		CnIndexPage,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		return useListView('product', { sidebarState })
	},

	methods: {
		openProduct(row) {
			this.$router.push({ name: 'ProductDetail', params: { id: row.id } })
		},
		createNew() {
			this.$router.push({ name: 'ProductDetail', params: { id: 'new' } })
		},
	},
}
</script>
