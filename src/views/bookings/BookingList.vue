<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Booking list — appointment-booking member 11.

  Columns: customer, service, resource, date/time, status, source.
  Filters: date range / status / resource / service / source (via
  useListView's standard schema-aware filter set).
  Row click opens the booking detail. There is no "Add" — admins create
  bookings through the public portal flow on a customer's behalf.

  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Bookings')"
			:description="t('pipelinq', 'Customer appointments — view, reschedule, cancel and act on bookings')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:refreshing="refreshing"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No bookings yet')"
			@refresh="onRefresh"
			@sort="onSort"
			@view="openBooking"
			@page-changed="onPageChange">
			<template #cell-startAt="{ row }">
				{{ formatDateTime(row.startAt) }}
			</template>
			<template #cell-status="{ row }">
				<span class="status-badge" :class="`status-badge--${row.status}`">
					{{ statusLabel(row.status) }}
				</span>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

const STATUS_LABELS = {
	'pending-deposit': 'Awaiting deposit',
	confirmed: 'Confirmed',
	completed: 'Completed',
	'no-show': 'No-show',
	'cancelled-by-customer': 'Cancelled (customer)',
	'cancelled-by-business': 'Cancelled (business)',
	rescheduled: 'Rescheduled',
}

export default {
	name: 'BookingList',
	components: { CnIndexPage },
	/**
	 * @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
	 */
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('booking', { sidebarState, objectStore })
	},
	data() {
		return {
			refreshing: false,
		}
	},
	computed: {
		visibleColumns() {
			return ['customerId', 'serviceId', 'startAt', 'status', 'source']
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
		openBooking(row) {
			this.$router.push({ name: 'BookingDetail', params: { id: row.id } })
		},
		formatDateTime(iso) {
			if (!iso) return '-'
			try {
				return new Date(iso).toLocaleString('nl-NL')
			} catch {
				return iso
			}
		},
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
	white-space: nowrap;
}
.status-badge--confirmed {
	background: var(--color-success);
	color: white;
}
.status-badge--completed {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}
.status-badge--no-show {
	background: var(--color-error);
	color: white;
}
.status-badge--pending-deposit {
	background: var(--color-warning);
	color: black;
}
.status-badge--cancelled-by-customer,
.status-badge--cancelled-by-business,
.status-badge--rescheduled {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}
</style>
