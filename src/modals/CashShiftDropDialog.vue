<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<NcDialog
		:name="t('pipelinq', 'Remove cash')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="cash-shift-drop">
			<NcTextField
				v-model="amount"
				type="number"
				:label="t('pipelinq', 'Amount (€)')"
				:error="showError"
				:helper-text="
					showError
						? t('pipelinq', 'Enter an amount greater than zero')
						: ''
				" />
			<NcSelect
				v-model="reason"
				:options="reasonOptions"
				:input-label="t('pipelinq', 'Reason')"
				label="label"
				track-by="id" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="submitting" @click="submit">
				{{ t('pipelinq', 'Record') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField, NcSelect } from '@nextcloud/vue'

export default {
	name: 'CashShiftDropDialog',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
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
			reason: null,
			showError: false,
		}
	},
	computed: {
		/**
		 * Selectable drop reasons.
		 *
		 * @return {Array<object>} The reason options.
		 */
		reasonOptions() {
			return [
				{
					id: 'manager-deposit',
					label: t('pipelinq', 'Cash drop to manager'),
				},
				{ id: 'bank-run', label: t('pipelinq', 'Bank run') },
				{ id: 'security-removal', label: t('pipelinq', 'Security removal') },
				{ id: 'other', label: t('pipelinq', 'Other') },
			]
		},
	},
	methods: {
		/**
		 * Validate the amount and emit the drop payload.
		 */
		submit() {
			const value = parseFloat(this.amount)
			if (this.amount === '' || Number.isNaN(value) || value <= 0) {
				this.showError = true
				return
			}
			this.$emit('confirm', {
				amount: value,
				reason: this.reason?.id || '',
			})
		},
	},
}
</script>

<style scoped>
.cash-shift-drop {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}
</style>
