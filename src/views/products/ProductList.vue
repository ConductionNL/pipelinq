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
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ProductList',
	components: {
		CnIndexPage,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('product', { sidebarState, objectStore })
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
