<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Expense list view with Shillinq AP sync-status badge column (REQ-AP-005).
  The badge renders inline (color-coded span) so the list view ships as a
  single component without depending on expense-capture-core's not-yet-built
  list. Clicking a row opens the expense detail view; the badge itself is
  read-only.
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Expenses')"
			:description="t('pipelinq', 'Captured expense submissions with Shillinq AP voucher sync status')"
			:schema="schema"
			:objects="decoratedObjects"
			:pagination="pagination"
			:loading="loading"
			:refreshing="refreshing"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No expenses yet')"
			:empty-action-label="t('pipelinq', 'New expense')"
			@add="createNew"
			@empty-action="createNew"
			@refresh="onRefresh"
			@sort="onSort"
			@row-click="openExpense"
			@page-changed="onPageChange">
			<template #cell-apSyncStatus="{ row }">
				<span class="ap-status-badge"
					:style="badgeStyle(row.apSyncStatus)"
					:aria-label="badgeLabel(row.apSyncStatus)">
					{{ badgeLabel(row.apSyncStatus) }}
				</span>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ExpenseList',
	components: {
		CnIndexPage,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('expense', { sidebarState, objectStore })
	},
	data() {
		return {
			refreshing: false,
		}
	},
	computed: {
		/**
		 * Columns shown on the list, in order. REQ-AP-005.
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return ['title', 'amount', 'category', 'status', 'apSyncStatus']
		},
		/**
		 * Pass-through to keep CnIndexPage on the same reactive list while we
		 * inject AP-status badges via the per-cell slot.
		 *
		 * @return {Array<object>} The objects array.
		 */
		decoratedObjects() {
			return this.objects || []
		},
	},
	methods: {
		/**
		 * Refresh handler for the Actions-menu Refresh item. Drives the
		 * CnIndexPage `:refreshing` spinner around the underlying fetch.
		 *
		 * @spec exclude presentational refresh-button spinner wiring — no business logic
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
		 * Inline style for the AP status badge. REQ-AP-005.
		 *
		 * @param {string|null} status The AP sync status.
		 * @return {object} The inline style.
		 */
		badgeStyle(status) {
			const colors = {
				synced: '#28a745',
				pending: '#ffc107',
				failed: '#dc3545',
			}
			return {
				backgroundColor: colors[status] || '#6c757d',
				color: status === 'pending' ? '#212529' : '#ffffff',
				padding: '2px 8px',
				borderRadius: '12px',
				fontSize: '0.85em',
				fontWeight: '500',
				display: 'inline-block',
			}
		},
		/**
		 * Localised AP status label. REQ-AP-005.
		 *
		 * @param {string|null} status The AP sync status.
		 * @return {string} The label.
		 */
		badgeLabel(status) {
			if (status === 'synced') {
				return t('pipelinq', 'AP gesynchroniseerd')
			}
			if (status === 'pending') {
				return t('pipelinq', 'AP in behandeling')
			}
			if (status === 'failed') {
				return t('pipelinq', 'AP mislukt')
			}
			return '–'
		},
		/**
		 * Open an expense detail.
		 *
		 * @param {object} row The clicked row.
		 */
		openExpense(row) {
			this.$router.push({ name: 'ExpenseDetail', params: { id: row.id } })
		},
		/**
		 * Start a new expense.
		 */
		createNew() {
			this.$router.push({ name: 'ExpenseNew' })
		},
	},
}
</script>

<style scoped>
.ap-status-badge {
	white-space: nowrap;
}
</style>
