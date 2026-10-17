<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<CnDetailPage
		:title="isEdit ? t('pipelinq', 'Edit refund') : t('pipelinq', 'New refund')"
		:breadcrumb="{ label: t('pipelinq', 'Refunds'), route: 'PosRefunds' }"
		:typePill="{ text: t('pipelinq', 'Draft'), variant: 'default' }"
		:loading="loading"
		:sidebar="{ enabled: false }"
		:showRefresh="false">
		<template #actions>
			<NcButton variant="primary" :disabled="saving" @click="save">
				<template #icon>
					<Check :size="20" />
				</template>
				{{ t('pipelinq', 'Save') }}
			</NcButton>
		</template>

		<div class="pos-refund">
			<div class="pos-refund__main">
				<CnDetailCard :title="t('pipelinq', 'Refund')">
					<div class="pos-refund-form__fields">
						<NcSelect
							:modelValue="selectedTransaction"
							:options="transactionOptions"
							:inputLabel="t('pipelinq', 'Original transaction')"
							:placeholder="t('pipelinq', 'Choose a transaction…')"
							label="label"
							:clearable="false"
							:disabled="lockedTransaction"
							@update:modelValue="onTransactionSelect" />
						<NcSelect
							:modelValue="selectedReason"
							:options="reasonOptions"
							:inputLabel="t('pipelinq', 'Refund reason')"
							:placeholder="t('pipelinq', 'Choose a reason…')"
							label="label"
							:clearable="false"
							@update:modelValue="onReasonSelect" />
					</div>
					<NcTextField
						v-model="refund.notes"
						:label="t('pipelinq', 'Notes')" />
				</CnDetailCard>

				<CnDetailCard :title="t('pipelinq', 'Items to take back')">
					<template v-if="originalLines.length">
						<div class="pos-refund-form__select-all">
							<NcButton variant="secondary" @click="selectAll">
								{{ t('pipelinq', 'Return all items') }}
							</NcButton>
						</div>
						<table class="pos-refund-form__lines">
							<thead>
								<tr>
									<th scope="col" />
									<th scope="col">{{ t('pipelinq', 'Description') }}</th>
									<th scope="col">{{ t('pipelinq', 'Original qty') }}</th>
									<th scope="col">{{ t('pipelinq', 'Unit price') }}</th>
									<th scope="col">{{ t('pipelinq', 'Returned qty') }}</th>
									<th scope="col">{{ t('pipelinq', 'Reason') }}</th>
									<th scope="col">{{ t('pipelinq', 'Restock') }}</th>
									<th scope="col">{{ t('pipelinq', 'Refund total') }}</th>
									<th scope="col" />
								</tr>
							</thead>
							<tbody>
								<PosRefundLineRow
									v-for="(candidate, index) in candidates"
									:key="candidate.originalLine"
									:line="candidate"
									:originalLine="originalLineFor(candidate.originalLine)"
									:reasons="activeReasons"
									@update:line="updateCandidate(index, $event)"
									@remove="removeCandidate(index)" />
							</tbody>
						</table>
						<p class="pos-refund__hint">
							{{ t('pipelinq', 'The returned quantity cannot exceed the original quantity. Choose at least one item to take back.') }}
						</p>
					</template>
					<p v-else class="pos-refund-form__empty">
						{{
							t(
								'pipelinq',
								'Select an original transaction to choose items to refund.',
							)
						}}
					</p>
				</CnDetailCard>
			</div>

			<div class="pos-refund__side">
				<CnDetailCard :title="t('pipelinq', 'Refund total')">
					<PosRefundTotalsPanel :lines="selectedLines" />
				</CnDetailCard>
				<CnDetailCard v-if="originalTransaction" :title="t('pipelinq', 'Original receipt')">
					<dl class="pos-refund__facts">
						<dt>{{ t('pipelinq', 'Identifier') }}</dt>
						<dd>{{ originalTransaction.reference || '-' }}</dd>
						<dt>{{ t('pipelinq', 'Date') }}</dt>
						<dd>{{ formatDate(originalTransaction.settledAt || originalTransaction.confirmedAt) }}</dd>
						<dt>{{ t('pipelinq', 'Total') }}</dt>
						<dd>{{ formatEur(originalTransaction.total) }}</dd>
					</dl>
				</CnDetailCard>
				<CnDetailCard :title="t('pipelinq', 'After saving')">
					<p class="pos-refund__after">
						{{ t('pipelinq', 'The refund waits for confirmation. A manager confirms or rejects it.') }}
					</p>
				</CnDetailCard>
			</div>
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDetailCard, CnDetailPage } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import PosRefundLineRow from '../../components/pos/PosRefundLineRow.vue'
import PosRefundTotalsPanel from '../../components/pos/PosRefundTotalsPanel.vue'
import { formatEur, refundLineAmounts } from '../../services/posTotals.js'
import { useObjectStore } from '../../store/modules/object.js'
import { collectionRows } from '../../utils/collectionRows.js'

