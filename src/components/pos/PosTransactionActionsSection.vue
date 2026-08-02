<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS transaction in-body section (kind:'section') for the declarative
  - type:"detail" PosTransactionDetail page (pipelinq-pos-mdm-detail-declarative).
  - The transaction's flat fields auto-render via CnObjectDataWidget and the line
  - items render as a relatedCollections table (posTransactionLine, FK transaction);
  - this section carries everything no declarative primitive can express:
  -   1. the status-gated action toolbar (Bevestigen / Parkeren / Hervatten /
  -      Afrekenen / Retour registreren / Terugboeken / Print / Email Receipt),
  -      which POSTs to bespoke /api/pos-transactions/{id}/{action} endpoints with
  -      side-effects — NOT OR /transition, and posTransaction has no
  -      x-openregister-lifecycle, so CnLifecycleActions cannot drive them;
  -   2. the BTW (tax) breakdown card + totals panel (computed over the lines);
  -   3. the interactive TenderEntryPanel (live add/remove split tenders, recompute);
  -   4. the PaymentStatusCard (provider capture/refund);
  -   5. the print/email receipt modals.
  -
  - Self-fetches the transaction + its lines by id (passed as `transactionId` via
  - @objectId, with a cnSectionContext inject fallback) so the toolbar gating and
  - totals stay in sync after an action / tender change.
  -
  - @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-002
  - @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-009
  -->
