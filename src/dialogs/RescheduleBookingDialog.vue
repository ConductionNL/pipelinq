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
	<NcDialog :name="t('pipelinq', 'Reschedule booking')" @closing="$emit('cancel')">
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
			<p v-if="error" class="error-text">
				{{ error }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!isValid" @click="onConfirm">
				{{ t('pipelinq', 'Reschedule') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'RescheduleBookingDialog',
	components: { NcButton, NcDialog },
	props: {
		currentStartAt: { type: String, default: '' },
	},
	emits: ['confirm', 'cancel'],
	data() {
		return {
			newStartAt: this.formatForInput(this.currentStartAt),
			error: '',
		}
	},
	computed: {
		isValid() {
			return (
				!!this.newStartAt
				&& this.newStartAt !== this.formatForInput(this.currentStartAt)
			)
		},
	},
	methods: {
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
	padding: 8px 0;
}

.form-group {
	margin-bottom: 12px;
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
