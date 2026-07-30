<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Refund transaction')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="pos-refund">
			<p>{{ t('pipelinq', 'This will reverse the transaction. Enter a reason for the refund.') }}</p>
			<NcTextArea
				:value.sync="reason"
				:label="t('pipelinq', 'Reason')"
				:error="showError"
				:helper-text="showError ? t('pipelinq', 'Enter a reason for the reversal') : ''" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton type="error" :disabled="submitting" @click="submit">
				{{ t('pipelinq', 'Reverse') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'PosRefundDialog',
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
		 * Validate and emit the refund reason.
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
.pos-refund {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}
</style>
