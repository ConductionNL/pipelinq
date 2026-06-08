<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<NcDialog
		:name="t('pipelinq', 'Kasverschil afwijzen')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="cash-shift-reject">
			<p>{{ t('pipelinq', 'Dit wijst het kasverschil af en heropent de shift voor een hertelling. Voer een reden in.') }}</p>
			<NcTextArea
				:value.sync="reason"
				:label="t('pipelinq', 'Reden afwijzing')"
				:error="showError"
				:helper-text="showError ? t('pipelinq', 'Vul een reden in voor de afwijzing') : ''" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Annuleren') }}
			</NcButton>
			<NcButton type="error" :disabled="submitting" @click="submit">
				{{ t('pipelinq', 'Afwijzen') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextArea } from '@nextcloud/vue'

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
