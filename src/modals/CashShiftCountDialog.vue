<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<NcDialog
		:name="t('pipelinq', 'Shift afsluiten en tellen')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="cash-shift-count">
			<p>{{ t('pipelinq', 'Tel het contante geld in de lade en voer het totaal in. Er worden geen verwachte bedragen getoond (blind tellen).') }}</p>
			<NcTextField
				:value.sync="amount"
				type="number"
				:label="t('pipelinq', 'Geteld bedrag')"
				placeholder="€ 0.00"
				:error="showError"
				:helper-text="showError ? t('pipelinq', 'Voer een geldig bedrag in') : ''" />
			<NcTextArea
				:value.sync="notes"
				:label="t('pipelinq', 'Notities (optioneel)')" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Annuleren') }}
			</NcButton>
			<NcButton type="primary" :disabled="submitting" @click="submit">
				{{ t('pipelinq', 'Afsluiten en tellen') }}
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
