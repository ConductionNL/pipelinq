<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  CancelBookingDialog — reason capture + confirm for cancelling a booking.
  Extracted to its own file per ADR-004 (modal-isolation).
  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<NcDialog
		:name="t('pipelinq', 'Cancel booking')"
		@closing="$emit('cancel')">
		<div class="cancel-form">
			<p>
				{{ t('pipelinq', 'Staff cancellations always skip the cancellation charge.') }}
			</p>
			<div class="form-group">
				<label for="cancel-reason">{{ t('pipelinq', 'Reason (optional)') }}</label>
				<textarea id="cancel-reason"
					v-model="reason"
					rows="3"
					:aria-label="t('pipelinq', 'Cancellation reason')" />
			</div>
		</div>
		<template #actions>
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Back') }}
			</NcButton>
			<NcButton type="error" @click="$emit('confirm', reason)">
				{{ t('pipelinq', 'Cancel booking') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'CancelBookingDialog',
	components: { NcButton, NcDialog },
	emits: ['confirm', 'cancel'],
	data() {
		return { reason: '' }
	},
}
</script>

<style scoped>
.cancel-form {
	padding: 8px 0;
}

.form-group {
	margin-top: 12px;
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
