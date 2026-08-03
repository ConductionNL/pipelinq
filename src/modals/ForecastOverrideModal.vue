<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Override forecast')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="forecast-override">
			<p class="forecast-override__context">
				{{ t('pipelinq', 'Overriding {category} for {owner}', { category: categoryLabel, owner: ownerId }) }}
			</p>
			<NcTextField
				v-model="amount"
				type="number"
				:label="t('pipelinq', 'Override amount')"
				:placeholder="t('pipelinq', 'Override amount')" />
			<NcTextArea
				v-model="reason"
				:label="t('pipelinq', 'Reason for override')"
				:placeholder="t('pipelinq', 'Why are you adjusting this number?')"
				:error="showError"
				:helper-text="showError ? t('pipelinq', 'Please enter at least 5 characters.') : ''"
				rows="3" />
			<p v-if="errorMessage" class="forecast-override__error">
				{{ errorMessage }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!valid || saving" @click="save">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextArea, NcTextField } from '@nextcloud/vue'
import { createOverride } from '../services/forecastApi.js'

export default {
	name: 'ForecastOverrideModal',
	components: { NcButton, NcDialog, NcTextArea, NcTextField },
	props: {
		periodId: { type: String, required: true },
		ownerId: { type: String, required: true },
		level: { type: String, required: true },
		category: { type: String, default: 'commit' },
		currentAmount: { type: Number, default: 0 },
	},
	emits: ['close', 'saved'],
	data() {
		return {
			amount: String(this.currentAmount),
			reason: '',
			touched: false,
			saving: false,
			errorMessage: '',
		}
	},
	computed: {
		categoryLabel() {
			return this.category === 'best_case'
				? t('pipelinq', 'Best-case')
				: t('pipelinq', 'Commit')
		},
		valid() {
			return this.reason.trim().length >= 5 && Number(this.amount) >= 0
		},
		showError() {
			return this.touched && this.reason.trim().length < 5
		},
	},
	methods: {
		async save() {
			this.touched = true
			if (!this.valid) {
				return
			}
			this.saving = true
			this.errorMessage = ''
			try {
				const created = await createOverride({
					period_id: this.periodId,
					override_owner_id: this.ownerId,
					level: this.level,
					category: this.category,
					override_amount: Number(this.amount),
					reason: this.reason.trim(),
				})
				this.$emit('saved', created)
				this.$emit('close')
			} catch (error) {
				this.errorMessage = error?.response?.data?.error
					|| t('pipelinq', 'Could not save the override.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.forecast-override__context {
	margin-bottom: 12px;
	color: var(--color-text-maxcontrast);
}

.forecast-override__error {
	margin-top: 8px;
	color: var(--color-error);
}
</style>
