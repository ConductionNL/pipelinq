<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->

<!--
  Z-report list view for POS boekhoudkundige afhandeling.

  Displays all posZReport objects in a filterable data table with columns for
  reference, date, terminal, transaction count, total, status badge, and last
  action. Filters: status dropdown, date range, terminal selector, search by
  reference. Each row links to the ZReportDetail view.

  @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#5.1
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Boekhoudkundige Afhandeling')"
			:description="t('pipelinq', 'Beheer dagelijkse Z-rapporten en Shillinq-boekingen')"
			:schema="schema"
			:objects="filteredObjects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:selectable="false"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'Geen Z-rapporten gevonden')"
			@refresh="refresh"
			@sort="onSort"
			@row-click="openZReport"
			@page-changed="onPageChange">
			<!-- Filter toolbar slot -->
			<template #header-actions>
				<div class="z-report-filters">
					<NcSelect
						v-model="statusFilter"
						:options="statusOptions"
						:placeholder="t('pipelinq', 'Status filter')"
						:label="t('pipelinq', 'Status')"
						multiple
						class="z-report-filter-item" />
					<NcDateTimePickerNative
						v-model="dateFrom"
						:label="t('pipelinq', 'Vanaf datum')"
						type="date"
						class="z-report-filter-item" />
					<NcDateTimePickerNative
						v-model="dateTo"
						:label="t('pipelinq', 'Tot datum')"
						type="date"
						class="z-report-filter-item" />
					<NcTextField
						v-model="terminalFilter"
						:label="t('pipelinq', 'Terminal')"
						:placeholder="t('pipelinq', 'Bijv. kassa-01')"
						class="z-report-filter-item" />
					<NcTextField
						v-model="searchQuery"
						:label="t('pipelinq', 'Zoeken')"
						:placeholder="t('pipelinq', 'Referentie...')"
						class="z-report-filter-item" />
				</div>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
/**
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#5.1
 */
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { NcSelect, NcTextField, NcDateTimePickerNative } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ZReportList',

	components: {
		CnIndexPage,
		NcSelect,
		NcTextField,
		NcDateTimePickerNative,
	},

	setup() {
		const objectStore = useObjectStore()
		return useListView('posZReport', { objectStore })
	},

	data() {
		return {
			/** @type {string[]} Selected status values for filtering. */
			statusFilter: [],
			/** @type {string} Date range lower bound (YYYY-MM-DD). */
			dateFrom: '',
			/** @type {string} Date range upper bound (YYYY-MM-DD). */
			dateTo: '',
			/** @type {string} Terminal ID text filter. */
			terminalFilter: '',
			/** @type {string} Reference free-text search. */
			searchQuery: '',
		}
	},

	computed: {
		/**
		 * Columns displayed on the list, in order.
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return ['reference', 'reportDate', 'terminalId', 'transactionCount', 'total', 'status']
		},

		/**
		 * Status options for the filter dropdown.
		 *
		 * @return {Array<{label: string, value: string}>} Status options.
		 */
		statusOptions() {
			return [
				{ label: this.t('pipelinq', 'Concept'), value: 'draft' },
				{ label: this.t('pipelinq', 'Gereed'), value: 'ready' },
				{ label: this.t('pipelinq', 'Ingediend'), value: 'submitted' },
				{ label: this.t('pipelinq', 'Geboekt'), value: 'posted' },
				{ label: this.t('pipelinq', 'Mislukt'), value: 'failed' },
				{ label: this.t('pipelinq', 'Verrekend'), value: 'reconciled' },
			]
		},

		/**
		 * Objects filtered by active client-side criteria (status, date range,
		 * terminal, search query). Server-side filtering via useListView handles
		 * pagination and schema scoping; client-side adds the additional cross-
		 * dimension narrowing not expressible as a single filter key.
		 *
		 * @return {Array<object>} Filtered Z-report objects.
		 */
		filteredObjects() {
			if (this.objects == null) {
				return []
			}

			return this.objects.filter((obj) => {
				// Status filter.
				if (this.statusFilter.length > 0 && !this.statusFilter.includes(obj.status)) {
					return false
				}

				// Date range filter.
				if (this.dateFrom && obj.reportDate < this.dateFrom) {
					return false
				}
				if (this.dateTo && obj.reportDate > this.dateTo) {
					return false
				}

				// Terminal filter.
				if (this.terminalFilter && !(obj.terminalId || '').toLowerCase().includes(this.terminalFilter.toLowerCase())) {
					return false
				}

				// Search by reference.
				if (this.searchQuery && !(obj.reference || '').toLowerCase().includes(this.searchQuery.toLowerCase())) {
					return false
				}

				return true
			})
		},
	},

	methods: {
		/**
		 * Navigate to a Z-report detail page.
		 *
		 * @param {object} row The clicked row object.
		 */
		openZReport(row) {
			this.$router.push({ name: 'ZReportDetail', params: { id: row.id } })
		},

		/**
		 * Format a monetary amount as a EUR string.
		 *
		 * @param {number} amount The amount.
		 * @return {string} Formatted string.
		 */
		formatCurrency(amount) {
			return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount ?? 0)
		},
	},
}
</script>

<style scoped>
.z-report-filters {
	display: flex;
	flex-wrap: wrap;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px) 0;
}

.z-report-filter-item {
	min-width: 160px;
}
</style>
