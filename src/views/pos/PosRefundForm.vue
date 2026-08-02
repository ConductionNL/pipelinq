<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<div class="pos-refund-form">
		<div class="pos-refund-form__header">
			<NcButton @click="goBack">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2>{{ isEdit ? t('pipelinq', 'Edit refund') : t('pipelinq', 'New refund') }}</h2>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<div class="pos-refund-form__fields">
				<NcSelect
					:model-value="selectedTransaction"
					:options="transactionOptions"
					:input-label="t('pipelinq', 'Original transaction')"
					:placeholder="t('pipelinq', 'Choose a transaction…')"
					label="label"
					:clearable="false"
					:disabled="lockedTransaction"
					@update:model-value="onTransactionSelect" />
				<NcSelect
					:model-value="selectedReason"
					:options="reasonOptions"
					:input-label="t('pipelinq', 'Refund reason')"
					:placeholder="t('pipelinq', 'Choose a reason…')"
					label="label"
					:clearable="false"
					@update:model-value="onReasonSelect" />
				<NcTextField
					v-model="refund.notes"
					:label="t('pipelinq', 'Notes')" />
			</div>

			<div v-if="originalLines.length" class="pos-refund-form__select-all">
				<NcButton variant="secondary" @click="selectAll">
					{{ t('pipelinq', 'Return all items') }}
				</NcButton>
			</div>

			<table v-if="originalLines.length" class="pos-refund-form__lines">
				<thead>
					<tr>
						<th />
						<th>{{ t('pipelinq', 'Description') }}</th>
						<th>{{ t('pipelinq', 'Original qty') }}</th>
						<th>{{ t('pipelinq', 'Unit price') }}</th>
						<th>{{ t('pipelinq', 'Returned qty') }}</th>
						<th>{{ t('pipelinq', 'Reason') }}</th>
						<th>{{ t('pipelinq', 'Restock') }}</th>
						<th>{{ t('pipelinq', 'Refund total') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<PosRefundLineRow
						v-for="(candidate, index) in candidates"
						:key="candidate.originalLine"
						:line="candidate"
						:original-line="originalLineFor(candidate.originalLine)"
						:reasons="activeReasons"
						@update:line="updateCandidate(index, $event)"
						@remove="removeCandidate(index)" />
				</tbody>
			</table>

			<p v-else class="pos-refund-form__empty">
				{{ t('pipelinq', 'Select an original transaction to choose items to refund.') }}
			</p>

			<PosRefundTotalsPanel :lines="selectedLines" />

			<div class="pos-refund-form__actions">
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcLoadingIcon } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import PosRefundLineRow from '../../components/pos/PosRefundLineRow.vue'
import PosRefundTotalsPanel from '../../components/pos/PosRefundTotalsPanel.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { refundLineAmounts } from '../../services/posTotals.js'

export default {
	name: 'PosRefundForm',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
		PosRefundLineRow,
		PosRefundTotalsPanel,
	},
	props: {
		posRefundId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			refund: { status: 'pending', notes: '', originalTransaction: null, refundReason: null },
			transactions: [],
			originalLines: [],
			reasons: [],
			candidates: [],
			loading: false,
			saving: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		/**
		 * The refund id from the prop or route (edit mode).
		 *
		 * @return {string|null} The id.
		 */
		refundId() {
			return this.posRefundId || this.$route.params.id || null
		},
		/**
		 * The transaction id passed when creating from a transaction.
		 *
		 * @return {string|null} The id.
		 */
		routeTransactionId() {
			return this.$route.params.transactionId || null
		},
		/**
		 * Whether this is an edit of an existing refund.
		 *
		 * @return {boolean} True when editing.
		 */
		isEdit() {
			return !!this.refundId
		},
		/**
		 * Whether the original transaction is locked (created from a transaction).
		 *
		 * @return {boolean} True when locked.
		 */
		lockedTransaction() {
			return !!this.routeTransactionId || this.isEdit
		},
		/**
		 * Transaction picker options (only refundable: confirmed / settled).
		 *
		 * @return {Array<object>} The options.
		 */
		transactionOptions() {
			return this.transactions
				.filter(tx => ['confirmed', 'settled'].includes(tx.status))
				.map(tx => ({ id: tx.id, label: tx.reference || tx.id }))
		},
		/**
		 * The selected transaction option.
		 *
		 * @return {object|null} The option.
		 */
		selectedTransaction() {
			return this.transactionOptions.find(o => o.id === this.refund.originalTransaction) || null
		},
		/**
		 * Active refundReason objects for the pickers.
		 *
		 * @return {Array<object>} The reasons.
		 */
		activeReasons() {
			return this.reasons.filter(r => r.isActive !== false)
		},
		/**
		 * Overall reason picker options.
		 *
		 * @return {Array<object>} The options.
		 */
		reasonOptions() {
			return this.activeReasons.map(r => ({ id: r.id, label: r.label || r.code }))
		},
		/**
		 * The selected overall reason option.
		 *
		 * @return {object|null} The option.
		 */
		selectedReason() {
			return this.reasonOptions.find(o => o.id === this.refund.refundReason) || null
		},
		/**
		 * Candidate lines that are selected for the refund (for the totals panel).
		 *
		 * @return {Array<object>} The selected lines with computed amounts.
		 */
		selectedLines() {
			return this.candidates.filter(c => c.selected)
		},
	},
	async mounted() {
		this.loading = true
		try {
			await this.loadTransactions()
			await this.loadReasons()
			if (this.isEdit) {
				await this.loadRefund()
			} else if (this.routeTransactionId) {
				this.refund.originalTransaction = this.routeTransactionId
				await this.loadOriginalLines(this.routeTransactionId)
			}
		} finally {
			this.loading = false
		}
	},
	methods: {
		/**
		 * Load candidate transactions for the picker.
		 */
		async loadTransactions() {
			try {
				await this.objectStore.fetchCollection('posTransaction', { _limit: 500 })
				this.transactions = this.objectStore.getCollection('posTransaction')?.results || []
			} catch {
				this.transactions = []
			}
		},
		/**
		 * Load the refund reasons.
		 */
		async loadReasons() {
			try {
				await this.objectStore.fetchCollection('refundReason', { _limit: 100 })
				this.reasons = this.objectStore.getCollection('refundReason')?.results || []
			} catch {
				this.reasons = []
			}
		},
		/**
		 * Load an existing refund and its lines (edit mode).
		 */
		async loadRefund() {
			const r = await this.objectStore.fetchObject('posRefund', this.refundId)
			this.refund = { ...r }
			await this.loadOriginalLines(this.refund.originalTransaction)

			await this.objectStore.fetchCollection('posRefundLine', { refund: this.refundId, _limit: 500 })
			const existing = (this.objectStore.getCollection('posRefundLine')?.results || [])
				.filter(l => l.refund === this.refundId)

			this.candidates = this.originalLines.map(original => {
				const match = existing.find(e => e.originalLine === original.id)
				if (match) {
					return {
						id: match.id,
						originalLine: original.id,
						selected: true,
						returnedQuantity: match.returnedQuantity,
						returnReason: match.returnReason,
						restock: match.restock ?? true,
						taxAmount: match.taxAmount || 0,
						lineTotal: match.lineTotal || 0,
						valid: true,
					}
				}
				return this.blankCandidate(original)
			})
		},
		/**
		 * Load the original transaction's lines and build candidate rows.
		 *
		 * @param {string} transactionId The transaction id.
		 */
		async loadOriginalLines(transactionId) {
			if (!transactionId) {
				this.originalLines = []
				this.candidates = []
				return
			}
			await this.objectStore.fetchCollection('posTransactionLine', { transaction: transactionId, _limit: 500 })
			this.originalLines = (this.objectStore.getCollection('posTransactionLine')?.results || [])
				.filter(l => l.transaction === transactionId)
				.sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
			this.candidates = this.originalLines.map(o => this.blankCandidate(o))
		},
		/**
		 * Build a blank (unselected) candidate for an original line.
		 *
		 * @param {object} original The original line.
		 * @return {object} The candidate.
		 */
		blankCandidate(original) {
			return {
				originalLine: original.id,
				selected: false,
				returnedQuantity: original.quantity ?? 1,
				returnReason: this.refund.refundReason || null,
				restock: true,
				taxAmount: 0,
				lineTotal: 0,
				valid: true,
			}
		},
		/**
		 * Resolve the original line object for a candidate.
		 *
		 * @param {string} id The original line id.
		 * @return {object} The original line.
		 */
		originalLineFor(id) {
			return this.originalLines.find(o => o.id === id) || {}
		},
		/**
		 * Replace a candidate after an edit.
		 *
		 * @param {number} index The candidate index.
		 * @param {object} line The updated candidate.
		 */
		updateCandidate(index, line) {
			const current = this.candidates[index]
			this.candidates[index] = { ...current, ...line }
		},
		/**
		 * Remove (deselect) a candidate.
		 *
		 * @param {number} index The candidate index.
		 */
		removeCandidate(index) {
			const current = this.candidates[index]
			this.candidates[index] = { ...current, selected: false }
		},
		/**
		 * Select all lines with full original quantity.
		 */
		selectAll() {
			this.candidates = this.candidates.map(c => {
				const original = this.originalLineFor(c.originalLine)
				const amounts = refundLineAmounts(original, original.quantity)
				return {
					...c,
					selected: true,
					returnedQuantity: original.quantity,
					returnReason: c.returnReason || this.refund.refundReason || null,
					taxAmount: amounts.taxAmount,
					lineTotal: amounts.lineTotal,
					valid: true,
				}
			})
		},
		/**
		 * Apply a transaction selection.
		 *
		 * @param {object|null} option The chosen transaction.
		 */
		async onTransactionSelect(option) {
			this.refund.originalTransaction = option ? option.id : null
			const tx = this.transactions.find(t => t.id === this.refund.originalTransaction)
			if (tx) {
				this.refund.paymentMethod = tx.paymentMethod || this.refund.paymentMethod
				this.refund.paymentReference = tx.paymentReference || this.refund.paymentReference
			}
			await this.loadOriginalLines(this.refund.originalTransaction)
		},
		/**
		 * Apply an overall reason selection.
		 *
		 * @param {object|null} option The chosen reason.
		 */
		onReasonSelect(option) {
			this.refund.refundReason = option ? option.id : null
		},
		/**
		 * Persist the refund header and its selected lines.
		 *
		 * Amounts are recomputed server-side on confirm; the client persists the
		 * editable header + line selections only.
		 */
		async save() {
			const selected = this.candidates.filter(c => c.selected)
			if (selected.length === 0) {
				showError(t('pipelinq', 'Select at least one item to return'))
				return
			}
			if (!this.refund.originalTransaction) {
				showError(t('pipelinq', 'Original receipt is required'))
				return
			}
			if (selected.some(c => c.valid === false)) {
				showError(t('pipelinq', 'Returned quantity may not exceed the original quantity'))
				return
			}

			this.saving = true
			try {
				const header = {
					...this.refund,
					status: this.refund.status || 'pending',
					cashier: this.refund.cashier || (window.OC?.getCurrentUser?.()?.uid ?? ''),
				}
				const savedRefund = await this.objectStore.saveObject('posRefund', header)
				if (!savedRefund) {
					showError(t('pipelinq', 'Failed to save refund.'))
					return
				}
				const refundId = savedRefund.id || this.refundId

				for (const candidate of selected) {
					const original = this.originalLineFor(candidate.originalLine)
					const amounts = refundLineAmounts(original, candidate.returnedQuantity)
					const payload = {
						refund: refundId,
						originalLine: candidate.originalLine,
						returnedQuantity: Number(candidate.returnedQuantity) || 0,
						returnReason: candidate.returnReason || this.refund.refundReason,
						restock: candidate.restock ?? true,
						taxAmount: amounts.taxAmount,
						lineTotal: amounts.lineTotal,
					}
					if (candidate.id) {
						payload.id = candidate.id
					}
					await this.objectStore.saveObject('posRefundLine', payload)
				}

				showSuccess(t('pipelinq', 'Refund saved.'))
				this.$router.push({ name: 'PosRefundDetail', params: { id: refundId } })
			} catch (e) {
				showError(t('pipelinq', 'Failed to save refund.'))
			} finally {
				this.saving = false
			}
		},
		/**
		 * Return to the refund list.
		 */
		goBack() {
			this.$router.push({ name: 'PosRefunds' })
		},
	},
}
</script>

<style scoped>
.pos-refund-form {
	padding: 20px;
	max-width: 1100px;
}

.pos-refund-form__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
}

.pos-refund-form__fields {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 16px;
	margin-bottom: 24px;
}

.pos-refund-form__select-all {
	margin-bottom: 12px;
}

.pos-refund-form__lines {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.pos-refund-form__lines th {
	text-align: left;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}

.pos-refund-form__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin: 16px 0;
}

.pos-refund-form__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 24px;
}
</style>
