<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<div class="pos-form">
		<div class="pos-form__header">
			<NcButton @click="goBack">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2>{{ isNew ? t('pipelinq', 'New transaction') : (transaction.reference || t('pipelinq', 'Transaction')) }}</h2>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<div class="pos-form__fields">
				<NcTextField
					v-model="transaction.terminalId"
					:label="t('pipelinq', 'Terminal')" />
				<NcSelect
					:model-value="selectedClient"
					:options="clientOptions"
					:input-label="t('pipelinq', 'Client (optional)')"
					label="label"
					:clearable="true"
					@update:model-value="onClientSelect" />
				<NcSelect
					:model-value="selectedPriceMode"
					:options="priceModeOptions"
					:input-label="t('pipelinq', 'Price mode')"
					label="label"
					:clearable="false"
					@update:model-value="onPriceModeSelect" />
				<NcSelect
					:model-value="selectedTender"
					:options="tenderOptions"
					:input-label="t('pipelinq', 'Tender type')"
					label="label"
					:clearable="false"
					data-testid="tender-type"
					@update:model-value="onTenderSelect" />
				<NcTextField
					v-model="transaction.notes"
					:label="t('pipelinq', 'Notes')" />
			</div>

			<div class="pos-form__customer">
				<div v-if="!selectedCustomer" class="pos-form__customer-empty">
					<NcButton
						variant="secondary"
						data-testid="add-customer"
						:disabled="saving"
						@click="openCustomerLookup">
						{{ t('pipelinq', 'Add customer') }}
					</NcButton>
					<span v-if="onAccountError" class="pos-form__customer-error" role="alert">
						{{ onAccountError }}
					</span>
				</div>
				<div v-else class="pos-form__customer-selected" data-testid="selected-customer">
					<span class="pos-form__customer-label">
						{{ t('pipelinq', 'Customer:') }}
						<strong>{{ selectedCustomer.name }}</strong>
						<span
							v-if="selectedCustomer.doNotContact"
							class="pos-form__customer-flag"
							:title="t('pipelinq', 'This customer does not wish to be contacted.')">
							🔒
						</span>
					</span>
					<NcButton
						variant="tertiary"
						:aria-label="t('pipelinq', 'Remove customer')"
						data-testid="clear-customer"
						:disabled="saving"
						@click="clearCustomer">
						✕
					</NcButton>
				</div>
				<PurchaseHistory v-if="selectedCustomer" :rows="history" />
				<label
					class="pos-form__consent"
					:title="!selectedCustomer ? t('pipelinq', 'Select a customer first.') : ''">
					<input
						type="checkbox"
						:checked="transaction.marketingConsent"
						:disabled="!selectedCustomer || (selectedCustomer && selectedCustomer.doNotContact)"
						data-testid="marketing-consent"
						@change="onConsentChange">
					{{ t('pipelinq', 'I want to receive offers and updates.') }}
				</label>
			</div>

			<table class="pos-form__lines">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Product') }}</th>
						<th>{{ t('pipelinq', 'Description') }}</th>
						<th>{{ t('pipelinq', 'Qty') }}</th>
						<th>{{ t('pipelinq', 'Unit price') }}</th>
						<th>{{ t('pipelinq', 'Discount %') }}</th>
						<th>{{ t('pipelinq', 'VAT') }}</th>
						<th>{{ t('pipelinq', 'Line total') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<PosLineItemRow
						v-for="(line, index) in lines"
						:key="line._key"
						:line="line"
						:products="products"
						:price-mode="priceMode"
						@update:line="updateLine(index, $event)"
						@remove="removeLine(index)" />
				</tbody>
			</table>

			<NcButton class="pos-form__add" @click="addLine">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('pipelinq', 'Add line') }}
			</NcButton>

			<PosTotalsPanel :lines="lines" :price-mode="priceMode" />

			<PaymentMethodSelector
				v-model="paymentSelection"
				:client-selected="!!transaction.client"
				@change="onPaymentSelectionChange" />

			<div class="pos-form__actions">
				<NcButton
					variant="primary"
					:disabled="saving || !checkoutAllowed"
					data-testid="checkout"
					@click="save">
					{{ t('pipelinq', 'Checkout') }}
				</NcButton>
			</div>
		</template>

		<CustomerLookupModal
			v-if="showCustomerModal"
			@select="onCustomerSelected"
			@cancel="closeCustomerLookup" />
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcLoadingIcon } from '@nextcloud/vue'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import Plus from 'vue-material-design-icons/Plus.vue'
import PosLineItemRow from '../../components/pos/PosLineItemRow.vue'
import PosTotalsPanel from '../../components/pos/PosTotalsPanel.vue'
import PurchaseHistory from '../../components/pos/PurchaseHistory.vue'
import CustomerLookupModal from '../../modals/CustomerLookupModal.vue'
import PaymentMethodSelector from '../../components/pos/PaymentMethodSelector.vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useObjectStore } from '../../store/modules/object.js'
import { recalculateLine } from '../../services/posTotals.js'
import {
	attachCustomer as apiAttachCustomer,
	detachCustomer as apiDetachCustomer,
	getCustomerHistory,
} from '../../services/posCustomerApi.js'

