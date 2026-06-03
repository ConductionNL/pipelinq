<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<CnDetailPage
		:title="transaction.reference || t('pipelinq', 'Transaction')"
		:subtitle="t('pipelinq', 'POS transaction')"
		:back-route="{ name: 'PosTransactions' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="!loading"
		object-type="pipelinq_posTransaction"
		:object-id="transactionId"
		:sidebar-props="sidebarProps">
		<template #actions>
			<NcButton v-if="canEdit" type="secondary" @click="edit">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton v-if="canConfirm"
				type="primary"
				:disabled="busy || lineCount === 0"
				@click="confirm">
				{{ t('pipelinq', 'Bevestigen') }}
			</NcButton>
			<NcButton v-if="canPark"
				type="secondary"
				:disabled="busy"
				@click="park">
				{{ t('pipelinq', 'Parkeren') }}
			</NcButton>
			<NcButton v-if="canResume"
				type="primary"
				:disabled="busy"
				@click="resume">
				{{ t('pipelinq', 'Hervatten') }}
			</NcButton>
			<NcButton v-if="canSettle"
				type="primary"
				:disabled="busy"
				@click="settle">
				{{ t('pipelinq', 'Afrekenen') }}
			</NcButton>
			<NcButton v-if="canRegisterReturn"
				type="secondary"
				@click="registerReturn">
				{{ t('pipelinq', 'Retour registreren') }}
			</NcButton>
			<NcButton v-if="canRefund"
				type="error"
				:disabled="busy"
				@click="showRefund = true">
				{{ t('pipelinq', 'Terugboeken') }}
			</NcButton>
			<NcButton v-if="canIssueReceipt"
				type="secondary"
				@click="showPrint = true">
				{{ t('pipelinq', 'Print Receipt') }}
			</NcButton>
			<NcButton v-if="canIssueReceipt"
				type="secondary"
				@click="showEmail = true">
				{{ t('pipelinq', 'Email Receipt') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Transaction information')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Reference') }}</label>
					<span>{{ transaction.reference || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Cashier') }}</label>
					<span>{{ transaction.cashier || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Terminal') }}</label>
					<span>{{ transaction.terminalId || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<CnStatusBadge :status="transaction.status" :label="statusLabel" />
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Price mode') }}</label>
					<span>{{ priceModeLabel }}</span>
				</div>
				<div v-if="transaction.notes" class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Notes') }}</label>
					<span>{{ transaction.notes }}</span>
				</div>
				<div v-if="transaction.refundReason" class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Refund reason') }}</label>
					<span>{{ transaction.refundReason }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Line items')">
			<table class="pos-detail__lines">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Description') }}</th>
						<th class="num">
							{{ t('pipelinq', 'Qty') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'Unit price') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'Discount %') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'VAT') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'Line total') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="line in lines" :key="line.id">
						<td>{{ line.description }}</td>
						<td class="num">
							{{ line.quantity }}
						</td>
						<td class="num">
							{{ formatEur(line.unitPrice) }}
						</td>
						<td class="num">
							{{ line.discount || 0 }}%
						</td>
						<td class="num">
							{{ line.taxRate }}%
						</td>
						<td class="num">
							{{ formatEur(line.lineTotal) }}
						</td>
					</tr>
					<tr v-if="lines.length === 0">
						<td colspan="6" class="empty">
							{{ t('pipelinq', 'No line items.') }}
						</td>
					</tr>
				</tbody>
			</table>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Tax breakdown')">
			<TaxBreakdownCard :transaction="transaction" />
			<PosTotalsPanel :lines="lines" :price-mode="priceMode" />
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Tenders')">
			<template #actions>
				<NcButton v-if="canAddTender"
					type="secondary"
					:disabled="busy"
					@click="showAddTender = true">
					{{ t('pipelinq', 'Add Tender') }}
				</NcButton>
			</template>
			<TendersCard
				:tenders="tenders"
				:tender-types="tenderTypes"
				:reconciliation="reconciliation"
				:transaction-total="transactionTotal"
				:can-remove="canRemoveTender"
				@remove="removeTender" />
		</CnDetailCard>

		<AddTenderModal
			v-if="showAddTender"
			:transaction-id="transactionId"
			:transaction-total="transactionTotal"
			:current-tender-sum="reconciliation.tenderSum || 0"
			@close="showAddTender = false"
			@added="onTenderAdded" />

		<PosRefundDialog
			v-if="showRefund"
			:submitting="busy"
			@close="showRefund = false"
			@confirm="refund" />

		<PrintReceiptModal
			v-if="showPrint"
			:transaction-id="transactionId"
			:templates="receiptTemplates"
			@close="showPrint = false"
			@printed="onReceiptIssued" />

		<EmailReceiptModal
			v-if="showEmail"
			:transaction-id="transactionId"
			:templates="receiptTemplates"
			@close="showEmail = false"
			@sent="onReceiptIssued" />
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnDetailPage, CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import PosTotalsPanel from '../../components/pos/PosTotalsPanel.vue'
import TaxBreakdownCard from '../../components/pos/TaxBreakdownCard.vue'
import TendersCard from '../../components/pos/TendersCard.vue'
import PosRefundDialog from '../../modals/PosRefundDialog.vue'
import AddTenderModal from '../../modals/AddTenderModal.vue'
import PrintReceiptModal from '../../modals/PrintReceiptModal.vue'
import EmailReceiptModal from '../../modals/EmailReceiptModal.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatEur } from '../../services/posTotals.js'

const STATUS_LABELS = {
	draft: 'Concept',
	parked: 'Geparkeerd',
	confirmed: 'Bevestigd',
	settled: 'Afgerekend',
	refunded: 'Teruggeboekt',
}

export default {
	name: 'PosTransactionDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnStatusBadge,
		PosTotalsPanel,
		TaxBreakdownCard,
		TendersCard,
		PosRefundDialog,
		AddTenderModal,
		PrintReceiptModal,
		EmailReceiptModal,
	},
	props: {
		posTransactionId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			transaction: {},
			lines: [],
			loading: false,
			busy: false,
			showRefund: false,
			showPrint: false,
			showEmail: false,
			showAddTender: false,
			receiptTemplates: [],
			tenders: [],
			tenderTypes: [],
			reconciliation: {},
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		/**
		 * The transaction id from the prop or route.
		 *
		 * @return {string|null} The id.
		 */
		transactionId() {
			return this.posTransactionId || this.$route.params.id || null
		},
		/**
		 * The current status.
		 *
		 * @return {string} The status.
		 */
		status() {
			return this.transaction.status || 'draft'
		},
		/**
		 * Translated status label.
		 *
		 * @return {string} The label.
		 */
		statusLabel() {
			return t('pipelinq', STATUS_LABELS[this.status] || this.status)
		},
		/**
		 * The transaction's price mode ('excl' default).
		 *
		 * @return {string} The price mode.
		 */
		priceMode() {
			return this.transaction.priceMode === 'incl' ? 'incl' : 'excl'
		},
		/**
		 * Human-readable price mode label.
		 *
		 * @return {string} The label.
		 */
		priceModeLabel() {
			return this.priceMode === 'incl'
				? t('pipelinq', 'Prijzen incl. BTW')
				: t('pipelinq', 'Prijzen excl. BTW')
		},
		/**
		 * Number of line items.
		 *
		 * @return {number} The count.
		 */
		lineCount() {
			return this.lines.length
		},
		/**
		 * Whether the current user is treated as a manager in the UI.
		 *
		 * Server-side authorization is authoritative; this only hides the button
		 * for clearly non-privileged users. NC admins are always managers.
		 *
		 * @return {boolean} Whether to show manager-only actions.
		 */
		isManager() {
			return typeof window.OC?.isUserAdmin === 'function' ? window.OC.isUserAdmin() : false
		},
		canEdit() {
			return ['draft', 'parked'].includes(this.status)
		},
		canConfirm() {
			return ['draft', 'parked'].includes(this.status)
		},
		canPark() {
			return this.status === 'draft'
		},
		canResume() {
			return this.status === 'parked'
		},
		canSettle() {
			return this.status === 'confirmed'
		},
		/**
		 * The transaction's server-computed total.
		 *
		 * @return {number} The total.
		 */
		transactionTotal() {
			return parseFloat(this.transaction.total) || 0
		},
		/**
		 * Whether tenders may still be added (anything but a settled/refunded sale).
		 *
		 * @return {boolean} Whether to show the add-tender action.
		 */
		canAddTender() {
			return ['draft', 'parked', 'confirmed'].includes(this.status)
		},
		/**
		 * Whether tenders may be removed (only before settlement).
		 *
		 * @return {boolean} Whether the remove control is enabled.
		 */
		canRemoveTender() {
			return ['draft', 'parked', 'confirmed'].includes(this.status)
		},
		canRefund() {
			return ['confirmed', 'settled'].includes(this.status) && this.isManager
		},
		/**
		 * Whether a structured return can be registered (confirmed / settled only).
		 *
		 * @return {boolean} Whether to show the return action.
		 */
		canRegisterReturn() {
			return ['confirmed', 'settled'].includes(this.status)
		},
		/**
		 * Whether a receipt may be issued (only fiscally-final transactions).
		 *
		 * @return {boolean} Whether to show the receipt actions.
		 */
		canIssueReceipt() {
			return ['confirmed', 'settled', 'refunded'].includes(this.status)
		},
		/**
		 * Sidebar props (files / notes / audit trail).
		 *
		 * @return {object} The props.
		 */
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry?.posTransaction || {}
			return {
				title: t('pipelinq', 'POS transaction'),
				register: config.register || '',
				schema: config.schema || '',
			}
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		formatEur,
		/**
		 * Load the transaction and its lines.
		 */
		async load() {
			this.loading = true
			try {
				this.transaction = await this.objectStore.fetchObject('posTransaction', this.transactionId) || {}
				await this.objectStore.fetchCollection('posTransactionLine', { transaction: this.transactionId, _limit: 500 })
				const rows = this.objectStore.getCollection('posTransactionLine')?.results || []
				this.lines = rows
					.filter(l => l.transaction === this.transactionId)
					.sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
				await this.loadReceiptTemplates()
				await this.loadTenders()
			} finally {
				this.loading = false
			}
		},
		/**
		 * Load the tenders and server reconciliation for this transaction, plus
		 * the active tender types (used to resolve display names in the table).
		 */
		async loadTenders() {
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/pos-transactions/${this.transactionId}/tenders`),
					{ headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' } },
				)
				const data = await response.json().catch(() => ({}))
				this.tenders = Array.isArray(data.tenders) ? data.tenders : []
				this.reconciliation = data.reconciliation || {}
			} catch (e) {
				this.tenders = []
				this.reconciliation = {}
			}
			await this.loadTenderTypes()
		},
		/**
		 * Load the active tender types for display-name resolution.
		 */
		async loadTenderTypes() {
			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/pos/tender-types'),
					{ headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' } },
				)
				const data = await response.json().catch(() => ({}))
				this.tenderTypes = Array.isArray(data.tenderTypes) ? data.tenderTypes : []
			} catch (e) {
				this.tenderTypes = []
			}
		},
		/**
		 * Refresh the tenders after one is added in the modal.
		 */
		async onTenderAdded() {
			this.showAddTender = false
			showSuccess(t('pipelinq', 'Tender added.'))
			await this.loadTenders()
		},
		/**
		 * Remove a tender after confirmation.
		 *
		 * @param {object} tender The tender to remove.
		 */
		async removeTender(tender) {
			if (!window.confirm(t('pipelinq', 'Remove this tender?'))) {
				return
			}
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/pos-transactions/${this.transactionId}/tenders/${tender.id}`),
					{
						method: 'DELETE',
						headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' },
					},
				)
				if (!response.ok) {
					const data = await response.json().catch(() => ({}))
					showError(data.error || t('pipelinq', 'Could not remove the tender.'))
					return
				}
				showSuccess(t('pipelinq', 'Tender removed.'))
				await this.loadTenders()
			} catch (e) {
				showError(t('pipelinq', 'Could not remove the tender.'))
			} finally {
				this.busy = false
			}
		},
		/**
		 * Load the active receipt templates for the print/email modal pickers.
		 */
		async loadReceiptTemplates() {
			try {
				await this.objectStore.fetchCollection('receiptTemplate', { status: 'active', _limit: 100 })
				const rows = this.objectStore.getCollection('receiptTemplate')?.results || []
				this.receiptTemplates = rows.filter(tpl => (tpl.status || 'active') === 'active')
			} catch (e) {
				this.receiptTemplates = []
			}
		},
		/**
		 * Reload after a receipt is printed or emailed (the audit log changes).
		 */
		async onReceiptIssued() {
			await this.load()
		},
		/**
		 * Navigate to the editor.
		 */
		edit() {
			this.$router.push({ name: 'PosTransactionEdit', params: { id: this.transactionId } })
		},
		/**
		 * Navigate to the new-refund form pre-filled with this transaction.
		 */
		registerReturn() {
			this.$router.push({ name: 'PosRefundNewFromTransaction', params: { transactionId: this.transactionId } })
		},
		/**
		 * Call a lifecycle action endpoint and reload.
		 *
		 * @param {string} action The action path segment.
		 * @param {object} body The optional request body.
		 * @param {string} successMessage The success toast.
		 */
		async lifecycle(action, body, successMessage) {
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/pos-transactions/${this.transactionId}/${action}`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify(body || {}),
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					showError(data.error || t('pipelinq', 'Action failed.'))
					return false
				}
				showSuccess(successMessage)
				await this.load()
				return true
			} catch (e) {
				showError(t('pipelinq', 'Action failed.'))
				return false
			} finally {
				this.busy = false
			}
		},
		confirm() {
			this.lifecycle('confirm', {}, t('pipelinq', 'Transaction confirmed.'))
		},
		settle() {
			this.lifecycle('settle', {}, t('pipelinq', 'Transaction settled.'))
		},
		park() {
			this.lifecycle('park', {}, t('pipelinq', 'Transaction parked.'))
		},
		resume() {
			this.lifecycle('resume', {}, t('pipelinq', 'Transaction resumed.'))
		},
		/**
		 * Submit a refund with a reason.
		 *
		 * @param {string} reason The refund reason.
		 */
		async refund(reason) {
			const ok = await this.lifecycle('refund', { reason }, t('pipelinq', 'Transaction refunded.'))
			if (ok) {
				this.showRefund = false
			}
		},
	},
}
</script>

<style scoped>
.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.info-field--wide {
	grid-column: 1 / -1;
}

.info-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.pos-detail__lines {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.pos-detail__lines th {
	text-align: left;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.pos-detail__lines td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.num {
	text-align: right;
}

.empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
