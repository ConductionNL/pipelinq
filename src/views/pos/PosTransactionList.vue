<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'POS')"
			:description="t('pipelinq', 'Register sales and manage receipts')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:refreshing="refreshing"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:selectable="true"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'Geen transacties gevonden')"
			:empty-action-label="t('pipelinq', 'Nieuwe transactie')"
			@add="createNew"
			@empty-action="createNew"
			@refresh="onRefresh"
			@sort="onSort"
			@view="openTransaction"
			@page-changed="onPageChange" />
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PosTransactionList',
	components: {
		CnIndexPage,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('posTransaction', { sidebarState, objectStore })
	},
	data() {
		return {
			refreshing: false,
		}
	},
	computed: {
		/**
		 * Columns shown on the list, in order.
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return ['reference', 'cashier', 'terminalId', 'status', 'priceMode', 'total', 'created']
		},
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
		 * Navigate to a transaction's detail.
		 *
		 * @param {object} row The clicked row.
		 */
		openTransaction(row) {
			this.$router.push({ name: 'PosTransactionDetail', params: { id: row.id } })
		},
		/**
		 * Start a new draft transaction.
		 */
		createNew() {
			this.$router.push({ name: 'PosTransactionNew' })
		},
	},
}
</script>
