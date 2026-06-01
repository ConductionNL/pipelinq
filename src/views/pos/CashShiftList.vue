<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<div class="cash-shift-list">
		<CnIndexPage
			:title="t('pipelinq', 'Kassalade')"
			:description="t('pipelinq', 'Beheer kassashifts, tellingen en kassaverschillen')"
			:schema="schema"
			:objects="filteredObjects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:selectable="true"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'Geen kassashifts gevonden')"
			:empty-action-label="t('pipelinq', 'Nieuwe shift openen')"
			@add="openNewShift"
			@empty-action="openNewShift"
			@refresh="refresh"
			@sort="onSort"
			@row-click="openShiftDetail"
			@page-changed="onPageChange">
			<template #filters>
				<div class="cash-shift-list__filters">
					<NcSelect
						v-model="statusFilter"
						:options="statusOptions"
						:placeholder="t('pipelinq', 'Filter op status')"
						class="cash-shift-list__filter-select" />
					<NcTextField
						v-model="referenceSearch"
						:label="t('pipelinq', 'Zoek op referentie')"
						:placeholder="t('pipelinq', 'SHIFT-...')"
						class="cash-shift-list__filter-search" />
					<NcTextField
						v-model="dateFrom"
						type="date"
						:label="t('pipelinq', 'Van datum')"
						class="cash-shift-list__filter-date" />
					<NcTextField
						v-model="dateTo"
						type="date"
						:label="t('pipelinq', 'Tot datum')"
						class="cash-shift-list__filter-date" />
				</div>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { NcSelect, NcTextField } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'CashShiftList',
	components: {
		CnIndexPage,
		NcSelect,
		NcTextField,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('cashShift', { sidebarState, objectStore })
	},
	data() {
		return {
			statusFilter: null,
			referenceSearch: '',
			dateFrom: '',
			dateTo: '',
		}
	},
	computed: {
		/**
		 * Columns shown on the list, in order.
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return ['reference', 'drawer', 'operator', 'floatAmount', 'status', 'floatAt', 'closedAt']
		},
		/**
		 * Status filter options for the dropdown.
		 *
		 * @return {Array<{id: string, label: string}>} The options.
		 */
		statusOptions() {
			return [
				{ id: '', label: this.t('pipelinq', 'Alle statussen') },
				{ id: 'open', label: this.t('pipelinq', 'Open') },
				{ id: 'closed', label: this.t('pipelinq', 'Gesloten') },
				{ id: 'reconciled', label: this.t('pipelinq', 'Afgestemd') },
			]
		},
		/**
		 * Objects filtered by status, date range, and reference search.
		 *
		 * @return {Array<object>} The filtered shift objects.
		 */
		filteredObjects() {
			const objects = this.objects ?? []
			const selectedStatus = this.statusFilter?.id ?? ''
			const search = (this.referenceSearch ?? '').toLowerCase()
			const from = this.dateFrom ? new Date(this.dateFrom).toISOString() : ''
			const to = this.dateTo ? new Date(this.dateTo + 'T23:59:59').toISOString() : ''

			return objects.filter((shift) => {
				if (selectedStatus && shift.status !== selectedStatus) {
					return false
				}
				if (search && !(shift.reference ?? '').toLowerCase().includes(search)) {
					return false
				}
				const floatAt = shift.floatAt ?? ''
				if (from && floatAt < from) {
					return false
				}
				if (to && floatAt > to) {
					return false
				}
				return true
			})
		},
	},
	methods: {
		/**
		 * Navigate to a shift's detail view.
		 *
		 * @param {object} row The clicked row.
		 */
		openShiftDetail(row) {
			this.$router.push({ name: 'CashShiftDetail', params: { id: row.id } })
		},
		/**
		 * Navigate to the open-shift form.
		 */
		openNewShift() {
			this.$router.push({ name: 'CashShiftNew' })
		},
	},
}
</script>

<style scoped>
.cash-shift-list__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	padding: 0.5rem 0;
	align-items: flex-end;
}

.cash-shift-list__filter-select {
	min-width: 180px;
}

.cash-shift-list__filter-search {
	min-width: 200px;
}

.cash-shift-list__filter-date {
	min-width: 160px;
}
</style>
