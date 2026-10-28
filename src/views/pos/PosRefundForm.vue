<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -
  - A refund against a cash register transaction (PqKassaRetour): the
  - transaction, reason and notes, the items to take back with their
  - quantity, reason and restock, and beside them the refund total, what
  - happens after saving and the original receipt.
  -->
<template>
	<CnDetailPage
		:title="isEdit ? t('pipelinq', 'Edit refund') : t('pipelinq', 'New refund')"
		:object="headerRecord"
		:typePill="statusPill"
		:breadcrumb="breadcrumb"
		:headerMeta="headerMeta"
		:loading="loading"
		:actionsMenu="headerMenu"
		class="pos-refund-form">
		<template #actions>
			<NcButton
				variant="primary"
				class="pos-refund-form__save"
				:disabled="saving"
				@click="save">
				<template #icon>
					<Check :size="16" />
				</template>
				{{ t('pipelinq', 'Save') }}
			</NcButton>
		</template>

		<div class="pos-refund-form__layout">
			<div class="pos-refund-form__main">
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
							:inputLabel="t('pipelinq', 'Reason for the refund')"
							:placeholder="t('pipelinq', 'Choose a reason…')"
							label="label"
							:clearable="false"
							@update:modelValue="onReasonSelect" />
					</div>
					<NcTextArea
						v-model="refund.notes"
						class="pos-refund-form__notes"
						:label="t('pipelinq', 'Notes (optional)')"
						resize="vertical"
						rows="2" />
				</CnDetailCard>

				<CnDetailCard :title="t('pipelinq', 'Items to take back')">
					<template v-if="candidates.length">
						<NcCheckboxRadioSwitch
							class="pos-refund-form__all"
							:modelValue="allSelected"
							@update:modelValue="toggleAll">
							{{ t('pipelinq', 'Take back all items') }}
						</NcCheckboxRadioSwitch>
						<CnDataTable
							class="pos-refund-form__lines"
							:columns="lineColumns"
							:rows="lineRows"
							rowKey="originalLine">
							<template #column-selected="{ row }">
								<NcCheckboxRadioSwitch
									:modelValue="row.selected"
									:aria-label="t('pipelinq', 'Take back {item}', { item: row.description })"
									@update:modelValue="setSelected(row.index, $event)" />
							</template>
							<template #column-unitPrice="{ row }">
								{{ formatEur(row.unitPrice) }}
							</template>
							<template #column-returnedQuantity="{ row }">
								<NcInputField
									v-if="row.selected"
									class="pos-refund-form__qty"
									:modelValue="String(row.returnedQuantity)"
									type="number"
									:label="t('pipelinq', 'Returned quantity')"
									:labelVisible="false"
									:error="!row.valid"
									min="0.001"
									:max="String(row.quantity)"
									step="0.001"
									@update:modelValue="setQuantity(row.index, $event)" />
								<template v-else>
									0
								</template>
							</template>
							<template #column-returnReason="{ row }">
								<NcSelect
									v-if="row.selected"
									:modelValue="reasonOptions.find((o) => o.id === row.returnReason) || null"
									:options="reasonOptions"
									:inputLabel="t('pipelinq', 'Return reason')"
									:labelOutside="true"
									label="label"
									:clearable="false"
									@update:modelValue="setLine(row.index, { returnReason: $event ? $event.id : null })" />
							</template>
							<template #column-restock="{ row }">
								<NcCheckboxRadioSwitch
									:modelValue="row.restock"
									:disabled="!row.selected"
									:aria-label="t('pipelinq', 'Back in stock')"
									@update:modelValue="setLine(row.index, { restock: $event })" />
							</template>
						</CnDataTable>
						<p class="pos-refund-form__hint">
							{{ t('pipelinq', 'The returned quantity may not exceed the original quantity. Choose at least one item to take back.') }}
						</p>
					</template>
					<p v-else class="pos-refund-form__empty">
						{{ t('pipelinq', 'Select an original transaction to choose items to refund.') }}
					</p>
				</CnDetailCard>
			</div>

			<div class="pos-refund-form__side">
				<CnDetailCard :title="t('pipelinq', 'Total refund')">
					<CnDetailGrid layout="horizontal" :accent="false" :items="totalItems" />
				</CnDetailCard>

				<CnDetailCard :title="t('pipelinq', 'After saving')">
					<p class="pos-refund-form__text">
						{{ t('pipelinq', 'The refund waits for confirmation. A manager confirms or rejects it.') }}
					</p>
					<div class="pos-refund-form__outcomes">
						<span class="pos-refund-form__outcome">
							<Check :size="14" />
							{{ t('pipelinq', 'Confirm') }}
						</span>
						<span class="pos-refund-form__outcome">
							<Close :size="14" />
							{{ t('pipelinq', 'Reject') }}
						</span>
					</div>
					<p class="pos-refund-form__note">
						{{ t('pipelinq', 'Confirming pays the refund back, puts the restocked items back in stock and writes a signed audit entry.') }}
					</p>
				</CnDetailCard>

				<CnDetailCard v-if="transaction" :title="t('pipelinq', 'Original receipt')">
					<CnDetailGrid layout="horizontal" :accent="false" :items="receiptItems">
						<template #item-0="{ item }">
							<router-link :to="{ name: 'PosTransactionDetail', params: { id: transaction.id } }">
								{{ item.value }}
							</router-link>
						</template>
					</CnDetailGrid>
				</CnDetailCard>
			</div>
		</div>
	</CnDetailPage>
