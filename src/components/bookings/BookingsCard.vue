<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  BookingsCard — customer timeline of bookings (REQ-APT-014).

  Props:
    customerId (string, required) — UUID of the customer whose bookings to
      list. The card fetches via objectStore.fetchCollection('booking',
      { customerId, _limit: 200 }) and sorts future-first (upcoming bookings
      bubble to the top; past bookings follow in reverse-chronological order).

  Each row shows service / resource / date+time / status badge and links to
  the full booking detail. An empty state renders when the customer has no
  bookings; an inline error state renders when the fetch fails (so the
  customer page never crashes on a relation lookup — REQ-KB360-006).

  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
  @spec openspec/specs/appointment-booking/spec.md
-->
<template>
	<CnDetailCard :title="t('pipelinq', 'Bookings')">
		<div v-if="loading" class="bookings-card__state">
			<NcLoadingIcon :size="24" />
		</div>
		<div
			v-else-if="error"
			class="bookings-card__state bookings-card__state--error">
			<p>{{ error }}</p>
		</div>
		<div v-else-if="!sortedBookings.length" class="bookings-card__state">
			<p>{{ t('pipelinq', 'No bookings for this customer.') }}</p>
		</div>
		<div v-else class="viewTableContainer">
			<table class="viewTable">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Date / time') }}</th>
						<th scope="col">{{ t('pipelinq', 'Service') }}</th>
						<th scope="col">{{ t('pipelinq', 'Resource') }}</th>
						<th scope="col">{{ t('pipelinq', 'Status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in sortedBookings"
						:key="row.id"
						class="viewTableRow"
						@click="open(row)">
						<td>{{ formatDateTime(row.startAt) }}</td>
						<td>{{ serviceLabel(row) }}</td>
						<td>{{ resourceLabel(row) }}</td>
						<td>
							<span
								class="status-badge"
								:class="`status-badge--${row.status}`">
								{{ statusLabel(row.status) }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</CnDetailCard>
</template>

<script>
import { CnDetailCard } from '@conduction/nextcloud-vue'
import { NcLoadingIcon } from '@nextcloud/vue'
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
	name: 'BookingsCard',
	components: { NcLoadingIcon, CnDetailCard },
	props: {
		customerId: { type: String, required: true },
	},

	data() {
		return {
			loading: false,
			error: '',
			bookings: [],
			serviceLookup: {},
			resourceLookup: {},
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		/**
		 * Future-first sort: upcoming bookings (startAt > now) first in
		 * ascending order, then past bookings in descending order so the
		 * most relevant rows surface at the top of the card.
		 *
		 * @return {Array<object>} Sorted bookings.
		 */
		sortedBookings() {
			const now = Date.now()
			const future = []
			const past = []
			for (const b of this.bookings) {
				const t = b?.startAt ? Date.parse(b.startAt) : 0
				if (t >= now) {
					future.push({ ...b, _ts: t })
				} else {
					past.push({ ...b, _ts: t })
				}
			}
			future.sort((a, b) => a._ts - b._ts)
			past.sort((a, b) => b._ts - a._ts)
			return [...future, ...past]
		},
	},

	watch: {
		customerId: {
			immediate: true,
			handler(val) {
				if (val) {
					this.fetchBookings()
				}
			},
		},
	},

	methods: {
		/**
		 * Fetch bookings for the customer and prime the service / resource
		 * label caches in parallel. Per-fetch failures are tolerated; a
		 * full fetch failure surfaces an inline error so the surrounding
		 * customer page never blanks out (REQ-KB360-006).
		 *
		 * @return {Promise<void>}
		 */
		async fetchBookings() {
			this.loading = true
			this.error = ''
			try {
				const rows = await this.objectStore.fetchCollection('booking', {
					customerId: this.customerId,
					_limit: 200,
				})
				this.bookings = Array.isArray(rows) ? rows : []
				await this.primeLabels()
			} catch {
				this.bookings = []
				this.error = t(
					'pipelinq',
					'Failed to load bookings for this customer.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
		 */
		async primeLabels() {
			const serviceIds = [
				...new Set(this.bookings.map((b) => b.serviceId).filter(Boolean)),
			]
			const resourceIds = [
				...new Set(
					this.bookings.flatMap((b) =>
						(b.resourceAssignments || [])
							.map((a) => a?.resourceId)
							.filter(Boolean),
					),
				),
			]
			for (const id of serviceIds) {
				if (this.serviceLookup[id]) continue
				try {
					const svc = await this.objectStore.fetchObject('service', id)
					if (svc?.name) {
						this.serviceLookup = {
							...this.serviceLookup,
							[id]: svc.name,
						}
					}
				} catch {
					/* tolerated */
				}
			}
			for (const id of resourceIds) {
				if (this.resourceLookup[id]) continue
				try {
					const r = await this.objectStore.fetchObject('resource', id)
					if (r?.name) {
						this.resourceLookup = {
							...this.resourceLookup,
							[id]: r.name,
						}
					}
				} catch {
					/* tolerated */
				}
			}
		},

		open(row) {
			this.$router.push({ name: 'BookingDetail', params: { id: row.id } })
		},

		serviceLabel(row) {
			return this.serviceLookup[row.serviceId] || row.serviceId || '-'
		},

		resourceLabel(row) {
			const first = (row.resourceAssignments || [])[0]
			const id = first?.resourceId
			if (!id) return '-'
			return this.resourceLookup[id] || id
		},

		statusLabel(status) {
			return t('pipelinq', STATUS_LABELS[status] || status || '-')
		},

		formatDateTime(iso) {
			if (!iso) return '-'
			try {
				return new Date(iso).toLocaleString('nl-NL')
			} catch {
				return iso
			}
		},
	},
}
</script>

<style scoped>
.bookings-card__state {
	padding: 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.bookings-card__state--error {
	color: var(--color-error);
}

.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
}

.viewTable th,
.viewTable td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.viewTableRow {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background: var(--color-background-hover);
}

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

@media (prefers-reduced-motion: reduce) {
	.viewTableRow {
		transition: none;
	}
}
</style>
