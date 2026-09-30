<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The PosTransactionDetail page's `actionsComponent`: the status-gated
  - transaction actions, rendered in the page header.
  -
  - They POST to the bespoke /api/pos-transactions/{id}/{action} endpoints,
  - which recompute totals server-side and emit CloudEvents. posTransaction has
  - no x-openregister-lifecycle, so CnLifecycleActions cannot drive them.
  -
  - "Edit line items" opens the cart editor. It sits beside the page's own Edit,
  - which opens the schema form: that form edits the transaction's fields but
  - cannot touch its lines.
  -
  - A successful action bumps `cn:page:refresh`, so every widget on the page
  - re-reads the transaction.
  -
  - @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
  -->
<template>
	<div
		v-if="hasActions"
		class="pos-tx-header-actions"
		data-testid="pos-tx-header-actions">
		<NcButton v-if="canEdit" variant="secondary" @click="edit">
			<template #icon>
				<Pencil :size="20" />
			</template>
			{{ t('pipelinq', 'Edit line items') }}
		</NcButton>
		<NcButton
			v-if="canPark"
			variant="secondary"
			:disabled="busy"
			@click="run('park', {}, t('pipelinq', 'Transaction parked.'))">
			{{ t('pipelinq', 'Park') }}
		</NcButton>
		<NcButton
			v-if="canResume"
			variant="secondary"
			:disabled="busy"
			@click="run('resume', {}, t('pipelinq', 'Transaction resumed.'))">
			{{ t('pipelinq', 'Resume') }}
		</NcButton>
		<NcButton
			v-if="canConfirm"
			variant="primary"
			:disabled="busy || lineCount === 0"
			@click="run('confirm', {}, t('pipelinq', 'Transaction confirmed.'))">
			{{ t('pipelinq', 'Confirm') }}
		</NcButton>
		<NcButton
			v-if="canSettle"
			variant="primary"
			:disabled="busy"
			@click="run('settle', {}, t('pipelinq', 'Transaction settled.'))">
			{{ t('pipelinq', 'Check out') }}
		</NcButton>
		<NcButton
			v-if="canIssueReceipt"
			variant="secondary"
			:disabled="busy"
			@click="openReceipt('print')">
			<template #icon>
				<Printer :size="20" />
			</template>
			{{ t('pipelinq', 'Print Receipt') }}
		</NcButton>
		<NcButton
			v-if="canIssueReceipt"
			variant="secondary"
			:disabled="busy"
			@click="openReceipt('email')">
			<template #icon>
				<EmailOutline :size="20" />
			</template>
			{{ t('pipelinq', 'Email Receipt') }}
		</NcButton>
		<NcButton
			v-if="canRegisterReturn"
			variant="secondary"
			@click="registerReturn">
			{{ t('pipelinq', 'Register refund') }}
		</NcButton>
		<NcButton
			v-if="canRefund"
			variant="error"
			:disabled="busy"
			@click="showRefund = true">
			{{ t('pipelinq', 'Reverse') }}
		</NcButton>

		<PosRefundDialog
			v-if="showRefund"
			:submitting="busy"
			@close="showRefund = false"
			@confirm="refund" />

		<PrintReceiptModal
			v-if="receiptMode === 'print'"
			:transactionId="transactionId"
			:templates="receiptTemplates"
			@close="receiptMode = ''"
			@printed="onReceiptIssued" />

		<EmailReceiptModal
			v-if="receiptMode === 'email'"
			:transactionId="transactionId"
			:templates="receiptTemplates"
			@close="receiptMode = ''"
			@sent="onReceiptIssued" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Printer from 'vue-material-design-icons/Printer.vue'