export default {
	name: 'PosRefundForm',
	components: {
		CnDetailCard,
		CnDetailPage,
		Check,
		NcButton,
		NcTextField,
		NcSelect,
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
			refund: {
				status: 'pending',
				notes: '',
				originalTransaction: null,
				refundReason: null,
			},

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
				.filter((tx) => ['confirmed', 'settled'].includes(tx.status))
				.map((tx) => ({ id: tx.id, label: tx.reference || tx.id }))
		},

		/**
		 * The selected transaction option.
		 *
		 * @return {object|null} The option.
		 */
		selectedTransaction() {
			return (
				this.transactionOptions.find(
					(o) => o.id === this.refund.originalTransaction,
				) || null
			)
		},

		/**
		 * Active refundReason objects for the pickers.
		 *
		 * @return {Array<object>} The reasons.
		 */
		activeReasons() {
			return this.reasons.filter((r) => r.isActive !== false)
		},

		/**
		 * Overall reason picker options.
		 *
		 * @return {Array<object>} The options.
		 */
		reasonOptions() {
			return this.activeReasons.map((r) => ({
				id: r.id,
				label: r.label || r.code,
			}))
		},

		/**
		 * The selected overall reason option.
		 *
		 * @return {object|null} The option.
		 */
		selectedReason() {
			return (
				this.reasonOptions.find((o) => o.id === this.refund.refundReason)
				|| null
			)
		},

		/**
		 * Candidate lines that are selected for the refund (for the totals panel).
		 *
		 * @return {Array<object>} The selected lines with computed amounts.
		 */
		/**
		 * The transaction this refund takes items back from.
		 *
		 * @return {object|null}
		 * @spec openspec/changes/screens-kassa-till-board/specs/pos-transaction-core/spec.md
		 */
		originalTransaction() {
			return this.transactions.find((tx) => tx.id === this.refund.originalTransaction) || null
		},

		selectedLines() {
			return this.candidates.filter((c) => c.selected)
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
		formatEur,

		/**
		 * A date-time as a short local date and time.
		 *
		 * @param {string} value The date-time.
		 * @return {string}
		 */
		formatDate(value) {
			const date = new Date(String(value || ''))
			return !value || Number.isNaN(date.getTime())
				? '-'
				: new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(date)
		},

		/**
		 * Load candidate transactions for the picker.
		 */
		async loadTransactions() {
			try {
				await this.objectStore.fetchCollection('posTransaction', {
					_limit: 500,
				})
				this.transactions =
					collectionRows(this.objectStore.getCollection('posTransaction'))
			} catch {
				this.transactions = []
			}
		},

		/**
		 * Load the refund reasons.
		 */
		async loadReasons() {
			try {
				await this.objectStore.fetchCollection('refundReason', {
					_limit: 100,
				})
				this.reasons =
					collectionRows(this.objectStore.getCollection('refundReason'))
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

			await this.objectStore.fetchCollection('posRefundLine', {
				refund: this.refundId,
				_limit: 500,
			})
			const existing = (
				collectionRows(this.objectStore.getCollection('posRefundLine'))
			).filter((l) => l.refund === this.refundId)

			this.candidates = this.originalLines.map((original) => {
				const match = existing.find((e) => e.originalLine === original.id)
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
			await this.objectStore.fetchCollection('posTransactionLine', {
				transaction: transactionId,
				_limit: 500,
			})
			this.originalLines = (
				collectionRows(this.objectStore.getCollection('posTransactionLine'))
			)
				.filter((l) => l.transaction === transactionId)
				.sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
			this.candidates = this.originalLines.map((o) => this.blankCandidate(o))
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
			return this.originalLines.find((o) => o.id === id) || {}
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
			this.candidates = this.candidates.map((c) => {
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
			const tx = this.transactions.find(
				(t) => t.id === this.refund.originalTransaction,
			)
			if (tx) {
				this.refund.paymentMethod =
					tx.paymentMethod || this.refund.paymentMethod
				this.refund.paymentReference =
					tx.paymentReference || this.refund.paymentReference
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
		 *
		 * @spec openspec/specs/pos-refund-return/spec.md#REQ-REF-003
		 */
		async save() {
			const selected = this.candidates.filter((c) => c.selected)
			if (selected.length === 0) {
				showError(t('pipelinq', 'Select at least one item to return'))
				return
			}
			if (!this.refund.originalTransaction) {
				showError(t('pipelinq', 'Original receipt is required'))
				return
			}
			if (selected.some((c) => c.valid === false)) {
				showError(
					t(
						'pipelinq',
						'Returned quantity may not exceed the original quantity',
					),
				)
				return
			}

			this.saving = true
			try {
				const header = {
					...this.refund,
					status: this.refund.status || 'pending',
					cashier:
						this.refund.cashier
						|| (window.OC?.getCurrentUser?.()?.uid ?? ''),
				}
				const savedRefund = await this.objectStore.saveObject(
					'posRefund',
					header,
				)
				if (!savedRefund) {
					showError(t('pipelinq', 'Failed to save refund.'))
					return
				}
				const refundId = savedRefund.id || this.refundId

				for (const candidate of selected) {
					const original = this.originalLineFor(candidate.originalLine)
					const amounts = refundLineAmounts(
						original,
						candidate.returnedQuantity,
					)
					const payload = {
						refund: refundId,
						originalLine: candidate.originalLine,
						returnedQuantity: Number(candidate.returnedQuantity) || 0,
						returnReason:
							candidate.returnReason || this.refund.refundReason,

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
				this.$router.push({
					name: 'PosRefundDetail',
					params: { id: refundId },
				})
			} catch {
				showError(t('pipelinq', 'Failed to save refund.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.pos-refund {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 340px;
	gap: 20px;
	align-items: start;
}

.pos-refund__main,
.pos-refund__side {
	display: flex;
	flex-direction: column;
	gap: 20px;
	min-width: 0;
}

.pos-refund__facts {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 6px 16px;
	margin: 0;
}

.pos-refund__facts dt {
	color: var(--color-text-maxcontrast);
}

.pos-refund__facts dd {
	margin: 0;
}

.pos-refund__hint,
.pos-refund__after {
	margin: 12px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

@media (max-width: 1100px) {
	.pos-refund {
		grid-template-columns: minmax(0, 1fr);
	}
}

.pos-refund-form__fields {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 16px;
	margin-bottom: 16px;
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
	text-align: start;
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
</style>