/**
 * Normalise an object-store collection into a plain rows array.
 *
 * useObjectStore().getCollection(type) returns the results ARRAY directly, but
 * older shapes wrapped them in a { results } envelope. Accept either so a lib
 * bump cannot silently empty the POS product / client / line lists again.
 *
 * @param {Array|object|null|undefined} collection The getCollection() value.
 * @return {Array} The rows array (never null/undefined).
 */
function collectionRows(collection) {
	if (Array.isArray(collection)) {
		return collection
	}
	return (collection && collection.results) || []
}

export default {
	name: 'PosTransactionForm',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
		Plus,
		PosLineItemRow,
		PosTotalsPanel,
		PurchaseHistory,
		CustomerLookupModal,
		PaymentMethodSelector,
	},
	props: {
		posTransactionId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			transaction: {
				status: 'draft',
				terminalId: '',
				notes: '',
				client: null,
				priceMode: 'excl',
				customer: null,
				marketingConsent: false,
				// Schema-typed string (default ''); send an explicit empty string
				// so the value is never persisted as null — a null trips the
				// posTransaction schema's string-type validation when the confirm
				// transition re-saves the header, blocking checkout completion.
				consentSyncStatus: '',
				tenderType: 'cash',
			},
			lines: [],
			products: [],
			clients: [],
			deletedLineIds: [],
			loading: false,
			saving: false,
			keyCounter: 0,
			selectedCustomer: null,
			history: [],
			showCustomerModal: false,
			paymentSelection: 'cash',
			paymentProvider: 'cash',
			paymentMethod: 'cash',
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
		 * Whether this is a new (unsaved) transaction.
		 *
		 * @return {boolean} True when creating.
		 */
		isNew() {
			return !this.transactionId || this.transactionId === 'new'
		},
		/**
		 * Client picker options.
		 *
		 * @return {Array<object>} The options.
		 */
		clientOptions() {
			return this.clients.map(c => ({ id: c.id, label: c.name }))
		},
		/**
		 * The selected client option.
		 *
		 * @return {object|null} The option.
		 */
		selectedClient() {
			return this.clientOptions.find(o => o.id === this.transaction.client) || null
		},
		/**
		 * The current price mode ('excl' default).
		 *
		 * @return {string} The price mode.
		 */
		priceMode() {
			return this.transaction.priceMode === 'incl' ? 'incl' : 'excl'
		},
		/**
		 * Available price mode options.
		 *
		 * @return {Array<object>} The options.
		 */
		priceModeOptions() {
			return [
				{ id: 'excl', label: t('pipelinq', 'Excl. VAT') },
				{ id: 'incl', label: t('pipelinq', 'Incl. VAT') },
			]
		},
		/**
		 * The selected price mode option.
		 *
		 * @return {object} The option.
		 */
		selectedPriceMode() {
			return this.priceModeOptions.find(o => o.id === this.priceMode) || this.priceModeOptions[0]
		},
		/**
		 * Tender type picker options.
		 *
		 * @return {Array<object>} The options.
		 */
		tenderOptions() {
			return [
				{ id: 'cash', label: t('pipelinq', 'Cash') },
				{ id: 'card', label: t('pipelinq', 'Card') },
				{ id: 'onAccount', label: t('pipelinq', 'On account') },
			]
		},
		/**
		 * The selected tender option.
		 *
		 * @return {object} The option.
		 */
		selectedTender() {
			const id = this.transaction.tenderType || 'cash'
			return this.tenderOptions.find(o => o.id === id) || this.tenderOptions[0]
		},
		/**
		 * Whether on-account + customer invariant holds (REQ-PCL-005).
		 *
		 * @return {boolean} True when the checkout button is allowed.
		 */
		isOnAccountValid() {
			if (this.transaction.tenderType !== 'onAccount') {
				return true
			}
			return !!this.selectedCustomer
		},
		/**
		 * Aggregate gate for the Checkout button.
		 *
		 * @return {boolean} True when checkout is permitted.
		 */
		checkoutAllowed() {
			return this.isOnAccountValid
		},
		/**
		 * Error message surfaced near the customer picker for on-account misuse.
		 *
		 * @return {string} The error or empty string.
		 */
		onAccountError() {
			if (this.transaction.tenderType === 'onAccount' && !this.selectedCustomer) {
				return t('pipelinq', 'Customer is required for on-account transactions.')
			}
			return ''
		},
	},
	async mounted() {
		this.loading = true
		try {
			await this.loadProducts()
			await this.loadClients()
			if (!this.isNew) {
				await this.loadTransaction()
			}
		} finally {
			this.loading = false
		}
	},
	methods: {
		/**
		 * Load the product catalog for the picker.
		 */
		async loadProducts() {
			try {
				await this.objectStore.fetchCollection('product', { _limit: 500 })
				// getCollection() returns the results ARRAY directly (not a
				// { results } envelope); reading `.results` off it yielded
				// undefined → [] and left the product picker permanently empty.
				this.products = collectionRows(this.objectStore.getCollection('product'))
			} catch {
				this.products = []
			}
		},
		/**
		 * Load clients for the optional account-sale picker.
		 */
		async loadClients() {
			try {
				await this.objectStore.fetchCollection('client', { _limit: 500 })
				this.clients = collectionRows(this.objectStore.getCollection('client'))
			} catch {
				this.clients = []
			}
		},
		/**
		 * Load an existing transaction and its lines.
		 */
		async loadTransaction() {
			const tx = await this.objectStore.fetchObject('posTransaction', this.transactionId)
			this.transaction = {
				tenderType: 'cash',
				marketingConsent: false,
				customer: null,
				...tx,
			}
			await this.objectStore.fetchCollection('posTransactionLine', { transaction: this.transactionId, _limit: 500 })
			const rows = collectionRows(this.objectStore.getCollection('posTransactionLine'))
			this.lines = rows
				.filter(l => l.transaction === this.transactionId)
				.sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
				.map(l => ({ ...l, _key: this.nextKey() }))
			if (this.transaction.customer) {
				try {
					this.history = await getCustomerHistory(this.transaction.customer, 0)
					// Surface the linked customer in the picker — we do not call
					// getCustomer (no need for the full row), just enough to
					// reflect the selection so detach works.
					this.selectedCustomer = {
						id: this.transaction.customer,
						name: tx.customerName || this.transaction.customer,
						doNotContact: false,
					}
				} catch {
					this.history = []
				}
			}
			// Restore the payment-method selector from the persisted transaction.
			const provider = (tx && tx.paymentProvider) || 'cash'
			const method = (tx && tx.paymentMethod) || 'cash'
			this.paymentProvider = provider
			this.paymentMethod = method
			this.paymentSelection = (provider === 'cash' || provider === 'voucher' || provider === 'account')
				? provider
				: provider + ':' + method
		},
		/**
		 * Next stable v-for key for a line row.
		 *
		 * @return {number} The key.
		 */
		nextKey() {
			this.keyCounter += 1
			return this.keyCounter
		},
		/**
		 * Append a blank line.
		 */
		addLine() {
			this.lines.push(recalculateLine({
				_key: this.nextKey(),
				description: '',
				quantity: 1,
				unitPrice: 0,
				discount: 0,
				taxRate: 21,
				product: null,
			}))
		},
		/**
		 * Replace a line after an edit.
		 *
		 * @param {number} index The line index.
		 * @param {object} line The updated line.
		 */
		updateLine(index, line) {
			this.lines[index] = { ...line, _key: this.lines[index]._key }
		},
		/**
		 * Remove a line, queueing a delete if it was persisted.
		 *
		 * @param {number} index The line index.
		 */
		removeLine(index) {
			const line = this.lines[index]
			if (line.id) {
				this.deletedLineIds.push(line.id)
			}
			this.lines.splice(index, 1)
		},
		/**
		 * Update local payment provider/method state when the selector changes.
		 *
		 * @param {object} payload The selector payload.
		 *
		 * @param payload.providerName
		 * @param payload.paymentMethod
		 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-008
		 */
		onPaymentSelectionChange({ providerName, paymentMethod }) {
			this.paymentProvider = providerName || 'cash'
			this.paymentMethod = paymentMethod || providerName || 'cash'
			// Cash / voucher / account settle immediately on confirm — mirror
			// the selection onto the draft transaction so saveObject persists it.
			if (this.paymentProvider === 'cash' || this.paymentProvider === 'voucher' || this.paymentProvider === 'account') {
				this.transaction.paymentProvider = this.paymentProvider
				this.transaction.paymentMethod = this.paymentMethod
				this.transaction.paymentStatus = (this.paymentProvider === 'cash' ? 'settled' : 'pending')
			} else {
				// Card / online providers — actual session is initiated server-side
				// by PosPaymentService.initiatePayment AFTER the transaction is
				// confirmed; clear the stale draft fields so saveObject does not
				// persist a stale value.
				this.transaction.paymentProvider = this.paymentProvider
				this.transaction.paymentMethod = this.paymentMethod
				this.transaction.paymentStatus = 'pending'
			}
		},
		/**
		 * Persist the transaction header and its lines.
		 *
		 * Totals are intentionally NOT sent: the backend recomputes them
		 * server-side on confirm. The client only persists the editable header
		 * + line inputs.
		 */
		async save() {
			if (!this.checkoutAllowed) {
				showError(t('pipelinq', 'Customer is required for on-account transactions.'))
				return
			}
			this.saving = true
			try {
				const header = {
					...this.transaction,
					status: this.transaction.status || 'draft',
					// `cashier` is a schema-required field on posTransaction; default
					// it to the logged-in user (same pattern as PosRefundForm) so the
					// OpenRegister POST validates and the sale persists. Preserve any
					// value already on the (edited) transaction.
					cashier: this.transaction.cashier || (window.OC?.getCurrentUser?.()?.uid ?? ''),
				}
				const savedTx = await this.objectStore.saveObject('posTransaction', header)
				if (!savedTx) {
					showError(t('pipelinq', 'Failed to save transaction.'))
					return
				}
				const txId = savedTx.id || this.transactionId

				// Attach the customer + consent server-authoritatively (also
				// triggers the marketingConsent sync to the contact).
				await this.syncCustomerAttachment(txId)

				for (const id of this.deletedLineIds) {
					await this.objectStore.deleteObject('posTransactionLine', id)
				}
				this.deletedLineIds = []

				let sortOrder = 1
				for (const line of this.lines) {
					const computed = recalculateLine(line, this.priceMode)
					const payload = {
						transaction: txId,
						product: line.product || null,
						description: line.description,
						quantity: computed.quantity,
						unitPrice: computed.unitPrice,
						discount: computed.discount,
						taxRate: computed.taxRate,
						taxAmount: computed.taxAmount,
						lineTotal: computed.lineTotal,
						sortOrder: sortOrder++,
						notes: line.notes || '',
					}
					if (line.id) {
						payload.id = line.id
					}
					await this.objectStore.saveObject('posTransactionLine', payload)
				}

				// Complete the sale: confirm the transaction so the backend
				// recomputes the server-authoritative totals from the persisted
				// lines and moves it out of `draft` (the draft header is stored
				// with total=0 — totals are only computed on the confirm
				// transition). Without this a "Checkout" only ever left an
				// unpriced draft. The endpoint is idempotent-safe for our flow:
				// it is only called once per checkout, immediately after the
				// lines persist. On failure we still navigate to the detail view
				// (the draft + lines are saved) but surface the error.
				try {
					await axios.post(
						generateUrl('/apps/pipelinq/api/pos-transactions/' + encodeURIComponent(txId) + '/confirm'),
					)
				} catch (confirmError) {
					showError(t('pipelinq', 'Transaction saved but could not be completed.'))
					this.$router.push({ name: 'PosTransactionDetail', params: { id: txId } })
					return
				}

				showSuccess(t('pipelinq', 'Transaction saved.'))
				this.$router.push({ name: 'PosTransactionDetail', params: { id: txId } })
			} catch (e) {
				showError(t('pipelinq', 'Failed to save transaction.'))
			} finally {
				this.saving = false
			}
		},
		/**
		 * Apply a client selection.
		 *
		 * @param {object|null} option The chosen client.
		 */
		onClientSelect(option) {
			this.transaction.client = option ? option.id : null
		},
		/**
		 * Apply a price mode selection.
		 *
		 * @param {object|null} option The chosen price mode.
		 */
		onPriceModeSelect(option) {
			this.transaction.priceMode = option ? option.id : 'excl'
		},
		/**
		 * Apply a tender type selection.
		 *
		 * @param {object|null} option The chosen tender.
		 */
		onTenderSelect(option) {
			this.transaction.tenderType = option ? option.id : 'cash'
		},
		/**
		 * Apply a marketing-consent toggle.
		 *
		 * @param {Event} event The change event.
		 */
		onConsentChange(event) {
			this.transaction.marketingConsent = !!event.target.checked
		},
		/**
		 * Open the customer lookup modal.
		 */
		openCustomerLookup() {
			this.showCustomerModal = true
		},
		/**
		 * Close the customer lookup modal.
		 */
		closeCustomerLookup() {
			this.showCustomerModal = false
		},
		/**
		 * Apply a customer chosen from the lookup modal.
		 *
		 * @param {object} row The selected contact.
		 */
		async onCustomerSelected(row) {
			this.selectedCustomer = row
			this.transaction.customer = row.id
			if (row.doNotContact) {
				this.transaction.marketingConsent = false
				showWarning(t('pipelinq', 'This customer does not wish to be contacted.'))
			}
			this.showCustomerModal = false
			await this.loadHistory(row.id)
		},
		/**
		 * Clear the selected customer (REQ-PCL-002 Scenario 3).
		 */
		async clearCustomer() {
			this.selectedCustomer = null
			this.transaction.customer = null
			this.transaction.marketingConsent = false
			this.history = []
			if (!this.isNew) {
				try {
					await apiDetachCustomer(this.transactionId)
				} catch {
					// Detach is best-effort on the persistent record; UI state
					// already reflects the cleared selection.
				}
			}
		},
		/**
		 * Load purchase history for the selected customer.
		 *
		 * @param {string} customerId The contact UUID.
		 */
		async loadHistory(customerId) {
			try {
				this.history = await getCustomerHistory(customerId, 0)
			} catch {
				this.history = []
			}
		},
		/**
		 * Attach the customer + consent on the server (called from save).
		 *
		 * @param {string} txId The transaction UUID.
		 */
		async syncCustomerAttachment(txId) {
			if (!txId || !this.selectedCustomer) {
				return
			}
			try {
				await apiAttachCustomer(
					txId,
					this.selectedCustomer.id,
					!!this.transaction.marketingConsent,
				)
			} catch {
				showError(t('pipelinq', 'Could not link customer to the transaction.'))
			}
		},
		/**
		 * Return to the transaction list.
		 */
		goBack() {
			this.$router.push({ name: 'PosTransactions' })
		},
	},
}
</script>

<style scoped>
.pos-form {
	padding: 20px;
	max-width: 1000px;
}

.pos-form__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
}

.pos-form__fields {
	display: grid;
	grid-template-columns: repeat(3, 1fr);
	gap: 16px;
	margin-bottom: 24px;
}

.pos-form__lines {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.pos-form__lines th {
	text-align: left;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}

.pos-form__add {
	margin-bottom: 24px;
}

.pos-form__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: 24px;
}

.pos-form__customer {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin: 16px 0;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.pos-form__customer-empty {
	display: flex;
	align-items: center;
	gap: 12px;
}

.pos-form__customer-error {
	color: var(--color-error);
	font-size: 12px;
}

.pos-form__customer-selected {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.pos-form__customer-label {
	display: flex;
	align-items: center;
	gap: 6px;
}

.pos-form__customer-flag {
	font-size: 13px;
}

.pos-form__consent {
	display: flex;
	align-items: center;
	gap: 8px;
	font-size: 13px;
}
</style>