</template>

<script>
import { CnDataTable, CnDetailCard, CnDetailGrid, CnDetailPage } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcInputField,
	NcSelect,
	NcTextArea,
} from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import { collectionRows } from '../../services/collectionRows.js'
import { formatDate } from '../../services/localeUtils.js'
import { computeRefundTotals, formatEur, refundLineAmounts } from '../../services/posTotals.js'
import { useObjectStore } from '../../store/modules/object.js'

const TENDER_LABELS = {
	cash: 'Cash',
	card: 'Card',
	onAccount: 'On account',
}

export default {
	name: 'PosRefundForm',
	components: {
		Check,
		Close,
		CnDataTable,
		CnDetailCard,
		CnDetailGrid,
		CnDetailPage,
		NcButton,
		NcCheckboxRadioSwitch,
		NcInputField,
		NcSelect,
		NcTextArea,
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
			clientName: '',
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
		 * The chosen original transaction.
		 *
		 * @return {object|null} The transaction.
		 */
		transaction() {
			return this.transactions.find((tx) => tx.id === this.refund.originalTransaction) || null
		},

		/**
		 * Transaction picker options (only refundable: confirmed / settled),
		 * read as "reference · day · total".
		 *
		 * @return {Array<object>} The options.
		 */
		transactionOptions() {
			return this.transactions
				.filter((tx) => ['confirmed', 'settled'].includes(tx.status))
				.map((tx) => ({ id: tx.id, label: this.transactionLabel(tx) }))
		},

		/**
		 * The selected transaction option.
		 *
		 * @return {object|null} The option.
		 */
		selectedTransaction() {
			return this.transactionOptions.find((o) => o.id === this.refund.originalTransaction) || null
		},

		activeReasons() {
			return this.reasons.filter((r) => r.isActive !== false)
		},

		reasonOptions() {
			return this.activeReasons.map((r) => ({ id: r.id, label: r.label || r.code }))
		},

		selectedReason() {
			return this.reasonOptions.find((o) => o.id === this.refund.refundReason) || null
		},

		/**
		 * The record the header reads: the refund with the reference of the
		 * transaction it comes from and the customer's name.
		 *
		 * @return {object} The header record.
		 */
		headerRecord() {
			return {
				...this.refund,
				fromReference: this.transaction?.reference || '',
				clientName: this.clientName,
			}
		},

		/**
		 * The pill beside the breadcrumb: Draft until the refund is saved,
		 * then where it stands.
		 *
		 * @return {object} The pill.
		 */
		statusPill() {
			if (!this.isEdit) {
				return { text: t('pipelinq', 'Draft') }
			}
			return {
				field: 'status',
				labels: {
					pending: t('pipelinq', 'Awaiting confirmation'),
					confirmed: t('pipelinq', 'Confirmed'),
					rejected: t('pipelinq', 'Rejected'),
				},
			}
		},

		breadcrumb() {
			return { label: 'Refunds', route: 'PosRefunds', separator: '/' }
		},

		/**
		 * The refund's header holds only Save: no More menu with refresh or
		 * help links.
		 *
		 * @return {object} The CnDetailPage actions menu config.
		 * @spec openspec/changes/pos-refund-reads-the-store-rows/specs/pos-refund-return/spec.md#requirement-the-refund-header-holds-only-save
		 */
		headerMenu() {
			return { showRefresh: false, showHelpLinks: false }
		},

		headerMeta() {
			return t('pipelinq', 'from {fromReference} · {clientName}')
		},

		lineColumns() {
			return [
				{ key: 'selected', label: t('pipelinq', 'Take back') },
				{ key: 'description', label: t('pipelinq', 'Description') },
				{ key: 'quantity', label: t('pipelinq', 'Original quantity'), class: 'num', cellClass: 'num' },
				{ key: 'unitPrice', label: t('pipelinq', 'Unit price'), class: 'num', cellClass: 'num' },
				{ key: 'returnedQuantity', label: t('pipelinq', 'Quantity returned'), class: 'num', cellClass: 'num' },
				{ key: 'returnReason', label: t('pipelinq', 'Reason') },
				{ key: 'restock', label: t('pipelinq', 'Back in stock') },
			]
		},

		/**
		 * One row per line of the original transaction, with what is taken
		 * back of it.
		 *
		 * @return {Array<object>} The rows.
		 */
		lineRows() {
			return this.candidates.map((candidate, index) => {
				const original = this.originalLineFor(candidate.originalLine)
				return {
					...candidate,
					index,
					description: original.description,
					quantity: original.quantity,
					unitPrice: original.unitPrice,
				}
			})
		},

		allSelected() {
			return this.candidates.length > 0 && this.candidates.every((c) => c.selected)
		},

		/**
		 * The selected lines with their refund amounts.
		 *
		 * @return {Array<object>} The lines.
		 */
		selectedLines() {
			return this.candidates
				.filter((c) => c.selected)
				.map((c) => ({ ...c, ...refundLineAmounts(this.originalLineFor(c.originalLine), c.returnedQuantity) }))
		},

		totalItems() {
			const totals = computeRefundTotals(this.selectedLines)
			const rates = [...new Set(this.selectedLines.map((c) => this.originalLineFor(c.originalLine).taxRate).filter((r) => r !== undefined && r !== null && r !== ''))]
			const items = [
				{ label: t('pipelinq', 'Excl. VAT'), value: formatEur(totals.refundAmount) },
				{ label: rates.length === 1 ? t('pipelinq', 'VAT {rate}%', { rate: rates[0] }) : t('pipelinq', 'VAT'), value: formatEur(totals.totalTax) },
				{ label: t('pipelinq', 'Total refund'), value: formatEur(totals.total) },
			]
			const tender = this.transaction?.tenderType
			if (tender) {
				items.push({
					label: t('pipelinq', 'Refunded to'),
					value: t('pipelinq', '{method}, the original payment method', { method: t('pipelinq', TENDER_LABELS[tender] || tender) }),
				})
			}
			return items
		},

		receiptItems() {
			const tx = this.transaction || {}
			return [
				{ label: t('pipelinq', 'Reference number'), value: tx.reference || tx.id },
				{ label: t('pipelinq', 'Date'), value: this.formatDateTime(tx.confirmedAt || tx['@self']?.created) },
				{ label: t('pipelinq', 'Total'), value: formatEur(tx.total) },
				{ label: t('pipelinq', 'Cashier'), value: tx.cashier || '—' },
			]
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
			await this.loadClientName()
		} finally {
			this.loading = false
		}
	},

	methods: {
		formatEur,

		/**
		 * A transaction as the picker reads it: "POS-2026-1043 · 7 okt · € 96,41".
		 *
		 * @param {object} tx The transaction.
		 * @return {string} The label.
		 */
		transactionLabel(tx) {
			const day = formatDate(tx.confirmedAt || tx['@self']?.created).replace('.', '')
			return [tx.reference || tx.id, day, formatEur(tx.total)].filter((part) => part !== '').join(' · ')
		},

		/**
		 * A moment as "7 okt 2026, 11:43".
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string} The text, or a dash.
		 */
		formatDateTime(value) {
			if (!value) {
				return '—'
			}
			return formatDate(value, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).replace('.', '')
		},

		/**
		 * Loads the sales a refund can be made against.
		 *
		 * @spec openspec/changes/pos-refund-reads-the-store-rows/specs/pos-refund-return/spec.md#requirement-a-refund-reads-the-sales-and-lines-the-store-holds
		 */
		async loadTransactions() {
			try {
				await this.objectStore.fetchCollection('posTransaction', { _limit: 500 })
				this.transactions = collectionRows(this.objectStore.getCollection('posTransaction'))
			} catch {
				this.transactions = []
			}
		},

		/**
		 * Loads the refund reasons.
		 *
		 * @spec openspec/changes/pos-refund-reads-the-store-rows/specs/pos-refund-return/spec.md#requirement-a-refund-reads-the-sales-and-lines-the-store-holds
		 */
		async loadReasons() {
			try {
				await this.objectStore.fetchCollection('refundReason', { _limit: 100 })
				this.reasons = collectionRows(this.objectStore.getCollection('refundReason'))
			} catch {
				this.reasons = []
			}
		},

		/**
		 * The name of the customer of the chosen transaction, for the header.
		 *
		 * @return {Promise<void>}
		 */
		async loadClientName() {
			const tx = this.transaction
			this.clientName = ''
			const id = tx?.customer || tx?.client
			if (!id) {
				return
			}
			try {
				const record = await this.objectStore.fetchObject(tx.customer ? 'contact' : 'client', id)
				this.clientName = record?.name || [record?.firstName, record?.lastName].filter(Boolean).join(' ') || ''
			} catch {
				this.clientName = ''
			}
		},

		/**
		 * Loads the refund being edited with its lines.
		 *
		 * @spec openspec/changes/pos-refund-reads-the-store-rows/specs/pos-refund-return/spec.md#requirement-a-refund-reads-the-sales-and-lines-the-store-holds
		 */
		async loadRefund() {
			const r = await this.objectStore.fetchObject('posRefund', this.refundId)
			this.refund = { ...r }
			await this.loadOriginalLines(this.refund.originalTransaction)

			await this.objectStore.fetchCollection('posRefundLine', { refund: this.refundId, _limit: 500 })
			const existing = collectionRows(this.objectStore.getCollection('posRefundLine'))
				.filter((l) => l.refund === this.refundId)

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
						valid: true,
					}
				}
				return this.blankCandidate(original)
			})
		},

		/**
		 * Loads the lines of the sale being refunded.
		 *
		 * @param {string} transactionId The sale being refunded.
		 *
		 * @spec openspec/changes/pos-refund-reads-the-store-rows/specs/pos-refund-return/spec.md#requirement-a-refund-reads-the-sales-and-lines-the-store-holds
		 */
		async loadOriginalLines(transactionId) {
			if (!transactionId) {
				this.originalLines = []
				this.candidates = []
				return
			}
			await this.objectStore.fetchCollection('posTransactionLine', { transaction: transactionId, _limit: 500 })
			this.originalLines = collectionRows(this.objectStore.getCollection('posTransactionLine'))
				.filter((l) => l.transaction === transactionId)
				.sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
			this.candidates = this.originalLines.map((o) => this.blankCandidate(o))
		},

		blankCandidate(original) {
			return {
				originalLine: original.id,
				selected: false,
				returnedQuantity: original.quantity ?? 1,
				returnReason: this.refund.refundReason || null,
				restock: true,
				valid: true,
			}
		},

		originalLineFor(id) {
			return this.originalLines.find((o) => o.id === id) || {}
		},

		/**
		 * Change one line of the take-back list.
		 *
		 * @param {number} index The line.
		 * @param {object} changes The fields that change.
		 */
		setLine(index, changes) {
			this.candidates.splice(index, 1, { ...this.candidates[index], ...changes })
		},

		setSelected(index, selected) {
			const candidate = this.candidates[index]
			this.setLine(index, {
				selected,
				returnReason: candidate.returnReason || this.refund.refundReason || null,
			})
		},

		/**
		 * Set the returned quantity of a line, marked invalid above the
		 * original quantity or at zero.
		 *
		 * @param {number} index The line.
		 * @param {string} value The typed quantity.
		 */
		setQuantity(index, value) {
			const max = Number(this.originalLineFor(this.candidates[index].originalLine).quantity) || 0
			const quantity = Number(value)
			this.setLine(index, { returnedQuantity: value, valid: quantity > 0 && quantity <= max })
		},

		toggleAll(selected) {
			this.candidates = this.candidates.map((c) => {
				const original = this.originalLineFor(c.originalLine)
				return {
					...c,
					selected,
					returnedQuantity: original.quantity,
					returnReason: c.returnReason || this.refund.refundReason || null,
					valid: true,
				}
			})
		},

		async onTransactionSelect(option) {
			this.refund.originalTransaction = option ? option.id : null
			const tx = this.transaction
			if (tx) {
				this.refund.paymentMethod = tx.paymentMethod || this.refund.paymentMethod
				this.refund.paymentReference = tx.paymentReference || this.refund.paymentReference
			}
			await this.loadOriginalLines(this.refund.originalTransaction)
			await this.loadClientName()
		},

		onReasonSelect(option) {
			this.refund.refundReason = option ? option.id : null
		},

		async save() {
			const selected = this.selectedLines
			if (selected.length === 0) {
				showError(t('pipelinq', 'Select at least one item to return'))
				return
			}
			if (!this.refund.originalTransaction) {
				showError(t('pipelinq', 'Original receipt is required'))
				return
			}
			if (selected.some((c) => c.valid === false)) {
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
					const payload = {
						refund: refundId,
						originalLine: candidate.originalLine,
						returnedQuantity: Number(candidate.returnedQuantity) || 0,
						returnReason: candidate.returnReason || this.refund.refundReason,
						restock: candidate.restock ?? true,
						taxAmount: candidate.taxAmount,
						lineTotal: candidate.lineTotal,
					}
					if (candidate.id) {
						payload.id = candidate.id
					}
					await this.objectStore.saveObject('posRefundLine', payload)
				}

				showSuccess(t('pipelinq', 'Refund saved.'))
				this.$router.push({ name: 'PosRefundDetail', params: { id: refundId } })
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
.pos-refund-form__layout {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 296px;
	gap: 20px;
	align-items: start;
}

.pos-refund-form__main,
.pos-refund-form__side {
	display: flex;
	flex-direction: column;
	gap: 20px;
	min-width: 0;
}

.pos-refund-form__fields {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 16px;
	margin-bottom: 12px;
}

.pos-refund-form__save.pos-refund-form__save {
	order: 1;
	min-height: 34px;
	height: 34px;
	padding-inline: 12px;
}

.pos-refund-form__notes :deep(textarea) {
	height: 58px;
	min-height: 58px;
}

.pos-refund-form__qty {
	max-width: 80px;
	margin-inline-start: auto;
}

.pos-refund-form__hint,
.pos-refund-form__note {
	margin: 12px 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.pos-refund-form__text {
	margin: 0;
}

.pos-refund-form__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.pos-refund-form__outcomes {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.pos-refund-form__outcome {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	height: 34px;
	padding: 0 12px;
	border-radius: 17px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-weight: 600;
	font-size: 13px;
}

@media (max-width: 1024px) {
	.pos-refund-form__layout {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
