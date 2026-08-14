<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Add Tender dialog (pos-split-tender REQ-PST-002 / REQ-PST-005).
  -
  - Picks an active tender type, enters the amount and the optional
  - external reference (required when the type has requiresReference=true).
  - Submits POST /api/pos-transactions/{id}/tenders and emits `added` on
  - success so the parent panel can refresh.
  -
  - @spec openspec/changes/pos-split-tender/tasks.md#7.3
  - @spec openspec/changes/pos-split-tender/tasks.md#7.4
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Add tender')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="add-tender">
			<NcSelect
				v-model="selected"
				:options="tenderTypes"
				:input-label="t('pipelinq', 'Tender type')"
				label="name"
				track-by="code"
				:reduce="(option) => idOf(option)"
				:placeholder="t('pipelinq', 'Select a tender type')" />

			<NcTextField
				v-model.number="amount"
				type="number"
				:label="t('pipelinq', 'Amount (EUR)')"
				:placeholder="
					suggestedAmount > 0 ? formatEur(suggestedAmount) : '0.00'
				"
				step="0.01"
				min="0.01" />
			<NcButton
				v-if="suggestedAmount > 0 && amount !== suggestedAmount"
				@click="amount = suggestedAmount">
				{{
					t('pipelinq', 'Use remaining: {amount}', {
						amount: formatEur(suggestedAmount),
					})
				}}
			</NcButton>

			<NcTextField
				v-if="requiresReference"
				v-model="reference"
				:label="t('pipelinq', 'Reference')"
				:placeholder="t('pipelinq', 'Card auth code, voucher serial, ...')"
				required />

			<div
				v-if="
					selectedType
					&& selectedType.allowsChange
					&& amount > transactionTotal
				"
				class="add-tender__change">
				{{
					t('pipelinq', 'Change due: {change}', {
						change: formatEur(amount - transactionTotal),
					})
				}}
			</div>

			<div class="add-tender__totals">
				<div>
					<strong>{{ t('pipelinq', 'Transaction total:') }}</strong>
					{{ formatEur(transactionTotal) }}
				</div>
				<div v-if="remaining > 0">
					<strong>{{ t('pipelinq', 'Remaining:') }}</strong>
					{{ formatEur(remaining) }}
				</div>
			</div>

			<p v-if="errorMessage" class="add-tender__error" role="alert">
				{{ errorMessage }}
			</p>
		</div>
		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving || !canSubmit"
				@click="submit">
				{{ saving ? t('pipelinq', 'Saving…') : t('pipelinq', 'Add tender') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcDialog, NcSelect, NcTextField } from '@nextcloud/vue'
import { formatEur } from '../services/posTotals.js'

export default {
	name: 'AddTenderDialog',
	components: { NcButton, NcDialog, NcSelect, NcTextField },
	props: {
		transactionId: {
			type: String,
			required: true,
		},
		transactionTotal: {
			type: Number,
			default: 0,
		},
		remaining: {
			type: Number,
			default: 0,
		},
		tenderTypes: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['close', 'added'],
	data() {
		return {
			selected: null,
			amount: 0,
			reference: '',
			saving: false,
			errorMessage: '',
		}
	},
	computed: {
		selectedType() {
			if (!this.selected) {
				return null
			}
			return (
				this.tenderTypes.find((type) => this.idOf(type) === this.selected)
				|| null
			)
		},
		requiresReference() {
			return !!this.selectedType?.requiresReference
		},
		suggestedAmount() {
			return Number((this.remaining || 0).toFixed(2))
		},
		canSubmit() {
			if (!this.selected || !this.amount || this.amount < 0.01) {
				return false
			}
			if (this.requiresReference && !this.reference?.trim()) {
				return false
			}
			return true
		},
	},
	mounted() {
		if (this.suggestedAmount > 0) {
			this.amount = this.suggestedAmount
		}
	},
	methods: {
		formatEur,
		idOf(type) {
			if (type?.['@self']?.id) {
				return type['@self'].id
			}
			return type?.id || type?.uuid || ''
		},
		async submit() {
			this.saving = true
			this.errorMessage = ''
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/pos-transactions/{id}/tenders',
					{ id: this.transactionId },
				)
				await axios.post(url, {
					tenderType: this.selected,
					amount: Number(Number(this.amount).toFixed(2)),
					reference: (this.reference || '').trim(),
				})
				showSuccess(t('pipelinq', 'Tender added'))
				this.$emit('added')
			} catch (error) {
				const msg =
					error?.response?.data?.error
					|| t('pipelinq', 'Failed to add tender')
				this.errorMessage = msg
				showError(msg)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.add-tender {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 8px 0;
	min-width: 360px;
}

.add-tender__change {
	color: var(--color-success);
	font-weight: bold;
	padding: 6px 10px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.add-tender__totals {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 6px 10px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.add-tender__error {
	color: var(--color-error);
	margin: 0;
}
</style>
