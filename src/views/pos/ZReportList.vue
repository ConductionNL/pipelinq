<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Boekhoudkundige Afhandeling — daily Z-report list with status / date / terminal
  - filters. Row click navigates to the per-Z-report detail with the GL mapping,
  - submission timeline and resubmit action.
  -
  - @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#5.1
  -->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Boekhoudkundige Afhandeling')"
			:description="t('pipelinq', 'Dagelijkse Z-reports met Shillinq inboekstatus, BTW-uitsplitsing en retry geschiedenis.')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:selectable="true"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'Geen Z-reports gevonden')"
			@refresh="refresh"
			@sort="onSort"
			@row-click="openDetail"
			@page-changed="onPageChange" />
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ZReportList',
	components: {
		CnIndexPage,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('posZReport', { sidebarState, objectStore })
	},
	computed: {
		/**
		 * Columns shown on the list, in order.
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return [
				'reference',
				'reportDate',
				'terminalId',
				'transactionCount',
				'total',
				'status',
				'createdAt',
			]
		},
	},
	methods: {
		/**
		 * Navigate to the Z-report detail view.
		 *
		 * @param {object} row The clicked row.
		 */
		openDetail(row) {
			this.$router.push({ name: 'ZReportDetail', params: { id: row.id } })
		},
	},
}
</script>
