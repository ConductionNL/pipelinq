<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The resource assignments of a booking, a grid widget on the BookingDetail
  - page: resourceAssignments is an array on the booking, and each row names a
  - resource by id, which this table shows by name.
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
						<th scope="col">{{ t('pipelinq', 'Step') }}</th>
						<th scope="col">{{ t('pipelinq', 'Resource') }}</th>
						<th scope="col">{{ t('pipelinq', 'Start') }}</th>
						<th scope="col">{{ t('pipelinq', 'End') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(a, idx) in assignments" :key="idx">
						<td>{{ (a.stepIndex ?? 0) + 1 }}</td>
						<td>{{ resourceName(a.resourceId) }}</td>
						<td>{{ formatDateTime(a.startAt) }}</td>
						<td>{{ formatDateTime(a.endAt) }}</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { NcLoadingIcon } from '@nextcloud/vue'
import { getUserLocale } from '../../services/localeUtils.js'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'BookingAssignments',
	components: { NcLoadingIcon },
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
		 * A date and time in the user's locale.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string}
		 */
		formatDateTime(iso) {
			if (!iso) return '-'
			try {
				return new Date(iso).toLocaleString(getUserLocale())
			} catch {
				return iso
			}
		},

		/**
		 * A resource's name, or its id while the name is not known.
		 *
		 * @param {string} resourceId The resource id.
		 * @return {string}
		 */
		resourceName(resourceId) {
			if (!resourceId) return '-'
			return this.resourceLookup[resourceId] || resourceId
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
		 * Read the name of every resource the assignments name, once per id.
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
					this.resourceLookup = { ...this.resourceLookup, [id]: resource.name }
				}
			}
		},
	},
}
</script>

<style scoped>
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
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.section-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
}
</style>
