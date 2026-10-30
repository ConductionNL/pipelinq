<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The resource assignments of a booking, a grid widget on the BookingDetail
  - page: resourceAssignments is an array on the booking, and each row names a
  - resource by id, which this table shows by name and type. A person is
  - assigned, a room or equipment is reserved. Times read "14:00"; an
  - assignment on another day than the booking also names its day.
  -
  - Self-fetches the booking by id (passed as `bookingId` via @objectId, with a
  - cnSectionContext inject fallback) and re-reads it on `cn:page:refresh`, which
  - a header action sends after it succeeds.
  -
  - @spec openspec/specs/appointment-booking/spec.md
  -->
<template>
	<div class="booking-assignments">
		<NcLoadingIcon v-if="loading" :size="24" />
		<div v-else-if="!assignments.length" class="section-empty">
			<p>{{ t('pipelinq', 'No resource assignments recorded.') }}</p>
		</div>
		<div v-else class="viewTableContainer">
			<table class="viewTable">
				<thead>
					<tr>
						<th v-if="multiStep" scope="col">{{ t('pipelinq', 'Step') }}</th>
						<th scope="col">{{ t('pipelinq', 'Resource') }}</th>
						<th scope="col">{{ t('pipelinq', 'Type') }}</th>
						<th scope="col">{{ t('pipelinq', 'From') }}</th>
						<th scope="col">{{ t('pipelinq', 'To') }}</th>
						<th scope="col">{{ t('pipelinq', 'Status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(a, idx) in assignments" :key="idx">
						<td v-if="multiStep">{{ (a.stepIndex ?? 0) + 1 }}</td>
						<td>{{ resourceName(a.resourceId) }}</td>
						<td>{{ resourceTypeLabel(a.resourceId) }}</td>
						<td>{{ formatTime(a.startAt) }}</td>
						<td>{{ formatTime(a.endAt) }}</td>
						<td>
							<CnStatusBadge
								:label="isPerson(a.resourceId) ? t('pipelinq', 'Assigned') : t('pipelinq', 'Reserved')"
								:variant="isPerson(a.resourceId) ? 'success' : 'info'" />
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { showError } from '@nextcloud/dialogs'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { NcLoadingIcon } from '@nextcloud/vue'
import { getUserLocale } from '../../services/localeUtils.js'
import { useObjectStore } from '../../store/modules/object.js'
import { timeOfDay } from '../../utils/bookingTime.js'

export default {
	name: 'BookingAssignments',
	components: { CnStatusBadge, NcLoadingIcon },
	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The booking id (`@objectId`). */
		bookingId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			booking: {},
			loading: false,
			resourceLookup: {},
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		/**
		 * The booking id, from the prop or the section context.
		 *
		 * @return {string}
		 */
		resolvedId() {
			if (this.bookingId) {
				return this.bookingId
			}
			const ctx = this.cnSectionContext
			const bag = ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (bag && bag.objectId) || ''
		},

		/**
		 * The booking's resource assignments.
		 *
		 * @return {Array<object>}
		 */
		assignments() {
			return Array.isArray(this.booking.resourceAssignments) ? this.booking.resourceAssignments : []
		},

		/**
		 * Whether the assignments span more than one step of the service.
		 *
		 * @return {boolean}
		 */
		multiStep() {
			return new Set(this.assignments.map((a) => a?.stepIndex ?? 0)).size > 1
		},
	},

	watch: {
		resolvedId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	/**
	 * Re-read the booking on `cn:page:refresh`, which a header action sends.
	 *
	 * @spec openspec/specs/appointment-booking/spec.md
	 */
	mounted() {
		this.onPageRefresh = (payload) => {
			const done = this.load({ silent: true })
			payload?.waitUntil?.(done)
		}
		subscribe('cn:page:refresh', this.onPageRefresh)
	},

	beforeUnmount() {
		unsubscribe('cn:page:refresh', this.onPageRefresh)
	},

	methods: {
		/**
		 * The time of day ("14:00"), with the day in front when it is not the
		 * booking's own day.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string}
		 */
		formatTime(iso) {
			const time = timeOfDay(iso)
			if (time === '') return '-'
			const day = new Date(iso).toDateString()
			const bookingDay = this.booking.startAt ? new Date(this.booking.startAt).toDateString() : day
			if (day === bookingDay) return time
			return new Intl.DateTimeFormat(getUserLocale(), { day: 'numeric', month: 'short' }).format(new Date(iso)) + ', ' + time
		},

		/**
		 * The resource's type in words ("Staff", "Room", "Equipment").
		 *
		 * @param {string} resourceId The resource id.
		 * @return {string}
		 */
		resourceTypeLabel(resourceId) {
			const labels = { staff: t('pipelinq', 'Staff'), room: t('pipelinq', 'Room'), equipment: t('pipelinq', 'Equipment') }
			return labels[this.resourceLookup[resourceId]?.type] || '-'
		},

		/**
		 * Whether the resource is a person, who is assigned rather than reserved.
		 *
		 * @param {string} resourceId The resource id.
		 * @return {boolean}
		 */
		isPerson(resourceId) {
			return this.resourceLookup[resourceId]?.type === 'staff'
		},

		/**
		 * A resource's name, or its id while the name is not known.
		 *
		 * @param {string} resourceId The resource id.
		 * @return {string}
		 */
		resourceName(resourceId) {
			if (!resourceId) return '-'
			return this.resourceLookup[resourceId]?.name || resourceId
		},

		/**
		 * Load the booking, then the names of its resources.
		 *
		 * @param {{silent?: boolean}} [opts] `silent` skips the spinner (a refresh).
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		async load(opts = {}) {
			if (!this.resolvedId) {
				return
			}
			if (opts.silent !== true) {
				this.loading = true
			}
			try {
				this.booking = (await this.objectStore.fetchObject('appointmentBooking', this.resolvedId)) || {}
				await this.loadResourceNames()
			} catch (err) {
				showError(err?.response?.data?.error || t('pipelinq', 'Could not load booking.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Read the name and type of every resource the assignments name, once per id.
		 * A resource that cannot be read keeps its id.
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		async loadResourceNames() {
			// A booking has one assignment row per step, so a resource recurs.
			const ids = [...new Set(this.assignments.map((a) => a?.resourceId).filter(Boolean))]
				.filter((id) => !this.resourceLookup[id])
			for (const id of ids) {
				const resource = await this.objectStore.fetchObject('appointmentResource', id)
				if (resource?.name) {
					this.resourceLookup = { ...this.resourceLookup, [id]: { name: resource.name, type: resource.type } }
				}
			}
		},
	},
}
</script>

<style scoped>
.viewTableContainer {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	overflow-x: auto;
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}

.viewTable th,
.viewTable td {
	padding: 12px;
	text-align: start;
	vertical-align: middle;
	border-bottom: 1px solid var(--color-border);
}

.viewTable th {
	padding-block: 10px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.viewTable th:first-child,
.viewTable td:first-child {
	padding-inline-start: 18px;
}

.viewTable th:last-child,
.viewTable td:last-child {
	padding-inline-end: 18px;
}

.viewTable tbody tr:last-child td {
	border-bottom: 0;
}

.section-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
}
</style>
