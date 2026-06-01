<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<!--
  KassakoppelingAuditList — searchable, filterable list of POS audit log entries.

  Displays a paginated table of Kassakoppeling audit entries with colour-coded
  action badges and signature-status indicators. An export button (admin only)
  triggers a file download from GET /api/kassakoppeling/audit/export.

  @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#5.1
-->
<template>
	<div class="audit-list">
		<CnIndexPage
			:title="t('pipelinq', 'Kassakoppeling Audit Log')"
			:description="t('pipelinq', 'Immutable POS audit ledger — Kassakoppeling compliant')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:selectable="false"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No audit entries found')"
			:show-add="false"
			@refresh="refresh"
			@sort="onSort"
			@row-click="openEntry"
			@page-changed="onPageChange">
			<!-- Filter toolbar -->
			<template #filters>
				<div class="audit-filters">
					<NcTextField
						v-model="filters.registerNumber"
						:label="t('pipelinq', 'Register')"
						:placeholder="t('pipelinq', 'e.g. REG-001')"
						@update:value="onFilterChange" />
					<NcTextField
						v-model="filters.operatorId"
						:label="t('pipelinq', 'Operator')"
						:placeholder="t('pipelinq', 'Operator ID')"
						@update:value="onFilterChange" />
					<NcSelect
						v-model="filters.action"
						:options="actionOptions"
						:placeholder="t('pipelinq', 'All actions')"
						:label="t('pipelinq', 'Action')"
						@update:value="onFilterChange" />
					<NcDateTimePicker
						v-model="filters.fromDate"
						:label="t('pipelinq', 'From date')"
						type="date"
						@update:value="onFilterChange" />
					<NcDateTimePicker
						v-model="filters.toDate"
						:label="t('pipelinq', 'To date')"
						type="date"
						@update:value="onFilterChange" />
				</div>
			</template>

			<!-- Custom cell renderers for action badge and verified badge -->
			<template #cell-action="{ value }">
				<span :class="['action-badge', 'action-badge--' + value]">
					{{ t('pipelinq', value) }}
				</span>
			</template>
			<template #cell-amount="{ value }">
				{{ formatAmount(value) }}
			</template>
			<template #cell-verified="{ value }">
				<span :class="['verified-badge', verifiedClass(value)]"
					:title="verifiedLabel(value)">
					{{ verifiedIcon(value) }} {{ verifiedLabel(value) }}
				</span>
			</template>

			<!-- Header actions: export button (admin only) -->
			<template #header-actions>
				<div v-if="isAdmin" class="export-controls">
					<NcSelect
						v-model="exportFormat"
						:options="exportFormatOptions"
						:label="t('pipelinq', 'Format')"
						style="min-width: 100px" />
					<NcButton
						type="primary"
						:disabled="exporting"
						@click="exportEntries">
						{{ t('pipelinq', 'Export for Belastingdienst') }}
					</NcButton>
				</div>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { NcButton, NcTextField, NcSelect, NcDateTimePicker } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'KassakoppelingAuditList',

	components: {
		CnIndexPage,
		NcButton,
		NcTextField,
		NcSelect,
		NcDateTimePicker,
	},

	setup() {
		const objectStore = useObjectStore()
		return useListView('kassakoppelingAuditLog', { objectStore })
	},

	data() {
		return {
			filters: {
				registerNumber: '',
				operatorId: '',
				action: '',
				fromDate: '',
				toDate: '',
			},
			exportFormat: 'xml',
			exporting: false,
			isAdmin: window.OC?.isUserAdmin?.() ?? false,
			actionOptions: [
				{ id: '', label: this.t('pipelinq', 'All actions') },
				{ id: 'sale', label: this.t('pipelinq', 'Sale') },
				{ id: 'void', label: this.t('pipelinq', 'Void') },
				{ id: 'refund', label: this.t('pipelinq', 'Refund') },
				{ id: 'no-sale', label: this.t('pipelinq', 'No-sale') },
			],
			exportFormatOptions: [
				{ id: 'xml', label: 'XML' },
				{ id: 'json', label: 'JSON' },
			],
		}
	},

	computed: {
		/**
		 * Columns shown in the audit list.
		 *
		 * @return {Array<string>}
		 */
		visibleColumns() {
			return ['timestamp', 'operatorId', 'registerNumber', 'action', 'amount', 'verified']
		},
	},

	methods: {
		/**
		 * Format an amount in cents to EUR display string.
		 *
		 * @param {number} cents Amount in cents.
		 * @return {string} Formatted EUR string.
		 */
		formatAmount(cents) {
			if (cents === null || cents === undefined) return '-'
			const eur = Number(cents) / 100
			return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(eur)
		},

		/**
		 * Return a CSS class for the verified status.
		 *
		 * @param {boolean|null} verified The verified flag.
		 * @return {string} CSS class suffix.
		 */
		verifiedClass(verified) {
			if (verified === true) return 'verified-badge--valid'
			if (verified === false) return 'verified-badge--invalid'
			return 'verified-badge--pending'
		},

		/**
		 * Return a human-readable label for the verified status.
		 *
		 * @param {boolean|null} verified The verified flag.
		 * @return {string} Label.
		 */
		verifiedLabel(verified) {
			if (verified === true) return this.t('pipelinq', 'Signed')
			if (verified === false) return this.t('pipelinq', 'Invalid')
			return this.t('pipelinq', 'Pending')
		},

		/**
		 * Return an icon character for the verified status.
		 *
		 * @param {boolean|null} verified The verified flag.
		 * @return {string} Icon.
		 */
		verifiedIcon(verified) {
			if (verified === true) return '✓'
			if (verified === false) return '⚠'
			return '?'
		},

		/**
		 * Navigate to the audit entry detail page.
		 *
		 * @param {object} row The clicked row.
		 */
		openEntry(row) {
			this.$router.push({ name: 'KassakoppelingAuditDetail', params: { id: row.id } })
		},

		/**
		 * Apply active filters to the list view.
		 */
		onFilterChange() {
			const activeFilters = {}
			if (this.filters.registerNumber) activeFilters.registerNumber = this.filters.registerNumber
			if (this.filters.operatorId) activeFilters.operatorId = this.filters.operatorId
			if (this.filters.action) activeFilters.action = this.filters.action
			if (this.filters.fromDate) activeFilters.fromDate = this.filters.fromDate
			if (this.filters.toDate) activeFilters.toDate = this.filters.toDate
			// Refresh list with new filters (useListView handles filter prop).
			this.refresh()
		},

		/**
		 * Export audit entries in the selected format.
		 */
		async exportEntries() {
			if (this.exporting) return
			this.exporting = true
			try {
				const from = this.filters.fromDate || '2020-01-01'
				const to = this.filters.toDate || new Date().toISOString().split('T')[0]
				const fmt = (this.exportFormat && this.exportFormat.id) ? this.exportFormat.id : this.exportFormat
				const url = generateUrl(
					`/apps/pipelinq/api/kassakoppeling/audit/export?fromDate=${from}&toDate=${to}&format=${fmt}`,
				)
				const link = document.createElement('a')
				link.href = url
				link.download = `kassakoppeling-export-${from}-to-${to}.${fmt}`
				document.body.appendChild(link)
				link.click()
				document.body.removeChild(link)
			} finally {
				this.exporting = false
			}
		},
	},
}
</script>

<style scoped>
.audit-list {
	height: 100%;
}

.audit-filters {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding: 8px 0;
	align-items: flex-end;
}

.action-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.85em;
	font-weight: 500;
	text-transform: capitalize;
}

.action-badge--sale {
	background-color: var(--color-success);
	color: var(--color-main-background);
}

.action-badge--void {
	background-color: var(--color-error);
	color: var(--color-main-background);
}

.action-badge--refund {
	background-color: var(--color-warning);
	color: var(--color-main-text);
}

.action-badge--no-sale {
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
}

.verified-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 0.85em;
	font-weight: 500;
}

.verified-badge--valid {
	color: var(--color-success);
}

.verified-badge--invalid {
	color: var(--color-error);
}

.verified-badge--pending {
	color: var(--color-text-lighter);
}

.export-controls {
	display: flex;
	gap: 8px;
	align-items: center;
}
</style>
