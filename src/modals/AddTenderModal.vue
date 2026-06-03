<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Add Tender')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="add-tender">
			<p class="add-tender__summary">
				{{ summaryLabel }}
			</p>

			<NcSelect v-model="selectedType"
				:options="tenderTypeOptions"
				:input-label="t('pipelinq', 'Tender Type')"
				:placeholder="t('pipelinq', 'Select a tender type')"
				label="label"
				:loading="loadingTypes" />

			<NcInputField :value.sync="amount"
				type="number"
				step="0.01"
				min="0.01"
				:label="t('pipelinq', 'Amount')" />

			<NcInputField v-if="requiresReference"
				:value.sync="reference"
				type="text"
				:label="t('pipelinq', 'Reference')"
				:helper-text="t('pipelinq', 'Reference is required for this tender type')" />

			<p v-if="changeHint" class="add-tender__change">
				{{ changeHint }}
			</p>

			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="submitting || !canSubmit" @click="submit">
				{{ t('pipelinq', 'Add Tender') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcSelect, NcInputField, NcNoteCard } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'AddTenderModal',
	components: {
		NcDialog,
		NcButton,
		NcSelect,
		NcInputField,
		NcNoteCard,
	},
	props: {
		transactionId: {
			type: String,
			required: true,
		},
		transactionTotal: {
			type: Number,
			default: 0,
		},
		currentTenderSum: {
			type: Number,
			default: 0,
		},
	},
	emits: ['close', 'added'],
	data() {
		return {
			tenderTypes: [],
			loadingTypes: false,
			selectedType: null,
			amount: '',
			reference: '',
			submitting: false,
			errorMessage: '',
		}
	},
	computed: {
		/**
		 * Tender types mapped to NcSelect option objects.
		 *
		 * @return {Array} The options.
		 */
		tenderTypeOptions() {
			return this.tenderTypes.map(type => ({
				id: type.id || type.uuid,
				label: type.name,
				code: type.code,
				glAccount: type.glAccount,
				allowsChange: !!type.allowsChange,
				requiresReference: !!type.requiresReference,
			}))
		},
		/**
		 * Whether the selected tender type requires an external reference.
		 *
		 * @return {boolean} Whether a reference is required.
		 */
		requiresReference() {
			return !!(this.selectedType && this.selectedType.requiresReference)
		},
		/**
		 * The numeric amount entered (0 when blank/invalid).
		 *
		 * @return {number} The amount.
		 */
		numericAmount() {
			const value = parseFloat(this.amount)
			return Number.isFinite(value) ? value : 0
		},
		/**
		 * Remaining amount still due before this tender.
		 *
		 * @return {number} The remaining due.
		 */
		remaining() {
			return Math.round((this.transactionTotal - this.currentTenderSum) * 100) / 100
		},
		/**
		 * Running-total summary label for the modal header.
		 *
		 * @return {string} The summary.
		 */
		summaryLabel() {
			return t('pipelinq', 'Current tender sum: {currentSum} EUR | Total: {total} EUR | Remaining: {remaining} EUR', {
				currentSum: this.currentTenderSum.toFixed(2),
				total: this.transactionTotal.toFixed(2),
				remaining: this.remaining.toFixed(2),
			})
		},
		/**
		 * Change hint shown when a change-allowing tender overpays the remaining due.
		 *
		 * @return {string} The hint (empty when no change).
		 */
		changeHint() {
			if (!this.selectedType || !this.selectedType.allowsChange) {
				return ''
			}
			const change = Math.round((this.numericAmount - this.remaining) * 100) / 100
			if (change <= 0) {
				return ''
			}
			return t('pipelinq', 'Change due: {change} EUR', { change: change.toFixed(2) })
		},
		/**
		 * Whether the form can be submitted.
		 *
		 * @return {boolean} Whether submit is allowed.
		 */
		canSubmit() {
			if (!this.selectedType || this.numericAmount < 0.01) {
				return false
			}
			if (this.requiresReference && this.reference.trim() === '') {
				return false
			}
			return true
		},
	},
	async mounted() {
		await this.loadTenderTypes()
	},
	methods: {
		/**
		 * Load the active tender types from the API.
		 */
		async loadTenderTypes() {
			this.loadingTypes = true
			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/pos/tender-types'),
					{ headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' } },
				)
				const data = await response.json().catch(() => ({}))
				this.tenderTypes = Array.isArray(data.tenderTypes) ? data.tenderTypes : []
			} catch (e) {
				this.tenderTypes = []
				showError(t('pipelinq', 'Could not load tender types.'))
			} finally {
				this.loadingTypes = false
			}
		},
		/**
		 * Validate and post the new tender.
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.submitting = true
			this.errorMessage = ''
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/pos-transactions/${this.transactionId}/tenders`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify({
							tenderType: this.selectedType.id,
							amount: this.numericAmount,
							reference: this.reference.trim(),
						}),
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					this.errorMessage = data.error || t('pipelinq', 'Could not add the tender.')
					return
				}
				this.$emit('added', data)
			} catch (e) {
				this.errorMessage = t('pipelinq', 'Could not add the tender.')
			} finally {
				this.submitting = false
			}
		},
	},
}
</script>

<style scoped>
.add-tender {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.add-tender__summary {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.add-tender__change {
	color: var(--color-success);
	font-weight: bold;
}
</style>
