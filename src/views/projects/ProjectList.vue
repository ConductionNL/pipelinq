<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Project list view (project-task-hierarchy / REQ-PTH-006).

  Top-level WBS surface — lists every project for the current pipelinq
  register. Wraps CnIndexPage + useListView so search / sort / pagination /
  sidebar all come straight from the platform; we contribute custom cell
  slots for the status badge, billable indicator, budget/logged progress and
  end-date hint. Clicking a row navigates to /projects/:id.
-->
<template>
	<CnIndexPage
		:title="t('pipelinq', 'Projecten')"
		:description="t('pipelinq', 'Werkverdeling per client — projecten, fasen en taken')"
		:schema="schema"
		:objects="objects || []"
		:pagination="pagination"
		:loading="loading"
		:refreshing="refreshing"
		:sort-key="sortKey"
		:sort-order="sortOrder"
		:include-columns="visibleColumns"
		:empty-title="t('pipelinq', 'Nog geen projecten')"
		:empty-action-label="t('pipelinq', 'Nieuw project')"
		@add="createNew"
		@empty-action="createNew"
		@refresh="onRefresh"
		@sort="onSort"
		@view="openProject"
		@page-changed="onPageChange">
		<template #cell-status="{ row }">
			<span class="status-pill" :class="statusClass(row.status)">
				{{ statusLabel(row.status) }}
			</span>
		</template>
		<template #cell-billable="{ row }">
			<span :class="['billable-dot', row.billable === false ? 'billable-dot--off' : 'billable-dot--on']" />
			{{ row.billable === false ? t('pipelinq', 'Niet-factureerbaar') : t('pipelinq', 'Factureerbaar') }}
		</template>
		<template #cell-budget="{ row }">
			<span>
				{{ formatHours(row.budgetHours) }}
				<small v-if="row.budgetAmount > 0" class="budget-amount">
					· {{ formatEur(row.budgetAmount) }}
				</small>
			</span>
		</template>
		<template #cell-endDate="{ row }">
			<span :class="{ 'endDate--overdue': isOverdue(row) }">
				{{ formatDate(row.endDate) }}
			</span>
		</template>
		<template #cell-ledgerSyncStatus="{ row }">
			<span v-if="!row.ledgerSyncStatus" class="ledger-dash">-</span>
			<span
				v-else
				class="ledger-pill"
				:class="ledgerPillClass(row.ledgerSyncStatus)">
				{{ ledgerLabel(row.ledgerSyncStatus) }}
			</span>
		</template>
	</CnIndexPage>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ProjectList',
	components: {
		CnIndexPage,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('project', { sidebarState, objectStore })
	},
	data() {
		return {
			schema: 'project',
			refreshing: false,
		}
	},
	computed: {
		/**
		 * Columns rendered on the project list (REQ-PTH-006 / Scenario 22).
		 *
		 * @return {Array<string>}
		 */
		visibleColumns() {
			return ['name', 'client', 'status', 'ledgerSyncStatus', 'billable', 'budget', 'endDate']
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
		 * Navigate to the project detail page (REQ-PTH-006 Scenario 22).
		 *
		 * @param {object} row The clicked project row.
		 */
		openProject(row) {
			this.$router.push({ name: 'ProjectDetail', params: { id: row.id } })
		},
		/**
		 * Navigate to the new-project detail form (REQ-PTH-001 Scenario 1).
		 */
		createNew() {
			this.$router.push({ name: 'ProjectDetail', params: { id: 'new' } })
		},
		/**
		 * Project lifecycle status label.
		 *
		 * @param {string|null} status The project status.
		 * @return {string}
		 */
		statusLabel(status) {
			const map = {
				open: t('pipelinq', 'Open'),
				in_progress: t('pipelinq', 'Loopt'),
				on_hold: t('pipelinq', 'Gepauzeerd'),
				completed: t('pipelinq', 'Afgerond'),
				cancelled: t('pipelinq', 'Geannuleerd'),
			}
			return map[status] || (status || '-')
		},
		/**
		 * CSS class for the status pill.
		 *
		 * @param {string|null} status The project status.
		 * @return {string}
		 */
		statusClass(status) {
			return 'status-pill--' + (status || 'open')
		},
		/**
		 * Format hours with the Dutch "u" suffix, returning "-" for missing.
		 *
		 * @param {*} value Raw hours (number).
		 * @return {string}
		 */
		formatHours(value) {
			if (value === null || value === undefined || value === '') return '-'
			const n = Number(value)
			if (Number.isNaN(n)) return '-'
			return n + 'u'
		},
		/**
		 * Format a number as Dutch-locale EUR.
		 *
		 * @param {*} value Raw amount.
		 * @return {string}
		 */
		formatEur(value) {
			const n = Number(value || 0)
			if (Number.isNaN(n)) return '€ 0'
			return '€ ' + n.toLocaleString('nl-NL', { maximumFractionDigits: 0 })
		},
		/**
		 * Format an ISO date as a locale string, "-" when missing.
		 *
		 * @param {string|null} dateStr ISO date.
		 * @return {string}
		 */
		formatDate(dateStr) {
			if (!dateStr) return '-'
			try {
				return new Date(dateStr).toLocaleDateString()
			} catch {
				return dateStr
			}
		},
		/**
		 * Translated label for the ledger sync status pill (REQ-PLG-004).
		 *
		 * @param {string|null} status The ledgerSyncStatus value.
		 * @return {string}
		 */
		ledgerLabel(status) {
			const map = {
				synced: t('pipelinq', 'Ledger synchronized'),
				pending: t('pipelinq', 'Ledger pending'),
				failed: t('pipelinq', 'Ledger sync failed'),
			}
			return map[status] || (status || '-')
		},
		/**
		 * CSS modifier class for the ledger sync status pill (REQ-PLG-004).
		 *
		 * @param {string|null} status The ledgerSyncStatus value.
		 * @return {string}
		 */
		ledgerPillClass(status) {
			return 'ledger-pill--' + (status || 'unknown')
		},
		/**
		 * Whether the project is past its end date AND not finalised.
		 *
		 * @param {object} project The project row.
		 * @return {boolean}
		 */
		isOverdue(project) {
			if (!project || !project.endDate) return false
			if (project.status === 'completed' || project.status === 'cancelled') return false
			try {
				return Date.parse(project.endDate) < Date.now()
			} catch {
				return false
			}
		},
	},
}
</script>

<style scoped>
.status-pill {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.85em;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.status-pill--open { background: #e3f2fd; color: #0d47a1; }

.status-pill--in_progress { background: #fff8e1; color: #6d4c00; }

.status-pill--on_hold { background: #ede7f6; color: #4527a0; }

.status-pill--completed { background: #e8f5e9; color: #1b5e20; }

.status-pill--cancelled { background: #fbe9e7; color: #b71c1c; }

.billable-dot {
	display: inline-block;
	width: 8px;
	height: 8px;
	border-radius: 50%;
	margin-right: 6px;
	vertical-align: middle;
}

.billable-dot--on { background: #43a047; }

.billable-dot--off { background: #b0bec5; }

.budget-amount {
	color: var(--color-text-maxcontrast);
}

.endDate--overdue {
	color: #c62828;
	font-weight: 600;
}

/* Ledger sync status pill (REQ-PLG-004). */
.ledger-pill {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.85em;
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.ledger-pill--synced { background: #e8f5e9; color: #1b5e20; }

.ledger-pill--pending { background: #fff8e1; color: #6d4c00; }

.ledger-pill--failed { background: #fbe9e7; color: #b71c1c; }

.ledger-dash {
	color: var(--color-text-maxcontrast);
}
</style>
