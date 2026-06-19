<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Booking detail page — appointment-booking member 11.

  Header + status badge + context-sensitive action buttons (Reschedule,
  Cancel, Send Reminder, Mark Completed, Mark No-show, Complete Payment).
  Body cards: Booking Details, Resource Assignments, Audit Trail
  (statusHistory), Timeline. Only `notes` + `internalNotes` are editable
  inline; all other fields flow through BookingService via the admin
  endpoints in /api/bookings/{id}/{action}.

  Action visibility rules:
    - "Mark Completed": confirmed booking whose endAt has passed.
    - "Mark No-show":   confirmed booking whose endAt has passed.
    - "Reschedule":     confirmed or pending-deposit, in the future.
    - "Cancel":         confirmed or pending-deposit, in the future.
    - "Send Reminder":  confirmed, in the future, > 1h away.
    - "Confirm Deposit": status == pending-deposit.

  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
  @spec openspec/changes/appointment-booking-11-admin-ui/specs/appointment-booking/spec.md#REQ-APT-015
-->
<template>
	<CnDetailPage
		:title="bookingTitle"
		:subtitle="t('pipelinq', 'Booking')"
		:back-route="{ name: 'Bookings' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="{ enabled: !loading }"
		object-type="pipelinq_booking"
		:object-id="bookingId"
		:sidebar-props="sidebarProps">
		<template #actions>
			<NcButton
				v-if="canConfirmDeposit"
				type="primary"
				:disabled="busy"
				@click="confirmDeposit">
				{{ t('pipelinq', 'Confirm deposit') }}
			</NcButton>
			<NcButton
				v-if="canMarkCompleted"
				type="primary"
				:disabled="busy"
				@click="markCompleted">
				{{ t('pipelinq', 'Mark completed') }}
			</NcButton>
			<NcButton
				v-if="canMarkNoShow"
				type="error"
				:disabled="busy"
				@click="markNoShow">
				{{ t('pipelinq', 'Mark no-show') }}
			</NcButton>
			<NcButton
				v-if="canReschedule"
				type="secondary"
				:disabled="busy"
				@click="showReschedule = true">
				{{ t('pipelinq', 'Reschedule') }}
			</NcButton>
			<NcButton
				v-if="canSendReminder"
				type="secondary"
				:disabled="busy"
				@click="sendReminder">
				{{ t('pipelinq', 'Send reminder') }}
			</NcButton>
			<NcButton
				v-if="canCancel"
				type="error"
				:disabled="busy"
				@click="showCancel = true">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Booking details')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<span class="status-badge" :class="`status-badge--${booking.status}`">
						{{ statusLabel(booking.status) }}
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Source') }}</label>
					<span>{{ booking.source || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Service') }}</label>
					<span>{{ serviceName }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Customer') }}</label>
					<span>{{ customerLabel }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Start') }}</label>
					<span>{{ formatDateTime(booking.startAt) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'End') }}</label>
					<span>{{ formatDateTime(booking.endAt) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Deposit') }}</label>
					<span>{{ depositLabel }}</span>
				</div>
				<div v-if="booking.previousBookingId" class="info-field">
					<label>{{ t('pipelinq', 'Rescheduled from') }}</label>
					<span>
						<router-link :to="{ name: 'BookingDetail', params: { id: booking.previousBookingId } }">
							{{ booking.previousBookingId }}
						</router-link>
					</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Notes')">
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
					type="primary"
					:disabled="busy || !notesDirty"
					@click="saveNotes">
					{{ t('pipelinq', 'Save notes') }}
				</NcButton>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Resource assignments')">
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
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Audit trail')">
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
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Timeline')">
			<ol class="timeline">
				<li v-for="(event, idx) in timeline" :key="idx" :class="`timeline-${event.kind}`">
					<span class="timeline-when">{{ formatDateTime(event.at) }}</span>
					<span class="timeline-text">{{ event.text }}</span>
				</li>
			</ol>
		</CnDetailCard>

		<RescheduleBookingDialog
			v-if="showReschedule"
			:current-start-at="booking.startAt || ''"
			@confirm="onReschedule"
			@cancel="showReschedule = false" />

		<CancelBookingDialog
			v-if="showCancel"
			@confirm="onCancel"
			@cancel="showCancel = false" />
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
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
	name: 'BookingDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		RescheduleBookingDialog,
		CancelBookingDialog,
	},
	props: {
		id: { type: String, default: null },
	},
	data() {
		return {
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
		bookingId() {
			return this.id || null
		},
		loading() {
			return this.objectStore.loading?.booking || false
		},
		booking() {
			if (!this.bookingId) return {}
			return this.objectStore.getObject('booking', this.bookingId) || {}
		},
		bookingTitle() {
			const start = this.booking.startAt ? this.formatDateTime(this.booking.startAt) : t('pipelinq', 'Booking')
			return this.serviceName !== '-' ? `${this.serviceName} — ${start}` : start
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
		sidebarProps() {
			const cfg = this.objectStore.objectTypeRegistry?.booking || {}
			return {
				title: t('pipelinq', 'Booking'),
				register: cfg.register || '',
				schema: cfg.schema || '',
				hiddenTabs: ['tasks'],
			}
		},
	},
	watch: {
		booking: {
			immediate: true,
			handler(val) {
				if (!val || !Object.keys(val).length) return
				this.editableNotes = val.notes || ''
				this.savedNotes = val.notes || ''
				this.editableInternalNotes = val.internalNotes || ''
				this.savedInternalNotes = val.internalNotes || ''
				this.loadContext()
			},
		},
	},
	async mounted() {
		if (this.bookingId) {
			await this.objectStore.fetchObject('booking', this.bookingId)
		}
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
		 * Fetch the linked Service / Customer / Resource records so the
		 * detail page can display human-readable names instead of UUIDs.
		 * Failures are silent — the page falls back to UUIDs.
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
					id: this.bookingId,
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
					generateUrl(`/apps/pipelinq/api/bookings/${this.bookingId}/${action}`),
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
				if (data.bookingId && data.bookingId !== this.bookingId) {
					// Reschedule returns a new UUID — navigate to the new booking.
					this.$router.push({ name: 'BookingDetail', params: { id: data.bookingId } })
					return true
				}
				await this.objectStore.fetchObject('booking', this.bookingId)
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
