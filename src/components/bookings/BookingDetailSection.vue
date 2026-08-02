<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Booking in-body section (kind:'section') for the declarative type:"detail"
  - BookingDetail page (pipelinq-pos-mdm-detail-declarative). The booking's flat
  - identity fields (status / source / startAt / endAt / depositAmount / …)
  - auto-render in the detail-page body via CnObjectDataWidget; this section adds
  - the parts the auto-body + relatedCollections cannot express:
  -   1. the six TIME-WINDOW-gated admin actions (Confirm deposit / Mark completed
  -      / Mark no-show / Reschedule / Send reminder / Cancel) which POST to bespoke
  -      /api/bookings/{id}/{action} endpoints with side-effects (emails, no-show
  -      fees) — Reschedule CREATES A NEW booking UUID and navigates to it. These
  -      are NOT plain OR /transition status flips, so CnLifecycleActions cannot
  -      drive them even though booking has an x-openregister-lifecycle;
  -   2. the inline notes / internalNotes editor (objectStore save);
  -   3. resourceAssignments + statusHistory — ARRAY fields ON the booking (not FK
  -      children), with cross-schema id->name resolution for resources;
  -   4. the computed Timeline (a merge of timestamp fields);
  -   5. human-readable Service / Customer names resolved across other schemas.
  -
  - Self-fetches the booking by id (passed as `bookingId` via @objectId, with a
  - cnSectionContext inject fallback) so it stays in sync after an action.
  -
  - @spec openspec/specs/appointment-booking/spec.md
  -->
<template>
	<div class="booking-section">
		<NcLoadingIcon v-if="loading" :size="24" />
		<template v-else>
			<section class="booking-section__actions">
				<NcButton
					v-if="canConfirmDeposit"
					variant="primary"
					:disabled="busy"
					@click="confirmDeposit">
					{{ t('pipelinq', 'Confirm deposit') }}
				</NcButton>
				<NcButton
					v-if="canMarkCompleted"
					variant="primary"
					:disabled="busy"
					@click="markCompleted">
					{{ t('pipelinq', 'Mark completed') }}
				</NcButton>
				<NcButton
					v-if="canMarkNoShow"
					variant="error"
					:disabled="busy"
					@click="markNoShow">
					{{ t('pipelinq', 'Mark no-show') }}
				</NcButton>
				<NcButton
					v-if="canReschedule"
					variant="secondary"
					:disabled="busy"
					@click="showReschedule = true">
					{{ t('pipelinq', 'Reschedule') }}
				</NcButton>
				<NcButton
					v-if="canSendReminder"
					variant="secondary"
					:disabled="busy"
					@click="sendReminder">
					{{ t('pipelinq', 'Send reminder') }}
				</NcButton>
				<NcButton
					v-if="canCancel"
					variant="error"
					:disabled="busy"
					@click="showCancel = true">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
			</section>

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
					<label for="booking-notes">{{ t('pipelinq', 'Customer-facing notes') }}</label>
					<textarea id="booking-notes"
						v-model="editableNotes"
						rows="3"
						maxlength="4000" />
				</div>
				<div class="form-group">
					<label for="booking-internal-notes">{{ t('pipelinq', 'Internal staff notes') }}</label>
					<textarea id="booking-internal-notes"
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
								<th>{{ t('pipelinq', 'Step') }}</th>
								<th>{{ t('pipelinq', 'Resource') }}</th>
								<th>{{ t('pipelinq', 'Start') }}</th>
								<th>{{ t('pipelinq', 'End') }}</th>
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
								<th>{{ t('pipelinq', 'Status') }}</th>
								<th>{{ t('pipelinq', 'Changed at') }}</th>
								<th>{{ t('pipelinq', 'Changed by') }}</th>
								<th>{{ t('pipelinq', 'Reason') }}</th>
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
				<ol class="timeline">
					<li v-for="(event, idx) in timeline" :key="idx" :class="`timeline-${event.kind}`">
						<span class="timeline-when">{{ formatDateTime(event.at) }}</span>
						<span class="timeline-text">{{ event.text }}</span>
					</li>
				</ol>
			</section>

			<RescheduleBookingDialog
				v-if="showReschedule"
				:current-start-at="booking.startAt || ''"
				@confirm="onReschedule"
				@cancel="showReschedule = false" />

			<CancelBookingDialog
				v-if="showCancel"
				@confirm="onCancel"
				@cancel="showCancel = false" />
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import RescheduleBookingDialog from '../../dialogs/RescheduleBookingDialog.vue'
import CancelBookingDialog from '../../dialogs/CancelBookingDialog.vue'
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

