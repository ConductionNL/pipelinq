<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<NcDialog
		:name="t('pipelinq', 'Close and count shift')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="cash-shift-count">
			<p>{{ t('pipelinq', 'Count the cash in the drawer and enter the total. No expected amounts are shown (blind counting).') }}</p>
			<NcTextField
				v-model="amount"
				type="number"
				:label="t('pipelinq', 'Counted amount')"
				placeholder="€ 0.00"
				:error="showError"
				:helper-text="showError ? t('pipelinq', 'Enter a valid amount') : ''" />
			<NcTextArea
				v-model="notes"
				:label="t('pipelinq', 'Notes (optional)')" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="submitting" @click="submit">
				{{ t('pipelinq', 'Close and count') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'CashShiftCountDialog',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
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
			amount: '',
			notes: '',
			showError: false,
		}
	},
	methods: {
		/**
		 * Validate the counted amount and emit the count payload.
		 */
		submit() {
			const value = parseFloat(this.amount)
			if (this.amount === '' || Number.isNaN(value) || value < 0) {
				this.showError = true
				return
			}
			this.$emit('confirm', {
				amount: value,
				notes: this.notes.trim(),
			})
		},
	},
}
</script>

<style scoped>
.cash-shift-count {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}
</style>
