<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<NcDialog
		:name="t('pipelinq', 'Open shift')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="cash-shift-open">
			<NcTextField
				:value.sync="drawer"
				:label="t('pipelinq', 'Drawer')" />
			<NcTextField
				:value.sync="floatAmount"
				type="number"
				:label="t('pipelinq', 'Opening amount (€)')"
				:error="showError"
				:helper-text="showError ? t('pipelinq', 'Opening amount required') : ''" />
			<NcTextField
				:value.sync="reference"
				:label="t('pipelinq', 'Reference (optional)')" />
			<NcTextArea
				:value.sync="notes"
				:label="t('pipelinq', 'Notes (optional)')" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="submitting" @click="submit">
				{{ t('pipelinq', 'Open shift') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField, NcTextArea } from '@nextcloud/vue'

export default {
	name: 'CashShiftOpenDialog',
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
			drawer: '',
			floatAmount: '',
			reference: '',
			notes: '',
			showError: false,
		}
	},
	methods: {
		/**
		 * Validate the float amount and emit the open payload.
		 */
		submit() {
			const amount = parseFloat(this.floatAmount)
			if (this.floatAmount === '' || Number.isNaN(amount) || amount < 0) {
				this.showError = true
				return
			}
			this.$emit('confirm', {
				drawer: this.drawer.trim(),
				floatAmount: amount,
				reference: this.reference.trim(),
				notes: this.notes.trim(),
			})
		},
	},
}
</script>

<style scoped>
.cash-shift-open {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}
</style>