import EmailReceiptModal from '../../modals/EmailReceiptModal.vue'
import PosRefundDialog from '../../modals/PosRefundDialog.vue'
import PrintReceiptModal from '../../modals/PrintReceiptModal.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PosTransactionHeaderActions',
	components: {
		EmailOutline,
		EmailReceiptModal,
		NcButton,
		Pencil,
		PosRefundDialog,
		Printer,
		PrintReceiptModal,
	},

	// The slot also hands over schema / store / openEditForm; none of them
	// belong on the root element.
	inheritAttrs: false,

	props: {
		/** The transaction, from CnDetailPage's `#actions` slot. */
		object: {
			type: Object,
			default: null,
		},

		/** The transaction id, from CnDetailPage's `#actions` slot. */
		objectId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			busy: false,
			showRefund: false,
			receiptMode: '',
			receiptTemplates: [],
			lineCount: null,
		}
	},

	computed: {
		/**
		 * The transaction, or an empty object before it loads.
		 *
		 * @return {object} The transaction.
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
		 */
		transaction() {
			return this.object || {}
		},

		/**
		 * The transaction id, from the prop or the object.
		 *
		 * @return {string} The id, or ''.
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
		 */
		transactionId() {
			return this.objectId || this.transaction.id || ''
		},

		/**
		 * The transaction's lifecycle status.
		 *
		 * @return {string} The status, draft when unset.
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
		 */
		status() {
			return this.transaction.status || 'draft'
		},

		/**
		 * Whether the current user is treated as a manager in the UI. The server
		 * authorises the refund; this only hides the button for users who
		 * clearly cannot use it. Nextcloud admins are always managers.
		 *
		 * @return {boolean} Whether to show manager-only actions.
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-004
		 */
		isManager() {
			return typeof window.OC?.isUserAdmin === 'function'
				? window.OC.isUserAdmin()
				: false
		},

		/**
		 * Whether the transaction can still be edited.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
		 */
		canEdit() {
			return ['draft', 'parked'].includes(this.status)
		},

		/**
		 * Whether the transaction can be confirmed.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
		 */
		canConfirm() {
			return ['draft', 'parked'].includes(this.status)
		},

		/**
		 * Whether the transaction can be parked.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
		 */
		canPark() {
			return this.status === 'draft'
		},

		/**
		 * Whether a parked transaction can be resumed.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
		 */
		canResume() {
			return this.status === 'parked'
		},

		/**
		 * Whether the transaction can be settled.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
		 */
		canSettle() {
			return this.status === 'confirmed'
		},

		/**
		 * Whether a return can be registered against the transaction.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/pos-refund-return/spec.md#REQ-REF-002
		 */
		canRegisterReturn() {
			return ['confirmed', 'settled'].includes(this.status)
		},

		/**
		 * Whether a manager can refund the whole transaction.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-004
		 */
		canRefund() {
			return ['confirmed', 'settled'].includes(this.status) && this.isManager
		},

		/**
		 * Whether a receipt can be printed or emailed.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/pos-receipt-engine/spec.md#REQ-PRE-003
		 */
		canIssueReceipt() {
			return ['confirmed', 'settled', 'refunded'].includes(this.status)
		},

		/**
		 * Whether any header action applies to this transaction.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
		 */
		hasActions() {
			return (
				Boolean(this.object)
				&& (this.canEdit
					|| this.canConfirm
					|| this.canPark
					|| this.canResume
					|| this.canSettle
					|| this.canRegisterReturn
					|| this.canRefund
					|| this.canIssueReceipt)
			)
		},
	},

	watch: {
		transactionId: {
			immediate: true,
			/**
			 * Re-count the lines when the transaction changes.
			 *
			 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
			 */
			handler() {
				this.loadLineCount()
			},
		},
	},

	methods: {
		/**
		 * Count the transaction's lines, so Confirm is off for an empty cart.
		 * Stays null (Confirm enabled) when the count cannot be read; the
		 * server refuses an empty cart either way.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
		 */
		async loadLineCount() {
			const id = this.transactionId
			if (!id) {
				return
			}
			try {
				const store = useObjectStore()
				await store.fetchCollection('posTransactionLine', {
					transaction: id,
					_limit: 500,
				})
				const rows = store.getCollection('posTransactionLine')?.results || []
				this.lineCount = rows.filter(
					(line) => line.transaction === id,
				).length
			} catch {
				this.lineCount = null
			}
		},

		/**
		 * Open the transaction's edit form.
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
		 */
		edit() {
			this.$router.push({
				name: 'PosTransactionEdit',
				params: { id: this.transactionId },
			})
		},

		/**
		 * Open a new refund for this transaction.
		 *
		 * @spec openspec/specs/pos-refund-return/spec.md#REQ-REF-002
		 */
		registerReturn() {
			this.$router.push({
				name: 'PosRefundNewFromTransaction',
				params: { transactionId: this.transactionId },
			})
		},

		/**
		 * Open the print or email receipt modal, loading the active receipt
		 * templates for its picker first.
		 *
		 * @param {string} mode Either 'print' or 'email'.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/pos-receipt-engine/spec.md#REQ-PRE-003
		 */
		async openReceipt(mode) {
			this.busy = true
			try {
				const store = useObjectStore()
				await store.fetchCollection('receiptTemplate', {
					status: 'active',
					_limit: 100,
				})
				const rows = store.getCollection('receiptTemplate')?.results || []
				this.receiptTemplates = rows.filter(
					(tpl) => (tpl.status || 'active') === 'active',
				)
			} catch {
				this.receiptTemplates = []
			} finally {
				this.busy = false
			}
			this.receiptMode = mode
		},

		/**
		 * Close the receipt modal and refresh the page after a receipt went out.
		 *
		 * @spec openspec/specs/pos-receipt-engine/spec.md#REQ-PRE-003
		 */
		onReceiptIssued() {
			this.receiptMode = ''
			emit('cn:page:refresh', {})
		},

		/**
		 * POST a transaction action, then refresh the page.
		 *
		 * @param {string} action The path segment (e.g. 'confirm').
		 * @param {object} body Optional JSON body.
		 * @param {string} okMsg Success toast message.
		 * @return {Promise<boolean>} Whether the action succeeded.
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-002
		 */
		async run(action, body, okMsg) {
			if (!this.transactionId) {
				return false
			}
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(
						`/apps/pipelinq/api/pos-transactions/${this.transactionId}/${action}`,
					),
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
				showSuccess(okMsg)
				emit('cn:page:refresh', {})
				return true
			} catch {
				showError(t('pipelinq', 'Action failed.'))
				return false
			} finally {
				this.busy = false
			}
		},

		/**
		 * Reverse the transaction with a reason.
		 *
		 * @param {string} reason The refund reason.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-004
		 */
		async refund(reason) {
			const ok = await this.run(
				'refund',
				{ reason },
				t('pipelinq', 'Transaction refunded.'),
			)
			if (ok) {
				this.showRefund = false
			}
		},
	},
}
</script>

<style scoped>
.pos-tx-header-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}
</style>