const HOUR_MS = 60 * 60 * 1000

export default {
	name: 'BookingDetailSection',
	components: {
		NcButton,
		NcLoadingIcon,
		RescheduleBookingDialog,
		CancelBookingDialog,
	},
	inject: {
		cnSectionContext: { default: null },
	},
	props: {
		/** The booking id (token-resolved from @objectId by CnBodySections). */
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
			showReschedule: false,
			showCancel: false,
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
			const bag = (ctx && typeof ctx === 'object' && 'value' in ctx) ? ctx.value : ctx
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
			return Array.isArray(this.booking.resourceAssignments) ? this.booking.resourceAssignments : []
		},
		history() {
			const raw = Array.isArray(this.booking.statusHistory) ? this.booking.statusHistory : []
			return [...raw].sort((a, b) => {
				const ta = a?.changedAt ? Date.parse(a.changedAt) : 0
				const tb = b?.changedAt ? Date.parse(b.changedAt) : 0
				return ta - tb
			})
		},
		timeline() {
			const events = []
			if (this.booking.startAt) {
				events.push({ at: this.booking.startAt, kind: 'start', text: t('pipelinq', 'Booking starts') })
			}
			if (this.booking.endAt) {
				events.push({ at: this.booking.endAt, kind: 'end', text: t('pipelinq', 'Booking ends') })
			}
			if (this.booking.confirmationSentAt) {
				events.push({ at: this.booking.confirmationSentAt, kind: 'email', text: t('pipelinq', 'Confirmation email sent') })
			}
			if (this.booking.reminderSentAt) {
				events.push({ at: this.booking.reminderSentAt, kind: 'email', text: t('pipelinq', 'Reminder email sent') })
			}
			if (this.booking.depositPaidAt) {
				events.push({ at: this.booking.depositPaidAt, kind: 'payment', text: t('pipelinq', 'Deposit cleared') })
			}
			if (this.booking.noShowFeeChargedAt) {
				events.push({ at: this.booking.noShowFeeChargedAt, kind: 'payment', text: t('pipelinq', 'No-show fee charged') })
			}
			if (this.booking.cancelledAt) {
				events.push({ at: this.booking.cancelledAt, kind: 'cancel', text: t('pipelinq', 'Cancelled') })
			}
			return events.sort((a, b) => Date.parse(a.at) - Date.parse(b.at))
		},
		depositLabel() {
			const amount = Number(this.booking.depositAmount || 0)
			if (!amount) return t('pipelinq', 'None')
			const paid = !!this.booking.depositPaidAt
			const formatted = this.formatCurrency(amount, this.service?.currency || 'EUR')
			return paid
				? t('pipelinq', '{amount} (paid {when})', { amount: formatted, when: this.formatDateTime(this.booking.depositPaidAt) })
				: t('pipelinq', '{amount} (pending)', { amount: formatted })
		},
		notesDirty() {
			return this.editableNotes !== this.savedNotes
				|| this.editableInternalNotes !== this.savedInternalNotes
		},
		isFuture() {
			if (!this.booking.endAt) return false
			return new Date(this.booking.endAt).getTime() > Date.now()
		},
		isPast() {
			if (!this.booking.endAt) return false
			return new Date(this.booking.endAt).getTime() <= Date.now()
		},
		hourAway() {
			if (!this.booking.startAt) return false
			return new Date(this.booking.startAt).getTime() - Date.now() > HOUR_MS
		},
		canConfirmDeposit() {
			return this.booking.status === 'pending-deposit'
		},
		canMarkCompleted() {
			return this.booking.status === 'confirmed' && this.isPast
		},
		canMarkNoShow() {
			return this.booking.status === 'confirmed' && this.isPast
		},
		canReschedule() {
			return ['confirmed', 'pending-deposit'].includes(this.booking.status) && this.isFuture
		},
		canCancel() {
			return ['confirmed', 'pending-deposit'].includes(this.booking.status) && this.isFuture
		},
		canSendReminder() {
			return this.booking.status === 'confirmed' && this.isFuture && this.hourAway
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
		 */
		async load() {
			if (!this.resolvedId) {
				return
			}
			this.loading = true
			try {
				this.booking = await this.objectStore.fetchObject('booking', this.resolvedId) || {}
				this.editableNotes = this.booking.notes || ''
				this.savedNotes = this.booking.notes || ''
				this.editableInternalNotes = this.booking.internalNotes || ''
				this.savedInternalNotes = this.booking.internalNotes || ''
				await this.loadContext()
			} catch (err) {
				showError(err?.response?.data?.error || t('pipelinq', 'Could not load booking.'))
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
					this.service = await this.objectStore.fetchObject('service', booking.serviceId)
				}
				// Resolve the customer once per id. fetchObject returns null
				// (it does not throw) on a 404, so fall back to `client` on a
				// null contact rather than in a catch — and record the id so a
				// genuinely missing customer is not refetched on every run.
				if (booking.customerId && this.loadedCustomerId !== booking.customerId) {
					this.loadedCustomerId = booking.customerId
					const contact = await this.objectStore.fetchObject('contact', booking.customerId)
					this.customer = contact || await this.objectStore.fetchObject('client', booking.customerId)
				}
				// De-duplicate resource ids: a booking has one assignment row
				// per step/time-slot, so the same resource recurs across rows.
				const resourceIds = [...new Set((this.assignments || [])
					.map(a => a?.resourceId)
					.filter(Boolean))]
					.filter(id => !this.resourceLookup[id])
				for (const id of resourceIds) {
					const resource = await this.objectStore.fetchObject('resource', id)
					if (resource?.name) {
						this.resourceLookup = { ...this.resourceLookup, [id]: resource.name }
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
				const saved = await this.objectStore.saveObject('booking', payload)
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
		/**
		 * POST to a booking-admin endpoint and reload the booking on success.
		 *
		 * @param {string} action The path segment (e.g. 'complete').
		 * @param {object} body   Optional JSON body.
		 * @param {string} okMsg  Success toast message.
		 * @return {Promise<boolean>} Whether the call succeeded.
		 */
		async lifecycle(action, body, okMsg) {
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/bookings/${this.resolvedId}/${action}`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify(body || {}),
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					showError(data.error || t('pipelinq', 'Action failed.'))
					return false
				}
				showSuccess(okMsg)
				if (data.bookingId && data.bookingId !== this.resolvedId) {
					// Reschedule returns a new UUID — navigate to the new booking.
					this.$router.push({ name: 'BookingDetail', params: { id: data.bookingId } })
					return true
				}
				await this.load()
				return true
			} catch {
				showError(t('pipelinq', 'Action failed.'))
				return false
			} finally {
				this.busy = false
			}
		},
		async confirmDeposit() {
			await this.lifecycle('confirm-deposit', {}, t('pipelinq', 'Deposit confirmed.'))
		},
		async markCompleted() {
			await this.lifecycle('complete', {}, t('pipelinq', 'Booking marked completed.'))
		},
		async markNoShow() {
			await this.lifecycle('no-show', {}, t('pipelinq', 'Booking marked as no-show.'))
		},
		async sendReminder() {
			await this.lifecycle('send-reminder', {}, t('pipelinq', 'Reminder dispatched.'))
		},
		async onReschedule(newStartAt) {
			this.showReschedule = false
			await this.lifecycle('reschedule', { newStartAt }, t('pipelinq', 'Booking rescheduled.'))
		},
		async onCancel(reason) {
			this.showCancel = false
			await this.lifecycle('cancel', { reason: reason || '' }, t('pipelinq', 'Booking cancelled.'))
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
.booking-section__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
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
.viewTable th, .viewTable td {
	padding: 12px;
	text-align: left;
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
.timeline {
	margin: 0;
	padding-left: 20px;
}
.timeline li {
	margin-bottom: 8px;
}
.timeline-when {
	display: inline-block;
	min-width: 180px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}
.timeline-text {
	font-weight: 500;
}
</style>