<template>
	<div class="pos-tx-section">
		<NcLoadingIcon v-if="loading" :size="24" />
		<template v-else>
			<section v-if="hasActions" class="pos-tx-section__actions">
				<NcButton v-if="canEdit" variant="secondary" @click="edit">
					{{ t('pipelinq', 'Edit') }}
				</NcButton>
				<NcButton v-if="canConfirm"
					variant="primary"
					:disabled="busy || lineCount === 0"
					@click="confirm">
					{{ t('pipelinq', 'Confirm') }}
				</NcButton>
				<NcButton v-if="canPark"
					variant="secondary"
					:disabled="busy"
					@click="park">
					{{ t('pipelinq', 'Park') }}
				</NcButton>
				<NcButton v-if="canResume"
					variant="primary"
					:disabled="busy"
					@click="resume">
					{{ t('pipelinq', 'Resume') }}
				</NcButton>
				<NcButton v-if="canSettle"
					variant="primary"
					:disabled="busy"
					@click="settle">
					{{ t('pipelinq', 'Check out') }}
				</NcButton>
				<NcButton v-if="canRegisterReturn"
					variant="secondary"
					@click="registerReturn">
					{{ t('pipelinq', 'Register refund') }}
				</NcButton>
				<NcButton v-if="canRefund"
					variant="error"
					:disabled="busy"
					@click="showRefund = true">
					{{ t('pipelinq', 'Reverse') }}
				</NcButton>
				<NcButton v-if="canIssueReceipt"
					variant="secondary"
					@click="showPrint = true">
					{{ t('pipelinq', 'Print Receipt') }}
				</NcButton>
				<NcButton v-if="canIssueReceipt"
					variant="secondary"
					@click="showEmail = true">
					{{ t('pipelinq', 'Email Receipt') }}
				</NcButton>
			</section>

			<CnDetailCard :title="t('pipelinq', 'Tax breakdown')">
				<TaxBreakdownCard :transaction="transaction" />
				<PosTotalsPanel :lines="lines" :price-mode="priceMode" />
			</CnDetailCard>

			<CnDetailCard
				v-if="canShowTenderPanel"
				:title="t('pipelinq', 'Tenders')">
				<TenderEntryPanel
					:transaction-id="resolvedId"
					:transaction-status="status"
					@changed="onTenderChanged" />
			</CnDetailCard>

			<CnDetailCard
				v-if="hasPaymentInfo"
				:title="t('pipelinq', 'Payment')">
				<PaymentStatusCard
					:transaction="transaction"
					:is-manager="canRefund"
					@updated="onPaymentUpdated" />
			</CnDetailCard>

			<PosRefundDialog
				v-if="showRefund"
				:submitting="busy"
				@close="showRefund = false"
				@confirm="refund" />

			<PrintReceiptModal
				v-if="showPrint"
				:transaction-id="resolvedId"
				:templates="receiptTemplates"
				@close="showPrint = false"
				@printed="onReceiptIssued" />

			<EmailReceiptModal
				v-if="showEmail"
				:transaction-id="resolvedId"
				:templates="receiptTemplates"
				@close="showEmail = false"
				@sent="onReceiptIssued" />
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnDetailCard } from '@conduction/nextcloud-vue'
import PosTotalsPanel from './PosTotalsPanel.vue'
import TaxBreakdownCard from './TaxBreakdownCard.vue'
import PaymentStatusCard from './PaymentStatusCard.vue'
import TenderEntryPanel from './TenderEntryPanel.vue'
import PosRefundDialog from '../../modals/PosRefundDialog.vue'
import PrintReceiptModal from '../../modals/PrintReceiptModal.vue'
import EmailReceiptModal from '../../modals/EmailReceiptModal.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PosTransactionActionsSection',
	components: {
		NcButton,
		NcLoadingIcon,
		CnDetailCard,
		PosTotalsPanel,
		TaxBreakdownCard,
		PaymentStatusCard,
		TenderEntryPanel,
		PosRefundDialog,
		PrintReceiptModal,
		EmailReceiptModal,
	},
	inject: {
		cnSectionContext: { default: null },
	},
	props: {
		/** The transaction id (token-resolved from @objectId by CnBodySections). */
		transactionId: {
			type: String,
			default: '',
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
			receiptTemplates: [],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		/** The resolved transaction id — prop wins, else the injected section context. */
		resolvedId() {
			if (this.transactionId) {
				return this.transactionId
			}
			const ctx = this.cnSectionContext
			const bag = (ctx && typeof ctx === 'object' && 'value' in ctx) ? ctx.value : ctx
			return (bag && bag.objectId) || ''
		},
		status() {
			return this.transaction.status || 'draft'
		},
		priceMode() {
			return this.transaction.priceMode === 'incl' ? 'incl' : 'excl'
		},
		lineCount() {
			return this.lines.length
		},
		/**
		 * Whether the current user is treated as a manager in the UI. Server-side
		 * authorization is authoritative; this only hides the button for clearly
		 * non-privileged users. NC admins are always managers.
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
		canRefund() {
			return ['confirmed', 'settled'].includes(this.status) && this.isManager
		},
		/**
		 * Whether to render the tender entry panel. Shown whenever the transaction
		 * has an id; the panel itself enforces read-only on settled/refunded.
		 *
		 * @return {boolean} Whether to render the tender panel.
		 *
		 * @spec openspec/changes/pos-split-tender/specs.md#REQ-PST-002
		 */
		canShowTenderPanel() {
			return !!this.resolvedId
		},
		canRegisterReturn() {
			return ['confirmed', 'settled'].includes(this.status)
		},
		canIssueReceipt() {
			return ['confirmed', 'settled', 'refunded'].includes(this.status)
		},
		/**
		 * Whether the transaction has any payment metadata to display.
		 *
		 * @return {boolean} True when the payment card should render.
		 *
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-009
		 */
		hasPaymentInfo() {
			return !!(this.transaction.paymentProvider
				|| this.transaction.paymentSessionId
				|| this.transaction.paymentStatus
				|| this.transaction.paymentMethod)
		},
		/**
		 * Whether any action button is visible for the current status.
		 *
		 * @return {boolean}
		 */
		hasActions() {
			return this.canEdit || this.canConfirm || this.canPark || this.canResume
				|| this.canSettle || this.canRegisterReturn || this.canRefund || this.canIssueReceipt
		},
	},
	watch: {
		resolvedId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},
	methods: {
		/**
		 * Load the transaction and its lines so the toolbar gating + totals render.
		 */
		async load() {
			if (!this.resolvedId) {
				return
			}
			this.loading = true
			try {
				this.transaction = await this.objectStore.fetchObject('posTransaction', this.resolvedId) || {}
				await this.objectStore.fetchCollection('posTransactionLine', { transaction: this.resolvedId, _limit: 500 })
				const rows = this.objectStore.getCollection('posTransactionLine')?.results || []
				this.lines = rows
					.filter(l => l.transaction === this.resolvedId)
					.sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
				await this.loadReceiptTemplates()
			} catch (err) {
				showError(err?.response?.data?.error || t('pipelinq', 'Could not load transaction.'))
			} finally {
				this.loading = false
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
		async onReceiptIssued() {
			await this.load()
		},
		async onPaymentUpdated() {
			await this.load()
		},
		async onTenderChanged() {
			await this.load()
		},
		edit() {
			this.$router.push({ name: 'PosTransactionEdit', params: { id: this.resolvedId } })
		},
		registerReturn() {
			this.$router.push({ name: 'PosRefundNewFromTransaction', params: { transactionId: this.resolvedId } })
		},
		/**
		 * Call a lifecycle action endpoint and reload.
		 *
		 * @param {string} action The action path segment.
		 * @param {object} body The optional request body.
		 * @param {string} successMessage The success toast.
		 * @return {Promise<boolean>} Whether the action succeeded.
		 */
		async lifecycle(action, body, successMessage) {
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/pos-transactions/${this.resolvedId}/${action}`),
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
.pos-tx-section {
	display: flex;
	flex-direction: column;
	gap: 16px;
}
.pos-tx-section__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
</style>
