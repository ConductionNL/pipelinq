<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<NcDialog
		:name="t('pipelinq', 'Reject cash difference')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="cash-shift-reject">
			<p>
				{{
					t(
						'pipelinq',
						'This rejects the cash difference and reopens the shift for a recount. Enter a reason.',
					)
				}}
			</p>
			<NcTextArea
				v-model="reason"
				:label="t('pipelinq', 'Rejection reason')"
				:error="showError"
				:helperText="
					showError
						? t('pipelinq', 'Enter a reason for the rejection')
						: ''
				" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="error" :disabled="submitting" @click="submit">
				{{ t('pipelinq', 'Reject') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'CashShiftRejectDialog',
	components: {
		NcDialog,
		NcButton,
		NcTextArea,
	},

	props: {
		submitting: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close', 'confirm'],
	data() {
		return {
			reason: '',
			showError: false,
		}
	},

	methods: {
		/**
		 * Validate and emit the rejection reason.
		 */
		submit() {
			if (this.reason.trim() === '') {
				this.showError = true
				return
			}
			this.$emit('confirm', this.reason.trim())
		},
	},
}
</script>

<style scoped>
.cash-shift-reject {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}
</style>
