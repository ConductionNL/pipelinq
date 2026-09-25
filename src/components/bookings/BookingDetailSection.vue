<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Booking in-body section (kind:'section') for the declarative type:"detail"
  - BookingDetail page (pipelinq-pos-mdm-detail-declarative). The booking's flat
  - identity fields (status / source / startAt / endAt / depositAmount / …)
  - auto-render in the detail-page body via CnObjectDataWidget; this section adds
  - the parts the auto-body + relatedCollections cannot express:
  -   1. the inline notes / internalNotes editor (objectStore save);
  -   2. resourceAssignments + statusHistory — ARRAY fields ON the booking (not FK
  -      children), with cross-schema id->name resolution for resources;
  -   3. the computed Timeline (a merge of timestamp fields);
  -   4. human-readable Service / Customer names resolved across other schemas.
  -
  - The admin actions live in the page header (BookingHeaderActions).
  -
  - Self-fetches the booking by id (passed as `bookingId` via @objectId, with a
  - cnSectionContext inject fallback) and re-reads it on `cn:page:refresh`, which
  - a header action sends after it succeeds.
  -
  - @spec openspec/specs/appointment-booking/spec.md
  -->
<template>
	<div class="booking-section">
		<NcLoadingIcon v-if="loading" :size="24" />
		<template v-else>
			<section class="booking-section__block">
				<h4>{{ t('pipelinq', 'Context') }}</h4>
				<div class="info-grid">
					<div class="info-field">
						<label>{{ t('pipelinq', 'Service') }}</label>
						<span>{{ serviceName }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Customer') }}</label>
						<span>{{ customerLabel }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Deposit') }}</label>
						<span>{{ depositLabel }}</span>
					</div>
					<div v-if="booking.previousBookingId" class="info-field">
						<label>{{ t('pipelinq', 'Rescheduled from') }}</label>
						<span>
							<a href="#" @click.prevent="openPrevious">
								{{ booking.previousBookingId }}
							</a>
						</span>
					</div>
				</div>
			</section>

			<section class="booking-section__block">
				<h4>{{ t('pipelinq', 'Notes') }}</h4>
				<div class="form-group">
					<label for="booking-notes">{{
						t('pipelinq', 'Customer-facing notes')
					}}</label>
					<textarea
						id="booking-notes"
						v-model="editableNotes"
						rows="3"
						maxlength="4000" />
				</div>
				<div class="form-group">
					<label for="booking-internal-notes">{{
						t('pipelinq', 'Internal staff notes')
					}}</label>
					<textarea
						id="booking-internal-notes"
						v-model="editableInternalNotes"
						rows="3"
						maxlength="4000" />
				</div>
				<div class="notes-actions">
					<NcButton
						variant="primary"
						:disabled="busy || !notesDirty"
						@click="saveNotes">
						{{ t('pipelinq', 'Save notes') }}
					</NcButton>
				</div>
			</section>

			<section class="booking-section__block">
				<h4>{{ t('pipelinq', 'Resource assignments') }}</h4>
				<div v-if="!assignments.length" class="section-empty">
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
			</section>

			<section class="booking-section__block">
				<h4>{{ t('pipelinq', 'Audit trail') }}</h4>
				<div v-if="!history.length" class="section-empty">
					<p>{{ t('pipelinq', 'No status changes recorded.') }}</p>
				</div>
				<div v-else class="viewTableContainer">
					<table class="viewTable">
						<thead>
							<tr>
								<th scope="col">{{ t('pipelinq', 'Status') }}</th>
								<th scope="col">
									{{ t('pipelinq', 'Changed at') }}
								</th>
								<th scope="col">
									{{ t('pipelinq', 'Changed by') }}
								</th>
								<th scope="col">{{ t('pipelinq', 'Reason') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(entry, idx) in history" :key="idx">
								<td>{{ statusLabel(entry.status) }}</td>
								<td>{{ formatDateTime(entry.changedAt) }}</td>
								<td>{{ entry.changedBy || '-' }}</td>
								<td>{{ entry.reason || '-' }}</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<section class="booking-section__block">
				<h4>{{ t('pipelinq', 'Timeline') }}</h4>
				<p v-if="!timeline.length" class="section-empty">
					{{ t('pipelinq', 'No events yet.') }}
				</p>
				<ol v-else class="booking-timeline">
					<li
						v-for="(event, idx) in timelineWithNow"
						:key="idx"
						class="booking-timeline__event"
						:class="[
							`booking-timeline__event--${event.kind}`,
							{ 'booking-timeline__event--upcoming': event.upcoming },
						]">
						<span class="booking-timeline__marker" aria-hidden="true">
							<component :is="event.icon" v-if="event.icon" :size="16" />
						</span>
						<div class="booking-timeline__body">
							<span class="booking-timeline__text">{{ event.text }}</span>
							<time
								v-if="event.at"
								class="booking-timeline__when"
								:datetime="event.at">
								{{ formatDateTime(event.at) }}
							</time>
						</div>
					</li>
				</ol>
			</section>
		</template>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import CalendarRemove from 'vue-material-design-icons/CalendarRemove.vue'
import CashCheck from 'vue-material-design-icons/CashCheck.vue'
import CashRemove from 'vue-material-design-icons/CashRemove.vue'
import ClockEnd from 'vue-material-design-icons/ClockEnd.vue'
import ClockStart from 'vue-material-design-icons/ClockStart.vue'
import EmailCheckOutline from 'vue-material-design-icons/EmailCheckOutline.vue'
import { useObjectStore } from '../../store/modules/object.js'

const TIMELINE_ICONS = {
	start: ClockStart,
	end: ClockEnd,
	email: EmailCheckOutline,
	deposit: CashCheck,
	fee: CashRemove,
	cancel: CalendarRemove,
}

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
	name: 'BookingDetailSection',
	components: {
		NcButton,
		NcLoadingIcon,
	},

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The booking id (token-resolved from `@objectId` by CnBodySections). */
		bookingId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			booking: {},
			loading: false,
			busy: false,
			editableNotes: '',
			editableInternalNotes: '',
			savedNotes: '',
			savedInternalNotes: '',
			resourceLookup: {},
			service: null,
			customer: null,
			loadedCustomerId: null,
			contextLoading: false,
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		/** The resolved booking id — prop wins, else the injected section context. */
		resolvedId() {
			if (this.bookingId) {
				return this.bookingId
			}
			const ctx = this.cnSectionContext
			const bag =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (bag && bag.objectId) || ''
		},

		serviceName() {
			return this.service?.name || this.booking.serviceId || '-'
		},

		customerLabel() {
			if (this.customer?.name) return this.customer.name
			if (this.customer?.fullName) return this.customer.fullName
			return this.booking.customerId || '-'
		},

		assignments() {
			return Array.isArray(this.booking.resourceAssignments)
				? this.booking.resourceAssignments
				: []
		},

		history() {
			const raw = Array.isArray(this.booking.statusHistory)
				? this.booking.statusHistory
				: []
			return [...raw].sort((a, b) => {
				const ta = a?.changedAt ? Date.parse(a.changedAt) : 0
				const tb = b?.changedAt ? Date.parse(b.changedAt) : 0
				return ta - tb
			})
		},

		timeline() {
			const events = []
			if (this.booking.startAt) {
				events.push({
					at: this.booking.startAt,
					kind: 'start',
					text: t('pipelinq', 'Booking starts'),
				})
			}
			if (this.booking.endAt) {
				events.push({
					at: this.booking.endAt,
					kind: 'end',
					text: t('pipelinq', 'Booking ends'),
				})
			}
			if (this.booking.confirmationSentAt) {
				events.push({
					at: this.booking.confirmationSentAt,
					kind: 'email',
					text: t('pipelinq', 'Confirmation email sent'),
				})
			}
			if (this.booking.reminderSentAt) {
				events.push({
					at: this.booking.reminderSentAt,
					kind: 'email',
					text: t('pipelinq', 'Reminder email sent'),
				})
			}
			if (this.booking.depositPaidAt) {
				events.push({
					at: this.booking.depositPaidAt,
					kind: 'deposit',
					text: t('pipelinq', 'Deposit cleared'),
				})
			}
			if (this.booking.noShowFeeChargedAt) {
				events.push({
					at: this.booking.noShowFeeChargedAt,
					kind: 'fee',
					text: t('pipelinq', 'No-show fee charged'),
				})
			}
			if (this.booking.cancelledAt) {
				events.push({
					at: this.booking.cancelledAt,
					kind: 'cancel',
					text: t('pipelinq', 'Cancelled'),
				})
			}
			return events.sort((a, b) => Date.parse(a.at) - Date.parse(b.at))
		},

		/**
		 * The timeline as rendered: each event with its icon and whether it is
		 * still ahead, plus a "Now" marker between the past and the upcoming
		 * events when the booking has both.
		 *
		 * @return {Array<{at?: string, kind: string, text: string, icon: object|null, upcoming: boolean}>}
		 */
		timelineWithNow() {
			const now = Date.now()
			const events = this.timeline.map((e) => ({
				...e,
				icon: TIMELINE_ICONS[e.kind] || null,
				upcoming: Date.parse(e.at) > now,
			}))
			const firstUpcoming = events.findIndex((e) => e.upcoming)
			if (firstUpcoming > 0) {
				events.splice(firstUpcoming, 0, {
					kind: 'now',
					text: t('pipelinq', 'Now'),
					icon: null,
					upcoming: false,
				})
			}
			return events
		},

		depositLabel() {
			const amount = Number(this.booking.depositAmount || 0)
			if (!amount) return t('pipelinq', 'None')
			const paid = !!this.booking.depositPaidAt
			const formatted = this.formatCurrency(
				amount,
				this.service?.currency || 'EUR',
			)
			return paid
				? t('pipelinq', '{amount} (paid {when})', {
						amount: formatted,
						when: this.formatDateTime(this.booking.depositPaidAt),
					})
				: t('pipelinq', '{amount} (pending)', { amount: formatted })
		},

		notesDirty() {
			return (
				this.editableNotes !== this.savedNotes
				|| this.editableInternalNotes !== this.savedInternalNotes
			)
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

	mounted() {
		// A header action changed the booking; re-read it without the spinner.
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

		resourceName(resourceId) {
			if (!resourceId) return '-'
			return this.resourceLookup[resourceId] || resourceId
		},

		/**
		 * Load the booking and seed the notes editor, then resolve the linked
		 * Service / Customer / Resource names.
		 *
		 * @param {{silent?: boolean}} [opts] `silent` skips the spinner and
		 *  keeps unsaved note edits (a refresh, not a first load).
		 */
		async load(opts = {}) {
			if (!this.resolvedId) {
				return
			}
			const silent = opts.silent === true
			const keepNotes = silent && this.notesDirty
			if (!silent) {
				this.loading = true
			}
			try {
				this.booking =
					(await this.objectStore.fetchObject(
						'appointmentBooking',
						this.resolvedId,
					)) || {}
				if (!keepNotes) {
					this.editableNotes = this.booking.notes || ''
					this.editableInternalNotes = this.booking.internalNotes || ''
				}
				this.savedNotes = this.booking.notes || ''
				this.savedInternalNotes = this.booking.internalNotes || ''
				await this.loadContext()
			} catch (err) {
				showError(
					err?.response?.data?.error
						|| t('pipelinq', 'Could not load booking.'),
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch the linked Service / Customer / Resource records so the
		 * section can display human-readable names instead of UUIDs.
		 * Failures are silent — it falls back to UUIDs.
		 *
		 * Guarded against re-entrancy and redundant fetches: the `booking`
		 * watcher can fire repeatedly (e.g. after an action re-fetches the
		 * booking), so each linked record is only resolved once.
		 */
		async loadContext() {
			if (this.contextLoading) return
			this.contextLoading = true
			try {
				const booking = this.booking
				if (booking.serviceId && !this.service) {
					this.service = await this.objectStore.fetchObject(
						'appointmentService',
						booking.serviceId,
					)
				}
				// Resolve the customer once per id. fetchObject returns null
				// (it does not throw) on a 404, so fall back to `client` on a
				// null contact rather than in a catch — and record the id so a
				// genuinely missing customer is not refetched on every run.
				if (
					booking.customerId
					&& this.loadedCustomerId !== booking.customerId
				) {
					this.loadedCustomerId = booking.customerId
					const contact = await this.objectStore.fetchObject(
						'contact',
						booking.customerId,
					)
					this.customer =
						contact
						|| (await this.objectStore.fetchObject(
							'client',
							booking.customerId,
						))
				}
				// De-duplicate resource ids: a booking has one assignment row
				// per step/time-slot, so the same resource recurs across rows.
				const resourceIds = [
					...new Set(
						(this.assignments || [])
							.map((a) => a?.resourceId)
							.filter(Boolean),
					),
				].filter((id) => !this.resourceLookup[id])
				for (const id of resourceIds) {
					const resource = await this.objectStore.fetchObject(
						'appointmentResource',
						id,
					)
					if (resource?.name) {
						this.resourceLookup = {
							...this.resourceLookup,
							[id]: resource.name,
						}
					}
				}
			} finally {
				this.contextLoading = false
			}
		},

		async saveNotes() {
			this.busy = true
			try {
				const payload = {
					id: this.resolvedId,
					notes: this.editableNotes,
					internalNotes: this.editableInternalNotes,
				}
				const saved = await this.objectStore.saveObject(
					'appointmentBooking',
					payload,
				)
				if (saved) {
					this.savedNotes = this.editableNotes
					this.savedInternalNotes = this.editableInternalNotes
					showSuccess(t('pipelinq', 'Notes saved.'))
				} else {
					showError(t('pipelinq', 'Failed to save notes.'))
				}
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.booking-section {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.booking-section__block h4 {
	margin: 0 0 8px;
	font-weight: 600;
}

.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.info-field {
	margin-bottom: 8px;
}

.info-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	font-weight: bold;
	margin-bottom: 4px;
}

.form-group textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.notes-actions {
	display: flex;
	justify-content: flex-end;
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

/* Vertical timeline: a marker per event on a continuous rail. Colour tells the
   kind of event, a dashed marker tells an event that is still ahead. */
.booking-timeline {
	--booking-timeline-marker: 28px;
	--booking-timeline-gap: calc(var(--default-grid-baseline) * 4);
	margin: 0;
	padding: 0;
	list-style: none;
}

.booking-timeline__event {
	position: relative;
	display: flex;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline) * 3);
	padding-bottom: var(--booking-timeline-gap);
}

.booking-timeline__event:last-child {
	padding-bottom: 0;
}

/* The rail segment from this marker down to the next one. */
.booking-timeline__event:not(:last-child)::before {
	content: '';
	position: absolute;
	inset-block: var(--booking-timeline-marker) 0;
	inset-inline-start: calc(var(--booking-timeline-marker) / 2 - 1px);
	width: 2px;
	background-color: var(--color-border);
}

.booking-timeline__marker {
	flex: 0 0 var(--booking-timeline-marker);
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: var(--booking-timeline-marker);
	height: var(--booking-timeline-marker);
	border: 2px solid transparent;
	border-radius: 50%;
	background-color: var(--color-background-dark);
	color: var(--color-main-text);
}

.booking-timeline__event--start .booking-timeline__marker,
.booking-timeline__event--end .booking-timeline__marker {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.booking-timeline__event--deposit .booking-timeline__marker {
	background-color: color-mix(in srgb, var(--color-success) 18%, var(--color-main-background));
	color: var(--color-success-text);
}

.booking-timeline__event--fee .booking-timeline__marker,
.booking-timeline__event--cancel .booking-timeline__marker {
	background-color: color-mix(in srgb, var(--color-error) 15%, var(--color-main-background));
	color: var(--color-error-text);
}

.booking-timeline__event--upcoming .booking-timeline__marker {
	border-style: dashed;
	border-color: currentColor;
	background-color: var(--color-main-background);
}

.booking-timeline__body {
	display: flex;
	flex-direction: column;
	min-height: var(--booking-timeline-marker);
	justify-content: center;
}

.booking-timeline__text {
	font-weight: 500;
}

.booking-timeline__event--upcoming .booking-timeline__text {
	color: var(--color-text-maxcontrast);
}

.booking-timeline__when {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-small, 13px);
	font-variant-numeric: tabular-nums;
}

/* "Now": a small dot on the rail and a quiet label. */
.booking-timeline__event--now .booking-timeline__marker {
	background-color: transparent;
}

.booking-timeline__event--now .booking-timeline__marker::after {
	content: '';
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background-color: var(--color-primary-element);
	box-shadow: 0 0 0 4px var(--color-main-background);
}

.booking-timeline__event--now .booking-timeline__text {
	color: var(--color-primary-element-text-dark, var(--color-primary-element));
	font-size: var(--font-size-small, 13px);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}
</style>
