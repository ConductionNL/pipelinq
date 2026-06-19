<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Service catalogue list — appointment-booking member 11.
  Columns: name, duration, price, status; filters by status + bookableOnline.
  Row click opens the detail; "Add" routes to the new-record page.
  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Services')"
			:description="t('pipelinq', 'Bookable services with duration, price and online-booking policies')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:refreshing="refreshing"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No services yet')"
			:empty-action-label="t('pipelinq', 'New service')"
			:add-label="t('pipelinq', 'New service')"
			@add="createNew"
			@empty-action="createNew"
			@refresh="onRefresh"
			@sort="onSort"
			@view="openService"
			@page-changed="onPageChange">
			<template #cell-durationMinutes="{ row }">
				{{ formatDuration(row.durationMinutes) }}
			</template>
			<template #cell-price="{ row }">
				{{ formatCurrency(row.price, row.currency) }}
			</template>
			<template #cell-status="{ row }">
				<span class="status-badge" :class="`status-badge--${row.status}`">
					{{ statusLabel(row.status) }}
				</span>
			</template>
			<template #cell-bookableOnline="{ row }">
				<span v-if="row.bookableOnline" aria-label="bookable online">&#10004;</span>
				<span v-else aria-hidden="true">&nbsp;</span>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

const STATUS_LABELS = {
	draft: 'Draft',
	active: 'Active',
	archived: 'Archived',
}

export default {
	name: 'ServiceList',
	components: {
		CnIndexPage,
	},
	/**
	 * @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
	 */
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('service', { sidebarState, objectStore })
	},
	data() {
		return {
			refreshing: false,
		}
	},
	computed: {
		visibleColumns() {
			return ['name', 'durationMinutes', 'price', 'bookableOnline', 'status']
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
		 * @param {object} row The row clicked.
		 */
		openService(row) {
			this.$router.push({ name: 'ServiceDetail', params: { id: row.id } })
		},
		createNew() {
			this.$router.push({ name: 'ServiceDetail', params: { id: 'new' } })
		},
		/**
		 * Format a duration in minutes as "Xh Ymin" or "Ymin".
		 *
		 * @param {*} minutes Raw duration.
		 * @return {string} Formatted duration.
		 */
		formatDuration(minutes) {
			const n = Number(minutes) || 0
			if (n < 60) {
				return t('pipelinq', '{n} min', { n })
			}
			const h = Math.floor(n / 60)
			const m = n % 60
			if (m === 0) {
				return t('pipelinq', '{n}h', { n: h })
			}
			return t('pipelinq', '{h}h {m}min', { h, m })
		},
		/**
		 * Format a numeric value as a currency string.
		 *
		 * @param {*}      value    Raw amount.
		 * @param {string} currency ISO 4217 code (default EUR).
		 * @return {string} Formatted currency.
		 */
		formatCurrency(value, currency) {
			const code = currency || 'EUR'
			const n = Number(value) || 0
			try {
				return new Intl.NumberFormat('nl-NL', {
					style: 'currency',
					currency: code,
					maximumFractionDigits: 2,
				}).format(n)
			} catch {
				return `${code} ${n}`
			}
		},
		/**
		 * @param {string} status The raw status.
		 * @return {string} The translated label.
		 */
		statusLabel(status) {
			return t('pipelinq', STATUS_LABELS[status] || status || '-')
		},
	},
}
</script>

<style scoped>
.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 12px;
	font-weight: 600;
}
.status-badge--active {
	background: var(--color-success);
	color: white;
}
.status-badge--draft {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
.status-badge--archived {
	background: var(--color-background-darker);
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}
</style>
