<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<NcDialog
		:name="t('pipelinq', 'Geld verwijderen')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="cash-shift-drop">
			<NcTextField
				:value.sync="amount"
				type="number"
				:label="t('pipelinq', 'Bedrag (€)')"
				:error="showError"
				:helper-text="showError ? t('pipelinq', 'Voer een bedrag groter dan nul in') : ''" />
			<NcSelect
				v-model="reason"
				:options="reasonOptions"
				:input-label="t('pipelinq', 'Reden')"
				label="label"
				track-by="id" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Annuleren') }}
			</NcButton>
			<NcButton type="primary" :disabled="submitting" @click="submit">
				{{ t('pipelinq', 'Vastleggen') }}
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
				{ id: 'manager-deposit', label: t('pipelinq', 'Afstorting bij manager') },
				{ id: 'bank-run', label: t('pipelinq', 'Bankrit') },
				{ id: 'security-removal', label: t('pipelinq', 'Veiligheidsafvoer') },
				{ id: 'other', label: t('pipelinq', 'Overig') },
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
