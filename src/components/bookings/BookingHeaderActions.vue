<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The BookingDetail page's `actionsComponent`: the six booking admin actions,
  - rendered in the page header beside Edit.
  -
  - They POST to BookingService's /api/bookings/{id}/{action} endpoints, which
  - carry side-effects (confirmation / reminder emails, no-show fees) and
  - time-window gating, so neither a declarative api-call (visibleWhen cannot
  - compare dates) nor the OR lifecycle (/transition only flips the status)
  - can drive them. Reschedule creates a new booking and navigates to it.
  -
  - A successful action bumps `cn:page:refresh`, so the page and
  - BookingDetailSection re-read the booking.
  -
  - @spec openspec/specs/appointment-booking/spec.md
  -->
<template>
	<div v-if="hasActions" class="booking-header-actions" data-testid="booking-header-actions">
		<NcButton
			v-if="canConfirmDeposit"
			variant="primary"
			:disabled="busy"
			@click="run('confirm-deposit', {}, t('pipelinq', 'Deposit confirmed.'))">
			{{ t('pipelinq', 'Confirm deposit') }}
		</NcButton>
		<NcButton
			v-if="canMarkCompleted"
			variant="primary"
			:disabled="busy"
			@click="run('complete', {}, t('pipelinq', 'Booking marked completed.'))">
			{{ t('pipelinq', 'Mark completed') }}
		</NcButton>
		<NcButton
			v-if="canMarkNoShow"
			variant="error"
			:disabled="busy"
			@click="run('no-show', {}, t('pipelinq', 'Booking marked as no-show.'))">
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
			@click="run('send-reminder', {}, t('pipelinq', 'Reminder dispatched.'))">
			{{ t('pipelinq', 'Send reminder') }}
		</NcButton>
		<NcButton
			v-if="canCancel"
			variant="error"
			:disabled="busy"
			@click="showCancel = true">
			{{ t('pipelinq', 'Cancel') }}
		</NcButton>

		<RescheduleBookingDialog
			v-if="showReschedule"
			:currentStartAt="booking.startAt || ''"
			@confirm="onReschedule"
			@cancel="showReschedule = false" />

		<CancelBookingDialog
			v-if="showCancel"
			@confirm="onCancel"
			@cancel="showCancel = false" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import CancelBookingDialog from '../../dialogs/CancelBookingDialog.vue'
import RescheduleBookingDialog from '../../dialogs/RescheduleBookingDialog.vue'

const HOUR_MS = 60 * 60 * 1000

export default {
	name: 'BookingHeaderActions',
	components: { CancelBookingDialog, NcButton, RescheduleBookingDialog },
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
		booking() {
			return this.object || {}
		},

		isFuture() {
			return Boolean(this.booking.endAt) && new Date(this.booking.endAt).getTime() > Date.now()
		},

		isPast() {
			return Boolean(this.booking.endAt) && new Date(this.booking.endAt).getTime() <= Date.now()
		},

		hourAway() {
			return Boolean(this.booking.startAt) && new Date(this.booking.startAt).getTime() - Date.now() > HOUR_MS
		},

		isOpen() {
			return ['confirmed', 'pending-deposit'].includes(this.booking.status)
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
			return this.isOpen && this.isFuture
		},

		canCancel() {
			return this.isOpen && this.isFuture
		},

		canSendReminder() {
			return this.booking.status === 'confirmed' && this.isFuture && this.hourAway
		},

		hasActions() {
			return this.canConfirmDeposit || this.canMarkCompleted || this.canMarkNoShow
				|| this.canReschedule || this.canCancel || this.canSendReminder
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
					this.$router.push({ name: 'BookingDetail', params: { id: data.bookingId } })
					return
				}
				emit('cn:page:refresh', {})
			} catch {
				showError(t('pipelinq', 'Action failed.'))
			} finally {
				this.busy = false
			}
		},

		onReschedule(newStartAt) {
			this.showReschedule = false
			this.run('reschedule', { newStartAt }, t('pipelinq', 'Booking rescheduled.'))
		},

		onCancel(reason) {
			this.showCancel = false
			this.run('cancel', { reason: reason || '' }, t('pipelinq', 'Booking cancelled.'))
		},
	},
}
</script>

<style scoped>
.booking-header-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}
</style>
