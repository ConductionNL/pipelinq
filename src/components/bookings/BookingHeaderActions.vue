<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The BookingDetail page's `actionsComponent`: the booking admin actions as
  - the row of buttons the PqAfspraak board draws under the title. The five
  - status actions are always there and disabled while they do not apply, so
  - the row keeps its place. Send reminder lives on the When and where card
  - (BookingDetailSection part `when`), where the board puts it.
  -
  - They POST to BookingService's /api/bookings/{id}/{action} endpoints, which
  - carry side-effects (confirmation / reminder emails, no-show fees) and
  - time-window gating, so neither a declarative api-call (visibleWhen cannot
  - compare dates) nor the OR lifecycle (/transition only flips the status)
  - can drive them. Reschedule creates a new booking and navigates to it.
  -
  - A successful action bumps `cn:page:refresh`, so the page and
  - the booking widgets re-read the booking.
  -
  - @spec openspec/specs/appointment-booking/spec.md
  -->
<template>
	<div
		v-if="booking.id || objectId"
		class="booking-header-actions"
		data-testid="booking-header-actions">
		<NcButton
			variant="secondary"
			:disabled="busy || !canConfirmDeposit"
			data-testid="booking-action-confirm-deposit"
			@click="run('confirm-deposit', {}, t('pipelinq', 'Deposit confirmed.'))">
			<template #icon>
				<CashCheck :size="20" />
			</template>
			{{ t('pipelinq', 'Confirm deposit') }}
		</NcButton>
		<NcButton
			variant="secondary"
			:disabled="busy || !canMarkCompleted"
			data-testid="booking-action-complete"
			@click="run('complete', {}, t('pipelinq', 'Booking marked completed.'))">
			<template #icon>
				<CheckCircleOutline :size="20" />
			</template>
			{{ t('pipelinq', 'Mark completed') }}
		</NcButton>
		<NcButton
			variant="secondary"
			:disabled="busy || !canMarkNoShow"
			data-testid="booking-action-no-show"
			@click="run('no-show', {}, t('pipelinq', 'Booking marked as no-show.'))">
			<template #icon>
				<AccountCancelOutline :size="20" />
			</template>
			{{ t('pipelinq', 'Mark no-show') }}
		</NcButton>
		<NcButton
			variant="secondary"
			:disabled="busy || !canReschedule"
			data-testid="booking-action-reschedule"
			@click="showReschedule = true">
			<template #icon>
				<CalendarSync :size="20" />
			</template>
			{{ t('pipelinq', 'Reschedule') }}
		</NcButton>
		<NcButton
			variant="secondary"
			:disabled="busy || !canCancel"
			data-testid="booking-action-cancel"
			@click="showCancel = true">
			<template #icon>
				<CalendarRemove :size="20" />
			</template>
			{{ t('pipelinq', 'Cancel') }}
		</NcButton>

		<RescheduleBookingDialog
			v-if="showReschedule"
			:currentStartAt="booking.startAt || ''"
			:currentEndAt="booking.endAt || ''"
			:subject="booking.subject || ''"
			@confirm="onReschedule"
			@cancel="showReschedule = false" />

		<CancelBookingDialog
			v-if="showCancel"
			:booking="booking"
			@confirm="onCancel"
			@cancel="showCancel = false" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import AccountCancelOutline from 'vue-material-design-icons/AccountCancelOutline.vue'
import CalendarRemove from 'vue-material-design-icons/CalendarRemove.vue'
import CalendarSync from 'vue-material-design-icons/CalendarSync.vue'
import CashCheck from 'vue-material-design-icons/CashCheck.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CancelBookingDialog from '../../dialogs/CancelBookingDialog.vue'
import RescheduleBookingDialog from '../../dialogs/RescheduleBookingDialog.vue'

export default {
	name: 'BookingHeaderActions',
	components: {
		AccountCancelOutline,
		CalendarRemove,
		CalendarSync,
		CancelBookingDialog,
		CashCheck,
		CheckCircleOutline,
		NcButton,
		RescheduleBookingDialog,
	},

	// The slot also hands over schema / store / openEditForm; none of them
	// belong on the root element.
	inheritAttrs: false,

	props: {
		/** The booking, from CnDetailPage's `#actions` slot. */
		object: {
			type: Object,
			default: null,
		},

		/** The booking id, from CnDetailPage's `#actions` slot. */
		objectId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			busy: false,
			showReschedule: false,
			showCancel: false,
		}
	},

	computed: {
		/**
		 * The booking the page shows, or an empty object before it loads.
		 *
		 * @return {object}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		booking() {
			return this.object || {}
		},

		/**
		 * Whether the booking has not ended yet.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		isFuture() {
			return (
				Boolean(this.booking.endAt)
				&& new Date(this.booking.endAt).getTime() > Date.now()
			)
		},

		/**
		 * Whether the booking has ended.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		isPast() {
			return (
				Boolean(this.booking.endAt)
				&& new Date(this.booking.endAt).getTime() <= Date.now()
			)
		},

		isOpen() {
			return ['confirmed', 'pending-deposit'].includes(this.booking.status)
		},

		/**
		 * Whether Confirm deposit applies: the booking awaits its deposit.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		canConfirmDeposit() {
			return this.booking.status === 'pending-deposit'
		},

		/**
		 * Whether Mark completed applies: a confirmed booking that has ended.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		canMarkCompleted() {
			return this.booking.status === 'confirmed' && this.isPast
		},

		/**
		 * Whether Mark no-show applies: a confirmed booking that has ended.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		canMarkNoShow() {
			return this.booking.status === 'confirmed' && this.isPast
		},

		/**
		 * Whether Reschedule applies: an open booking still ahead.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		canReschedule() {
			return this.isOpen && this.isFuture
		},

		/**
		 * Whether Cancel applies: an open booking still ahead.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		canCancel() {
			return this.isOpen && this.isFuture
		},

	},

	methods: {
		/**
		 * POST a booking-admin action, then refresh the page, or open the new
		 * booking when the action minted one (Reschedule).
		 *
		 * @param {string} action The path segment (e.g. 'complete').
		 * @param {object} body Optional JSON body.
		 * @param {string} okMsg Success toast message.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		async run(action, body, okMsg) {
			const id = this.objectId || this.booking.id
			if (!id) {
				return
			}
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/bookings/${id}/${action}`),
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
					return
				}
				showSuccess(okMsg)
				if (data.bookingId && data.bookingId !== id) {
					this.$router.push({
						name: 'BookingDetail',
						params: { id: data.bookingId },
					})
					return
				}
				emit('cn:page:refresh', {})
			} catch {
				showError(t('pipelinq', 'Action failed.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Reschedule the booking to the chosen start time.
		 *
		 * @param {string} newStartAt The new start, as an ISO date-time.
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		onReschedule(newStartAt) {
			this.showReschedule = false
			this.run(
				'reschedule',
				{ newStartAt },
				t('pipelinq', 'Booking rescheduled.'),
			)
		},

		/**
		 * Cancel the booking with the reason the dialog collected.
		 *
		 * @param {string} reason The cancellation reason, possibly empty.
		 *
		 * @spec openspec/specs/appointment-booking/spec.md
		 */
		onCancel(reason) {
			this.showCancel = false
			this.run(
				'cancel',
				{ reason: reason || '' },
				t('pipelinq', 'Booking cancelled.'),
			)
		},
	},
}
</script>

<style scoped>
.booking-header-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 10px;
}
</style>
