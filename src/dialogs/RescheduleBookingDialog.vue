<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  RescheduleBookingDialog — datetime picker for moving a booking.
  Emits `confirm(newStartAt)` with an ISO-8601 timestamp; the caller posts
  it to /api/bookings/{id}/reschedule. Extracted to its own file per
  ADR-004 (modal-isolation).
  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<CnDialog
		:name="t('pipelinq', 'Reschedule booking')"
		:eyebrow="eyebrow"
		:subtitle="t('pipelinq', 'Choose a new start time.')"
		width="form"
		@closing="$emit('cancel')">
		<div class="reschedule-form">
			<div class="form-group">
				<label for="new-start-at">{{
					t('pipelinq', 'New start time')
				}}</label>
				<input
					id="new-start-at"
					v-model="newStartAt"
					type="datetime-local"
					:aria-label="t('pipelinq', 'New start time')" />
			</div>
			<dl class="reschedule-form__slots" data-testid="reschedule-slots">
				<dt>{{ t('pipelinq', 'Now') }}</dt>
				<dd>{{ currentSlot }}</dd>
				<dt>{{ t('pipelinq', 'New') }}</dt>
				<dd>{{ newSlot }}</dd>
			</dl>
			<CnNoteCard
				type="info"
				:text="t('pipelinq', 'The customer gets a new confirmation and the reminder moves with the appointment.')" />
			<p v-if="error" class="error-text">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!isValid" @click="onConfirm">
				<template #icon>
					<CalendarSync :size="20" />
				</template>
				{{ t('pipelinq', 'Reschedule') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog, CnNoteCard } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import CalendarSync from 'vue-material-design-icons/CalendarSync.vue'
import { slotLabel } from '../utils/bookingTime.js'

export default {
	name: 'RescheduleBookingDialog',
	components: { CalendarSync, CnDialog, CnNoteCard, NcButton },
	props: {
		currentStartAt: { type: String, default: '' },
		/** The current end; the new slot keeps the same length. */
		currentEndAt: { type: String, default: '' },
		/** What the booking is about, named in the dialog's eyebrow. */
		subject: { type: String, default: '' },
	},

	emits: ['confirm', 'cancel'],
	data() {
		return {
			newStartAt: this.formatForInput(this.weekLater(this.currentStartAt)),
			error: '',
		}
	},

	computed: {
		/**
		 * The context line above the title ("Boeking · locatiebezoek Parkstraat").
		 *
		 * @return {string}
		 */
		eyebrow() {
			return [t('pipelinq', 'Booking'), this.subject].filter(Boolean).join(' · ')
		},

		/**
		 * The slot the booking has now ("Donderdag 8 oktober, 14:00 tot 15:00").
		 *
		 * @return {string}
		 */
		currentSlot() {
			return slotLabel(this.currentStartAt, this.currentEndAt) || '-'
		},

		/**
		 * The slot the chosen start gives, just as long as the current one.
		 *
		 * @return {string}
		 */
		newSlot() {
			const start = new Date(this.newStartAt)
			if (!this.newStartAt || Number.isNaN(start.getTime())) {
				return '-'
			}
			const from = new Date(this.currentStartAt).getTime()
			const to = new Date(this.currentEndAt).getTime()
			const length = Number.isFinite(to - from) && to > from ? to - from : 0
			const end = length > 0 ? new Date(start.getTime() + length) : null
			return t('pipelinq', '{slot}, same length', { slot: slotLabel(start, end) })
		},

		isValid() {
			return (
				!!this.newStartAt
				&& this.newStartAt !== this.formatForInput(this.currentStartAt)
			)
		},
	},

	methods: {
		/**
		 * The same time a week later, the first suggestion for the new start.
		 *
		 * @param {string} iso The current start.
		 * @return {string} The suggested start, or '' without a current start.
		 */
		weekLater(iso) {
			const start = Date.parse(iso || '')
			return Number.isFinite(start) ? new Date(start + 7 * 24 * 60 * 60 * 1000).toISOString() : ''
		},

		/**
		 * Convert an ISO-8601 timestamp to the value format expected by
		 * `<input type="datetime-local">` (YYYY-MM-DDTHH:MM in local time).
		 *
		 * @param {string} iso The source ISO timestamp.
		 * @return {string} Local-time value for the input.
		 */
		formatForInput(iso) {
			if (!iso) return ''
			const d = new Date(iso)
			if (Number.isNaN(d.getTime())) return ''
			const pad = (n) => String(n).padStart(2, '0')
			return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
		},

		onConfirm() {
			if (!this.newStartAt) {
				this.error = t('pipelinq', 'Pick a new start time.')
				return
			}
			const parsed = new Date(this.newStartAt)
			if (Number.isNaN(parsed.getTime())) {
				this.error = t('pipelinq', 'Invalid date / time.')
				return
			}
			this.$emit('confirm', parsed.toISOString())
		},
	},
}
</script>

<style scoped>
.reschedule-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.reschedule-form__slots {
	display: grid;
	justify-content: start;
	text-align: start;
	grid-template-columns: max-content 1fr;
	gap: 8px 16px;
	margin: 0;
	padding: 0;
	font-size: 14px;
}

/* Nextcloud's global dt and dd rules pad and right-align them, which pulls the rows apart. */
.reschedule-form__slots dt {
	width: auto;
	padding: 0;
	white-space: normal;
	text-align: start;
	color: var(--color-text-maxcontrast);
}

.reschedule-form__slots dd {
	margin: 0;
	padding: 0;
}

.form-group {
	margin: 0;
}

.form-group label {
	display: block;
	font-weight: bold;
	margin-bottom: 4px;
}

.form-group input {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.error-text {
	color: var(--color-error);
	margin-top: 8px;
	font-size: 13px;
}
</style>
