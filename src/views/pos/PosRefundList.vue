<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Retouren')"
			:description="t('pipelinq', 'Process refunds and returns')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:selectable="true"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'Geen retouren gevonden')"
			:empty-action-label="t('pipelinq', 'Nieuwe retour')"
			@add="createNew"
			@empty-action="createNew"
			@refresh="refresh"
			@sort="onSort"
			@view="openRefund"
			@page-changed="onPageChange" />
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PosRefundList',
	components: {
		CnIndexPage,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('posRefund', { sidebarState, objectStore })
	},
	computed: {
		/**
		 * Columns shown on the list, in order.
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return ['reference', 'originalTransaction', 'refundReason', 'refundAmount', 'status', 'created']
		},
	},
	methods: {
		/**
		 * Navigate to a refund's detail.
		 *
		 * @param {object} row The clicked row.
		 */
		openRefund(row) {
			this.$router.push({ name: 'PosRefundDetail', params: { id: row.id } })
		},
		/**
		 * Start a new refund.
		 */
		createNew() {
			this.$router.push({ name: 'PosRefundNew' })
		},
	},
}
</script>
