<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  CancelBookingDialog — reason capture + confirm for cancelling a booking.
  Extracted to its own file per ADR-004 (modal-isolation).
  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<CnDialog
		:name="t('pipelinq', 'Cancel booking')"
		:eyebrow="eyebrow"
		:subtitle="summary"
		width="form"
		@closing="$emit('cancel')">
		<div class="cancel-form">
			<div class="form-group">
				<label for="cancel-reason">{{
					t('pipelinq', 'Reason (optional)')
				}}</label>
				<textarea
					id="cancel-reason"
					v-model="reason"
					rows="3"
					:placeholder="t('pipelinq', 'Cancellation reason')"
					:aria-label="t('pipelinq', 'Cancellation reason')" />
			</div>
			<CnNoteCard type="warning" :text="chargeNote" />
			<p class="cancel-form__after">
				{{ t('pipelinq', 'The customer gets a cancellation message. The booking stays in the list with a cancelled status.') }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Back') }}
			</NcButton>
			<NcButton variant="error" @click="$emit('confirm', reason)">
				<template #icon>
					<CalendarRemove :size="20" />
				</template>
				{{ t('pipelinq', 'Cancel booking') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import CalendarRemove from 'vue-material-design-icons/CalendarRemove.vue'
import { objectCurrency } from '../services/cellFormatters.js'
import { slotLabel } from '../utils/bookingTime.js'

export default {
	name: 'CancelBookingDialog',
	components: { CalendarRemove, CnDialog, CnNoteCard, NcButton },
	props: {
		/** The booking being cancelled, for its subject, slot and deposit. */
		booking: { type: Object, default: () => ({}) },
	},

	emits: ['confirm', 'cancel'],
	data() {
		return { reason: '' }
	},

	computed: {
		/**
		 * The context line above the title ("Boeking · locatiebezoek Parkstraat").
		 *
		 * @return {string}
		 */
		eyebrow() {
			return [t('pipelinq', 'Booking'), this.booking?.subject].filter(Boolean).join(' · ')
		},

		/**
		 * What is being cancelled: the subject and the slot.
		 *
		 * @return {string}
		 */
		summary() {
			const slot = slotLabel(this.booking?.startAt, this.booking?.endAt)
			return [this.booking?.subject, slot].filter(Boolean).join(', ')
		},

		/**
		 * The charge rule, and the refund when a deposit was paid.
		 *
		 * @return {string}
		 */
		chargeNote() {
			const rule = t('pipelinq', 'Staff cancellations always skip the cancellation charge.')
			const amount = Number(this.booking?.depositAmount)
			if (!this.booking?.depositPaidAt || !Number.isFinite(amount) || amount <= 0) {
				return rule
			}
			return rule + ' ' + t('pipelinq', 'The deposit of {amount} is refunded.', {
				amount: objectCurrency(amount, this.booking),
			})
		},
	},
}
</script>

<style scoped>
.cancel-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 8px 0;
}

.cancel-form__after {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.form-group {
	margin: 0;
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
</style>
