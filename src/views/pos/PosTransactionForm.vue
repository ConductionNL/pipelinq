<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -
  - A sale at the cash register (PqKassa): the lines, scanned or typed, the
  - register, price display and payment method, the customer's purchase
  - history, and beside them the totals with how the payment splits, the
  - customer with the marketing consent and a preview of the receipt.
  - Checking out opens PosPaymentDialog (PqKassaAfrekenen), which splits
  - the payment over loyalty points, gift cards and the rest.
  -->
<template>
	<CnDetailPage
		:title="transaction.reference || (isOpenSale ? t('pipelinq', 'New transaction') : t('pipelinq', 'Transaction'))"
		:object="headerRecord"
		:typePill="statusPill"
		:breadcrumb="breadcrumb"
		:headerMeta="headerMeta"
		:loading="loading"
		:actionsMenu="headerMenu"
		class="pos-form">
		<template #actions>
			<NcButton
				variant="primary"
				class="pos-form__header-button"
				data-testid="checkout"
				:disabled="saving || !checkoutAllowed"
				@click="openPayment">
				<template #icon>
					<CashRegister :size="16" />
				</template>
				{{ t('pipelinq', 'Check out') }}
			</NcButton>
		</template>

		<div class="pos-form__layout">
			<div class="pos-form__main">
				<CnDetailCard :title="t('pipelinq', 'Lines')">
					<label for="pos-form-barcode" class="pos-form__label">{{ t('pipelinq', 'Scan or type a barcode') }}</label>
					<form class="pos-form__scan" @submit.prevent="addFromBarcode">
						<NcTextField
							id="pos-form-barcode"
							ref="barcode"
							v-model="barcode"
							class="pos-form__barcode"
							:label="t('pipelinq', 'Scan or type a barcode')"
							labelOutside
							data-testid="pos-barcode">
							<template #icon>
								<Barcode :size="18" />
							</template>
						</NcTextField>
						<NcButton type="submit" class="pos-form__add">
							<template #icon>
								<Plus :size="16" />
							</template>
							{{ t('pipelinq', 'Add line') }}
						</NcButton>
					</form>
					<div v-if="lines.length" class="pos-form__lines-wrap">
						<table class="pos-form__lines" data-testid="pos-lines">
							<thead>
								<tr>
									<th scope="col">{{ t('pipelinq', 'Product') }}</th>
									<th scope="col" class="num">{{ t('pipelinq', 'Qty') }}</th>
									<th scope="col" class="num">{{ t('pipelinq', 'Unit price') }}</th>
									<th scope="col" class="num">{{ t('pipelinq', 'Discount %') }}</th>
									<th scope="col" class="num">{{ t('pipelinq', 'VAT') }}</th>
									<th scope="col" class="num">{{ t('pipelinq', 'Line total') }}</th>
									<th scope="col">
										<span class="hidden-visually">{{ t('pipelinq', 'Actions') }}</span>
									</th>
								</tr>
							</thead>
							<tbody>
								<PosLineItemRow
									v-for="(line, index) in lines"
									:key="line._key"
									:line="line"
									:products="products"
									:priceMode="priceMode"
									:editing="editingKey === line._key"
									@edit="editingKey = line._key"
									@done="editingKey = null"
									@update:line="updateLine(index, $event)"
									@remove="removeLine(index)" />
							</tbody>
						</table>
					</div>
					<p class="pos-form__hint">
						{{ linesHint }}
					</p>
				</CnDetailCard>

				<CnDetailCard :title="t('pipelinq', 'Transaction')">
					<div class="pos-form__fields">
						<div class="pos-form__field">
							<label for="pos-form-register" class="pos-form__label">{{ t('pipelinq', 'Register') }}</label>
							<NcTextField
								id="pos-form-register"
								v-model="transaction.terminalId"
								:label="t('pipelinq', 'Register')"
								labelOutside
								data-testid="pos-register" />
						</div>
						<NcSelect
							:modelValue="selectedPriceMode"
							:options="priceModeOptions"
							:inputLabel="t('pipelinq', 'Price display')"
							label="label"
							:clearable="false"
							@update:modelValue="onPriceModeSelect" />
						<NcSelect
							:modelValue="selectedTender"
							:options="tenderOptions"
							:inputLabel="t('pipelinq', 'Tender')"
							label="label"
							:clearable="false"
							data-testid="tender-type"
							@update:modelValue="onTenderSelect" />
					</div>
					<CnResourceSelect
						v-if="transaction.tenderType === 'onAccount'"
						register="pipelinq"
						schema="client"
						labelField="name"
						:modelValue="transaction.client || ''"
						:inputLabel="t('pipelinq', 'Client (optional)')"
						:placeholder="t('pipelinq', 'Search clients')"
						:preload="true"
						data-testid="pos-form-client"
						@update:modelValue="onClientSelect" />
					<label for="pos-form-notes" class="pos-form__label pos-form__notes">{{ t('pipelinq', 'Notes') }}</label>
					<NcTextField
						id="pos-form-notes"
						v-model="transaction.notes"
						:label="t('pipelinq', 'Receipt notes')"
						:placeholder="t('pipelinq', 'Optional note on the receipt')"
						labelOutside />
					<PaymentMethodSelector
						v-if="transaction.tenderType === 'card'"
						v-model="paymentSelection"
						:clientSelected="!!transaction.client"
						@change="onPaymentSelectionChange" />
				</CnDetailCard>

				<CnDetailCard
					v-if="selectedCustomer"
					:title="t('pipelinq', 'Purchase history')"
					:subtitle="t('pipelinq', '{name}, latest {count} transactions', { name: selectedCustomer.name, count: recentHistory.length })">
					<CnDataTable
						v-if="recentHistory.length"
						framed
						:columns="historyColumns"
						:rows="recentHistory"
						rowKey="id">
						<template #column-createdAt="{ row }">
							{{ formatDay(row.createdAt) }}
						</template>
						<template #column-total="{ row }">
							{{ formatEur(row.total) }}
						</template>
						<template #column-tenderType="{ row }">
							{{ tenderLabel(row.tenderType) }}
						</template>
					</CnDataTable>
					<p v-else class="pos-form__hint">
						{{ t('pipelinq', 'No previous purchases.') }}
					</p>
				</CnDetailCard>
			</div>

			<div class="pos-form__side">
				<CnDetailCard :title="t('pipelinq', 'Totals')">
					<dl class="pos-totals">
						<div class="pos-totals__row">
							<dt>{{ t('pipelinq', 'Excl. VAT') }}</dt>
							<dd>{{ formatEur(totals.subtotal) }}</dd>
						</div>
						<div v-if="totals.discountTotal > 0" class="pos-totals__row">
							<dt>{{ t('pipelinq', 'Discount') }}</dt>
							<dd>− {{ formatEur(totals.discountTotal) }}</dd>
						</div>
						<div v-for="rate in vatRows" :key="rate.key" class="pos-totals__row">
							<dt>{{ rate.label }}</dt>
							<dd>{{ formatEur(rate.tax) }}</dd>
						</div>
						<div class="pos-totals__row pos-totals__row--total">
							<dt>{{ t('pipelinq', 'Incl. VAT') }}</dt>
							<dd>{{ formatEur(totals.total) }}</dd>
						</div>
					</dl>
					<CnDataTable
						class="pos-form__payments"
						framed
						:columns="paymentColumns"
						:rows="paymentRows"
						rowKey="key">
						<template #column-amount="{ row }">
							{{ formatEur(row.amount) }}
						</template>
					</CnDataTable>
					<NcNoteCard
						v-if="totals.total > 0"
						type="success"
						class="pos-form__closes">
						{{ t('pipelinq', 'Payment closes, ready to check out.') }}
					</NcNoteCard>
					<NcButton
						variant="primary"
						wide
						class="pos-form__checkout"
						:disabled="saving || !checkoutAllowed || lines.length === 0"
						@click="openPayment">
						<template #icon>
							<CashRegister :size="18" />
						</template>
						{{ t('pipelinq', 'Check out {amount}', { amount: formatEur(totals.total) }) }}
					</NcButton>
					<div class="pos-form__secondary">
						<NcButton :disabled="saving || lines.length === 0" @click="park">
							<template #icon>
								<PauseCircleOutline :size="16" />
							</template>
							{{ t('pipelinq', 'Park') }}
						</NcButton>
						<NcButton
							:disabled="saving || !checkoutAllowed || lines.length === 0"
							data-testid="confirm-sale"
							@click="save">
							<template #icon>
								<Check :size="16" />
							</template>
							{{ t('pipelinq', 'Confirm') }}
						</NcButton>
					</div>
				</CnDetailCard>

				<CnDetailCard :title="t('pipelinq', 'Customer')">
					<div v-if="selectedCustomer" class="pos-form__customer" data-testid="selected-customer">
						<span class="pos-form__avatar" aria-hidden="true">{{ initials(selectedCustomer.name) }}</span>
						<div class="pos-form__customer-text">
							<strong>{{ selectedCustomer.name }}</strong>
							<span v-if="customerMeta" class="pos-form__customer-meta">{{ customerMeta }}</span>
						</div>
						<NcButton
							variant="tertiary"
							class="pos-form__remove-customer"
							:aria-label="t('pipelinq', 'Remove customer')"
							data-testid="clear-customer"
							:disabled="saving"
							@click="clearCustomer">
							<template #icon>
								<Close :size="18" />
							</template>
						</NcButton>
					</div>
					<div v-else class="pos-form__customer-empty">
						<NcButton data-testid="add-customer" :disabled="saving" @click="openCustomerLookup">
							<template #icon>
								<AccountPlus :size="16" />
							</template>
							{{ t('pipelinq', 'Add customer') }}
						</NcButton>
						<span v-if="onAccountError" class="pos-form__error" role="alert">
							{{ onAccountError }}
						</span>
					</div>
					<NcCheckboxRadioSwitch
						:modelValue="!!transaction.marketingConsent"
						:disabled="!selectedCustomer || selectedCustomer.doNotContact"
						class="pos-form__consent"
						data-testid="marketing-consent"
						@update:modelValue="transaction.marketingConsent = $event">
						{{ t('pipelinq', 'I want to receive offers and news.') }}
					</NcCheckboxRadioSwitch>
					<p class="pos-form__consent-hint">
						{{ t('pipelinq', 'Asked at the register. Ticked, it goes along to the contact.') }}
					</p>
				</CnDetailCard>

				<CnDetailCard :title="t('pipelinq', 'Preview of the receipt')">
					<pre class="pos-form__receipt" data-testid="pos-receipt-preview">{{ receiptText }}</pre>
				</CnDetailCard>
			</div>
		</div>

		<CustomerLookupModal
			v-if="showCustomerModal"
			@select="onCustomerSelected"
			@cancel="closeCustomerLookup" />
		<PosPaymentDialog
			v-if="showPayment"
			:total="totals.total"
			:eyebrow="paymentEyebrow"
			:customerName="selectedCustomer ? selectedCustomer.name : ''"
			:loyalty="loyalty"
			:restLabel="selectedTender.label"
			:restHint="restHint"
			:submitting="saving"
			@sellGiftCard="addGiftCardLine"
			@confirm="checkout"
			@close="showPayment = false" />
	</CnDetailPage>
</template>

<script>
import { CnDataTable, CnDetailCard, CnDetailPage, CnResourceSelect } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess, showWarning } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Barcode from 'vue-material-design-icons/Barcode.vue'
import CashRegister from 'vue-material-design-icons/CashRegister.vue'
import Check from 'vue-material-design-icons/Check.vue'
import Close from 'vue-material-design-icons/Close.vue'
import PauseCircleOutline from 'vue-material-design-icons/PauseCircleOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import PaymentMethodSelector from '../../components/pos/PaymentMethodSelector.vue'
import PosLineItemRow from '../../components/pos/PosLineItemRow.vue'
import PosPaymentDialog from '../../dialogs/PosPaymentDialog.vue'
import CustomerLookupModal from '../../modals/CustomerLookupModal.vue'
import { collectionRows } from '../../services/collectionRows.js'
import { formatDate, getUserLocale } from '../../services/localeUtils.js'
import {
	attachCustomer as apiAttachCustomer,
	detachCustomer as apiDetachCustomer,
	getCustomerHistory,
} from '../../services/posCustomerApi.js'
import { tenderTypeFor } from '../../services/posSplitPayment.js'
import { computeTotals, formatEur, recalculateLine } from '../../services/posTotals.js'
import { useObjectStore } from '../../store/modules/object.js'

const TENDER_LABELS = {
	cash: 'Cash',
	card: 'Card',
	onAccount: 'On account',
}

/**
 * The uuid of an OpenRegister object.
 *
 * @param {object} object The object.
 * @return {string} The uuid, or ''.
 */
function uuidOf(object) {
	return String(object?.['@self']?.id || object?.id || object?.uuid || '')
}

export default {
	name: 'PosTransactionForm',
	components: {
		AccountPlus,
		Barcode,
		CashRegister,
		Check,
		Close,
		CnDataTable,
		CnDetailCard,
		CnDetailPage,
		CnResourceSelect,
		CustomerLookupModal,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextField,
		PauseCircleOutline,
		PaymentMethodSelector,
		Plus,
		PosLineItemRow,
		PosPaymentDialog,
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
				// Schema-typed string (default ''); a null trips the posTransaction
				// schema's string validation when the confirm transition re-saves
				// the header.
				consentSyncStatus: '',
				tenderType: 'cash',
			},

			lines: [],
			editingKey: null,
			products: [],
			deletedLineIds: [],
			loading: false,
			saving: false,
			keyCounter: 0,
			selectedCustomer: null,
			history: [],
			loyalty: null,
			shiftSince: '',
			showCustomerModal: false,
			showPayment: false,
			barcode: '',
			scanMessage: '',
			paymentSelection: 'cash',
			paymentProvider: 'cash',
			paymentMethod: 'cash',
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		transactionId() {
			return this.posTransactionId || this.$route.params.id || null
		},

		isNew() {
			return !this.transactionId || this.transactionId === 'new'
		},

		priceMode() {
			return this.transaction.priceMode === 'incl' ? 'incl' : 'excl'
		},

		priceModeOptions() {
			return [
				{ id: 'excl', label: t('pipelinq', 'Excl. VAT') },
				{ id: 'incl', label: t('pipelinq', 'Incl. VAT') },
			]
		},

		selectedPriceMode() {
			return this.priceModeOptions.find((o) => o.id === this.priceMode) || this.priceModeOptions[0]
		},

		tenderOptions() {
			return Object.entries(TENDER_LABELS).map(([id, label]) => ({ id, label: t('pipelinq', label) }))
		},

		selectedTender() {
			const id = this.transaction.tenderType || 'cash'
			return this.tenderOptions.find((o) => o.id === id) || this.tenderOptions[0]
		},

		checkoutAllowed() {
			return this.transaction.tenderType !== 'onAccount' || !!this.selectedCustomer
		},

		onAccountError() {
			return this.checkoutAllowed ? '' : t('pipelinq', 'Customer is required for on-account transactions.')
		},

		cashierName() {
			const user = window.OC?.getCurrentUser?.()
			return user?.displayName || user?.uid || ''
		},

		/**
		 * The record the header reads.
		 *
		 * @return {object} The transaction with the cashier's name.
		 */
		headerRecord() {
			const user = window.OC?.getCurrentUser?.()
			const cashier = !this.transaction.cashier || this.transaction.cashier === user?.uid ? this.cashierName : this.transaction.cashier
			return { ...this.transaction, cashierName: cashier, shiftSince: this.shiftSince }
		},

		/**
		 * Whether the sale is still being rung up: new, a draft or parked.
		 *
		 * @return {boolean}
		 */
		isOpenSale() {
			return this.isNew || ['draft', 'parked'].includes(this.transaction.status)
		},

		statusPill() {
			if (this.isOpenSale) {
				return { text: t('pipelinq', 'Draft') }
			}
			return {
				field: 'status',
				labels: {
					confirmed: t('pipelinq', 'Confirmed'),
					settled: t('pipelinq', 'Settled'),
					refunded: t('pipelinq', 'Refunded'),
				},
			}
		},

		breadcrumb() {
			return { label: 'Transactions', route: 'PosTransactions', separator: '/' }
		},

		/**
		 * The till's header holds only Check out: no More menu with refresh
		 * or help links.
		 *
		 * @return {object} The CnDetailPage actions menu config.
		 * @spec openspec/specs/pos-transactions/spec.md#requirement-the-sale-header-and-fields-follow-the-board
		 */
		headerMenu() {
			return { showRefresh: false, showHelpLinks: false }
		},

		headerMeta() {
			return t('pipelinq', '{terminalId} · cashier {cashierName} · shift open since {shiftSince}')
		},

		totals() {
			return computeTotals(this.lines, this.priceMode)
		},

		/**
		 * One VAT row, or one per rate with its base when the sale has several.
		 *
		 * @return {Array<object>} The rows.
		 */
		vatRows() {
			const breakdown = this.totals.taxBreakdown
			if (breakdown.length <= 1) {
				const rate = breakdown[0]?.rate
				return [{ key: 'vat', label: rate !== undefined ? t('pipelinq', 'VAT {rate}%', { rate }) : t('pipelinq', 'VAT'), tax: this.totals.totalTax }]
			}
			return breakdown.map((entry) => ({ key: String(entry.rate), label: t('pipelinq', 'VAT {rate}% (base {base})', { rate: entry.rate, base: formatEur(entry.base) }), tax: entry.tax }))
		},

		paymentColumns() {
			return [
				{ key: 'label', label: t('pipelinq', 'Tender') },
				{ key: 'amount', label: t('pipelinq', 'Amount'), class: 'num', cellClass: 'num' },
			]
		},

		paymentRows() {
			return [{ key: 'rest', label: this.selectedTender.label, amount: this.totals.total }]
		},

		linesHint() {
			const mode = this.priceMode === 'incl'
				? t('pipelinq', 'Price display incl. VAT: unit prices include VAT.')
				: t('pipelinq', 'Price display excl. VAT: VAT is added to the unit prices.')
			return [mode, this.scanMessage].filter(Boolean).join(' ')
		},

		recentHistory() {
			return this.history.slice(0, 5)
		},

		historyColumns() {
			return [
				{ key: 'createdAt', label: t('pipelinq', 'Date') },
				{ key: 'reference', label: t('pipelinq', 'Reference number') },
				{ key: 'itemCount', label: t('pipelinq', 'Items'), class: 'num', cellClass: 'num' },
				{ key: 'total', label: t('pipelinq', 'Total'), class: 'num', cellClass: 'num' },
				{ key: 'tenderType', label: t('pipelinq', 'Tender') },
			]
		},

		customerMeta() {
			const parts = []
			if (this.selectedCustomer?.email) {
				parts.push(this.selectedCustomer.email)
			}
			if (this.loyalty) {
				parts.push(t('pipelinq', 'loyalty {points} points', { points: this.loyalty.balance }))
			}
			return parts.join(' · ')
		},

		paymentEyebrow() {
			return [t('pipelinq', 'Check out'), this.transaction.terminalId, this.selectedCustomer?.name].filter(Boolean).join(' · ')
		},

		restHint() {
			return this.transaction.terminalId
				? t('pipelinq', 'The rest, at {register}', { register: this.transaction.terminalId })
				: t('pipelinq', 'The rest')
		},

		/**
		 * The receipt as it will print: the lines, the total, how it is paid and
		 * who served.
		 *
		 * @return {string} The receipt text.
		 */
		receiptText() {
			const rule = '-'.repeat(32)
			const lines = this.lines.map((raw) => {
				const line = recalculateLine(raw, this.priceMode)
				const qty = line.quantity !== 1 ? `${line.quantity} x ` : ''
				return `${qty}${line.description || t('pipelinq', 'Item')} ${formatEur(line.lineTotal)}`
			})
			return [
				...lines,
				rule,
				`${t('pipelinq', 'Total incl. VAT')} ${formatEur(this.totals.total)}`,
				`${this.selectedTender.label} ${formatEur(this.totals.total)}`,
				[this.transaction.terminalId, this.cashierName].filter(Boolean).join(' · '),
			].join('\n')
		},
	},

	/**
	 * Loads the products and, for an existing sale, the sale itself.
	 *
	 * @spec openspec/specs/pos-transactions/spec.md#requirement-the-sale-lines-read-as-a-table-and-stay-editable
	 */
	async mounted() {
		this.loading = true
		try {
			await this.loadProducts()
			if (!this.isNew) {
				await this.loadTransaction()
			}
		} finally {
			this.loading = false
		}
		this.$nextTick(() => this.$refs.barcode?.focus?.())
	},

	methods: {
		formatEur,

		formatDay(value) {
			return formatDate(value, { day: 'numeric', month: 'short', year: 'numeric' }).replace('.', '')
		},

		tenderLabel(id) {
			return TENDER_LABELS[id] ? t('pipelinq', TENDER_LABELS[id]) : (id || '—')
		},

		initials(name) {
			const words = String(name || '').trim().split(/\s+/).filter(Boolean)
			return ((words[0]?.[0] || '') + (words.length > 1 ? words[words.length - 1][0] : '')).toUpperCase()
		},

		async loadProducts() {
			try {
				await this.objectStore.fetchCollection('product', { _limit: 500 })
				this.products = collectionRows(this.objectStore.getCollection('product'))
			} catch {
				this.products = []
			}
		},

		/**
		 * Loads the sale being edited with its lines and customer.
		 *
		 * @spec openspec/specs/pos-transactions/spec.md#requirement-the-sale-lines-read-as-a-table-and-stay-editable
		 */
		async loadTransaction() {
			const tx = await this.objectStore.fetchObject('posTransaction', this.transactionId)
			this.transaction = { tenderType: 'cash', marketingConsent: false, customer: null, ...tx }
			await this.objectStore.fetchCollection('posTransactionLine', { transaction: this.transactionId, _limit: 500 })
			this.lines = collectionRows(this.objectStore.getCollection('posTransactionLine'))
				.filter((l) => l.transaction === this.transactionId)
				.sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
				.map((l) => ({ ...l, _key: this.nextKey() }))
			if (this.transaction.customer) {
				const name = tx.customerName || await this.contactName(this.transaction.customer)
				this.selectedCustomer = { id: this.transaction.customer, name: name || this.transaction.customer, doNotContact: false }
				await this.loadCustomerFacts(this.transaction.customer)
			} else if (this.transaction.client) {
				await this.loadClientAsCustomer(this.transaction.client)
			}
			await this.loadShift()
			const provider = tx?.paymentProvider || 'cash'
			const method = tx?.paymentMethod || 'cash'
			this.paymentProvider = provider
			this.paymentMethod = method
			this.paymentSelection = ['cash', 'voucher', 'account'].includes(provider) ? provider : provider + ':' + method
		},

		nextKey() {
			this.keyCounter += 1
			return this.keyCounter
		},

		/**
		 * Add a line: from the scanned or typed barcode when there is one,
		 * else an empty line to fill in.
		 *
		 * @return {Promise<void>}
		 */
		async addFromBarcode() {
			const code = this.barcode.trim()
			if (code === '') {
				this.addLine()
				return
			}
			try {
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/products/barcode-lookup'), { barcode: code })
				this.addProductLine(data?.product || {})
				this.scanMessage = t('pipelinq', 'The barcode found {name} in Products.', { name: data?.product?.name || code })
				this.barcode = ''
			} catch {
				this.scanMessage = ''
				showError(t('pipelinq', 'No product found for barcode {barcode}', { barcode: code }))
			}
		},

		/**
		 * Put a product on the sale, or one more of it when it is already there.
		 *
		 * @param {object} product The product.
		 */
		addProductLine(product) {
			const id = uuidOf(product)
			const index = this.lines.findIndex((line) => id !== '' && line.product === id)
			if (index !== -1) {
				const line = this.lines[index]
				this.updateLine(index, recalculateLine({ ...line, quantity: (Number(line.quantity) || 0) + 1 }, this.priceMode))
				return
			}
			this.lines.push(recalculateLine({
				_key: this.nextKey(),
				product: id || null,
				description: product.name || '',
				quantity: 1,
				unitPrice: Number(product.unitPrice ?? product.price) || 0,
				discount: 0,
				taxRate: product.taxRate ?? 21,
			}, this.priceMode))
		},

		/**
		 * Add an empty line, open for editing.
		 *
		 * @spec openspec/specs/pos-transactions/spec.md#requirement-the-sale-lines-read-as-a-table-and-stay-editable
		 */
		addLine() {
			const key = this.nextKey()
			this.lines.push(recalculateLine({ _key: key, description: '', quantity: 1, unitPrice: 0, discount: 0, taxRate: 21, product: null }))
			this.editingKey = key
		},

		/**
		 * Put a gift card sold in the payment dialog on the sale; it is issued
		 * once the sale is checked out.
		 *
		 * @param {{amount: number}} giftCard The amount loaded on the card.
		 */
		addGiftCardLine({ amount }) {
			this.lines.push(recalculateLine({ _key: this.nextKey(), _giftCard: true, description: t('pipelinq', 'Gift card'), quantity: 1, unitPrice: Number(amount) || 0, discount: 0, taxRate: 0, product: null }, this.priceMode))
		},

		updateLine(index, line) {
			this.lines.splice(index, 1, { ...line, _key: this.lines[index]._key, _giftCard: this.lines[index]._giftCard })
		},

		/**
		 * Removes a sale line, closing its editor when it is open.
		 *
		 * @param {number} index The line's position.
		 *
		 * @spec openspec/specs/pos-transactions/spec.md#requirement-the-sale-lines-read-as-a-table-and-stay-editable
		 */
		removeLine(index) {
			const line = this.lines[index]
			if (this.editingKey === line._key) {
				this.editingKey = null
			}
			if (line.id) {
				this.deletedLineIds.push(line.id)
			}
			this.lines.splice(index, 1)
		},

		onPaymentSelectionChange({ providerName, paymentMethod }) {
			this.paymentProvider = providerName || 'cash'
			this.paymentMethod = paymentMethod || providerName || 'cash'
			this.transaction.paymentProvider = this.paymentProvider
			this.transaction.paymentMethod = this.paymentMethod
			this.transaction.paymentStatus = this.paymentProvider === 'cash' ? 'settled' : 'pending'
		},

		openPayment() {
			if (!this.checkoutAllowed) {
				showError(this.onAccountError)
				return
			}
			this.showPayment = true
		},

		/**
		 * Store the sale: the header, the customer link and the lines, and
		 * confirm it unless it is parked.
		 *
		 * @param {boolean} confirm Whether to confirm the sale.
		 * @return {Promise<string|null>} The transaction id, or null when it was not stored.
		 */
		async persistSale(confirm) {
			const header = {
				...this.transaction,
				status: this.transaction.status || 'draft',
				// `cashier` is schema-required; default it to the signed-in user.
				cashier: this.transaction.cashier || (window.OC?.getCurrentUser?.()?.uid ?? ''),
			}
			const savedTx = await this.objectStore.saveObject('posTransaction', header)
			if (!savedTx) {
				showError(t('pipelinq', 'Failed to save transaction.'))
				return null
			}
			const txId = savedTx.id || this.transactionId
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
			if (confirm) {
				// The confirm transition computes the server-authoritative totals
				// from the stored lines and moves the sale out of draft.
				try {
					await axios.post(generateUrl('/apps/pipelinq/api/pos-transactions/' + encodeURIComponent(txId) + '/confirm'))
				} catch {
					showError(t('pipelinq', 'Transaction saved but could not be completed.'))
				}
			}
			return txId
		},

		/**
		 * Confirm the sale without splitting the payment.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			if (!this.checkoutAllowed) {
				showError(this.onAccountError)
				return
			}
			this.saving = true
			try {
				const txId = await this.persistSale(true)
				if (txId) {
					await this.issueSoldGiftCards(txId)
					showSuccess(t('pipelinq', 'Transaction saved.'))
					this.$router.push({ name: 'PosTransactionDetail', params: { id: txId } })
				}
			} catch {
				showError(t('pipelinq', 'Failed to save transaction.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Check out with the split from the payment dialog: confirm the sale,
		 * spend the points, redeem the gift cards and book every part as a
		 * tender.
		 *
		 * @param {{points: number, pointsValue: number, giftCards: Array<object>, rest: number}} split The split.
		 * @return {Promise<void>}
		 */
		async checkout(split) {
			this.saving = true
			try {
				const txId = await this.persistSale(true)
				if (!txId) {
					return
				}
				await this.payParts(txId, split)
				await this.issueSoldGiftCards(txId)
				this.showPayment = false
				showSuccess(t('pipelinq', 'Transaction saved.'))
				this.$router.push({ name: 'PosTransactionDetail', params: { id: txId } })
			} catch {
				showError(t('pipelinq', 'Failed to save transaction.'))
			} finally {
				this.saving = false
			}
		},

		/**
		 * Spend the points and gift cards of a split and book each part.
		 *
		 * @param {string} txId The sale.
		 * @param {object} split The split.
		 * @return {Promise<void>}
		 */
		async payParts(txId, split) {
			const parts = []
			if (split.points > 0 && this.loyalty) {
				try {
					await axios.post(generateUrl(`/apps/pipelinq/api/loyalty/accounts/${encodeURIComponent(this.loyalty.accountId)}/pay`), { points: split.points, posTransactionId: txId })
					parts.push({ kind: 'points', amount: split.pointsValue, reference: t('pipelinq', '{points} points', { points: split.points }) })
				} catch {
					showError(t('pipelinq', 'The loyalty points could not be spent.'))
				}
			}
			for (const card of split.giftCards) {
				try {
					await axios.post(generateUrl('/apps/pipelinq/api/loyalty/gift-card/redeem'), { giftCardId: card.giftCardId, pin: card.pin, amount: card.amount, posTransactionId: txId })
					parts.push({ kind: 'giftCard', amount: card.amount, reference: card.serial })
				} catch {
					showError(t('pipelinq', 'Gift card {serial} could not be redeemed.', { serial: card.serial }))
				}
			}
			if (split.rest > 0) {
				parts.push({ kind: this.transaction.tenderType || 'cash', amount: split.rest, reference: this.transaction.reference || txId })
			}
			await this.bookTenders(txId, parts)
		},

		/**
		 * Book the parts as tenders under the tender types set up for them.
		 * A part without a tender type is left to the single payment method.
		 *
		 * @param {string} txId The sale.
		 * @param {Array<object>} parts The parts.
		 * @return {Promise<void>}
		 */
		async bookTenders(txId, parts) {
			let types
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/pos/tender-types'), { params: { activeOnly: '1' } })
				types = data?.results || []
			} catch {
				return
			}
			for (const part of parts) {
				const type = tenderTypeFor(part.kind, types)
				if (!type) {
					continue
				}
				try {
					await axios.post(generateUrl(`/apps/pipelinq/api/pos-transactions/${encodeURIComponent(txId)}/tenders`), { tenderType: uuidOf(type), amount: part.amount, reference: part.reference })
				} catch {
					showError(t('pipelinq', 'A payment part could not be booked.'))
				}
			}
		},

		/**
		 * Issue and activate the gift cards sold on this sale.
		 *
		 * @param {string} txId The sale.
		 * @return {Promise<void>}
		 */
		async issueSoldGiftCards(txId) {
			for (const line of this.lines.filter((l) => l._giftCard)) {
				try {
					const { data } = await axios.post(generateUrl('/apps/pipelinq/api/loyalty/gift-card/issue'), { initialBalance: recalculateLine(line, this.priceMode).lineTotal, channel: 'purchased', issuedIn: txId })
					const id = uuidOf(data?.card)
					if (id) {
						await axios.post(generateUrl(`/apps/pipelinq/api/loyalty/gift-card/activate/${encodeURIComponent(id)}`), { posTransactionId: txId })
					}
					showSuccess(t('pipelinq', 'Gift card {serial} sold.', { serial: data?.card?.serial || '' }))
				} catch {
					showError(t('pipelinq', 'The gift card could not be issued.'))
				}
			}
		},

		/**
		 * Park the sale: store it unconfirmed and hand the register back.
		 *
		 * @return {Promise<void>}
		 */
		async park() {
			this.saving = true
			try {
				const txId = await this.persistSale(false)
				if (txId) {
					await axios.post(generateUrl('/apps/pipelinq/api/pos-transactions/' + encodeURIComponent(txId) + '/park'))
					showSuccess(t('pipelinq', 'Transaction parked.'))
					this.$router.push({ name: 'PosTransactions' })
				}
			} catch {
				showError(t('pipelinq', 'The transaction could not be parked.'))
			} finally {
				this.saving = false
			}
		},

		onClientSelect(value) {
			this.transaction.client = value || null
		},

		onPriceModeSelect(option) {
			this.transaction.priceMode = option ? option.id : 'excl'
		},

		onTenderSelect(option) {
			this.transaction.tenderType = option ? option.id : 'cash'
		},

		openCustomerLookup() {
			this.showCustomerModal = true
		},

		closeCustomerLookup() {
			this.showCustomerModal = false
		},

		async onCustomerSelected(row) {
			this.selectedCustomer = row
			this.transaction.customer = row.id
			if (row.doNotContact) {
				this.transaction.marketingConsent = false
				showWarning(t('pipelinq', 'This customer does not wish to be contacted.'))
			}
			this.showCustomerModal = false
			await this.loadCustomerFacts(row.id)
		},

		async clearCustomer() {
			if (this.selectedCustomer?.isClient) {
				this.transaction.client = null
			}
			this.selectedCustomer = null
			this.transaction.customer = null
			this.transaction.marketingConsent = false
			this.history = []
			this.loyalty = null
			if (!this.isNew) {
				try {
					await apiDetachCustomer(this.transactionId)
				} catch {
					// Best-effort on the stored record; the form already shows no customer.
				}
			}
		},

		/**
		 * The customer's purchase history and loyalty account.
		 *
		 * @param {string} customerId The contact.
		 * @return {Promise<void>}
		 */
		async loadCustomerFacts(customerId) {
			try {
				this.history = await getCustomerHistory(customerId, 0)
			} catch {
				this.history = []
			}
			this.loyalty = await this.loadLoyalty(customerId)
		},

		/**
		 * The name of the contact a sale's customer points at.
		 *
		 * @param {string} contactId The contact.
		 * @return {Promise<string>} The name, '' when it cannot be read.
		 *
		 * @spec openspec/specs/pos-transactions/spec.md#requirement-the-sale-page-reads-as-the-board
		 */
		async contactName(contactId) {
			try {
				const contact = await this.objectStore.fetchObject('contact', contactId)
				return contact?.name || ''
			} catch {
				return ''
			}
		},

		/**
		 * A sale for a client (an account customer) shows that client as the
		 * customer, with the client's loyalty account and earlier sales.
		 *
		 * @param {string} clientId The client.
		 * @return {Promise<void>}
		 */
		async loadClientAsCustomer(clientId) {
			let client
			try {
				client = await this.objectStore.fetchObject('client', clientId)
			} catch {
				client = null
			}
			this.selectedCustomer = { id: clientId, name: client?.name || clientId, email: client?.email || '', doNotContact: false, isClient: true }
			try {
				await this.objectStore.fetchCollection('posTransaction', { client: clientId, _limit: 50 })
				this.history = collectionRows(this.objectStore.getCollection('posTransaction'))
					.filter((tx) => tx.client === clientId && uuidOf(tx) !== this.transactionId && ['confirmed', 'settled', 'refunded'].includes(tx.status))
					.map((tx) => ({ id: uuidOf(tx), createdAt: tx.confirmedAt || tx.settledAt, reference: tx.reference, total: tx.total, tenderType: tx.tenderType }))
					.sort((a, b) => String(b.createdAt || '').localeCompare(String(a.createdAt || '')))
				await this.countHistoryLines()
			} catch {
				this.history = []
			}
			this.loyalty = await this.loadLoyalty(clientId)
		},

		/**
		 * Count the lines of the sales the history shows: their Items column.
		 *
		 * @return {Promise<void>}
		 */
		async countHistoryLines() {
			const shown = this.history.slice(0, 5)
			for (const row of shown) {
				try {
					await this.objectStore.fetchCollection('posTransactionLine', { transaction: row.id, _limit: 500 })
					row.itemCount = collectionRows(this.objectStore.getCollection('posTransactionLine')).filter((line) => line.transaction === row.id).length
				} catch {
					row.itemCount = 0
				}
			}
			this.history = [...this.history]
		},

		/**
		 * The time the open shift at this sale's register started, for the header.
		 *
		 * @return {Promise<void>}
		 */
		async loadShift() {
			if (!this.transaction.terminalId) {
				return
			}
			try {
				await this.objectStore.fetchCollection('cashShift', { status: 'open', _limit: 50 })
				const shift = collectionRows(this.objectStore.getCollection('cashShift'))
					.find((row) => row.status === 'open' && row.drawer === this.transaction.terminalId)
				this.shiftSince = shift?.floatAt
					? new Date(shift.floatAt).toLocaleTimeString(getUserLocale(), { hour: '2-digit', minute: '2-digit' })
					: ''
			} catch {
				this.shiftSince = ''
			}
		},

		/**
		 * The customer's loyalty account with what a point is worth.
		 *
		 * @param {string} customerId The contact.
		 * @return {Promise<object|null>} `{ accountId, balance, pointValue }`, or null.
		 */
		async loadLoyalty(customerId) {
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/objects/pipelinq/customerLoyaltyAccount'), { params: { customerId, _limit: 1 } })
				const account = (data?.results || [])[0]
				if (!account || account.status === 'disabled') {
					return null
				}
				const { data: programme } = await axios.get(generateUrl(`/apps/openregister/api/objects/pipelinq/loyaltyProgramme/${encodeURIComponent(account.programmeId)}`))
				const pointValue = Number(programme?.pointValue) || 0
				return pointValue > 0 ? { accountId: uuidOf(account), balance: Number(account.currentBalance) || 0, pointValue } : null
			} catch {
				return null
			}
		},

		async syncCustomerAttachment(txId) {
			if (!txId || !this.selectedCustomer || this.selectedCustomer.isClient) {
				return
			}
			try {
				await apiAttachCustomer(txId, this.selectedCustomer.id, !!this.transaction.marketingConsent)
			} catch {
				showError(t('pipelinq', 'Could not link customer to the transaction.'))
			}
		},
	},
}
</script>

<style scoped>
.pos-form__layout {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 336px;
	gap: 20px;
	align-items: start;
}

.pos-form__main,
.pos-form__side {
	display: flex;
	flex-direction: column;
	gap: 20px;
	min-width: 0;
}

/* Side cards head with a muted label, as CnDetailPage's own side column does.
   The tone carries !important against a theme that colours every heading. */
.cn-look-board .pos-form__side :deep(.cn-detail-card__title) {
	color: var(--color-text-maxcontrast) !important;
	font-size: var(--cn-board-side-heading-size, 15px);
	font-weight: 600;
}

.pos-form__scan {
	display: flex;
	align-items: flex-end;
	gap: 10px;
	margin-bottom: 14px;
}

.pos-form__barcode {
	flex: 1;
}

.pos-form__label {
	display: block;
	margin-bottom: 6px;
	font-size: 14px;
	font-weight: 600;
}

.pos-form__lines-wrap {
	overflow-x: auto;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.pos-form__lines {
	width: 100%;
	border-collapse: collapse;
}

.pos-form__lines th {
	text-align: start;
	font-size: 14px;
	font-weight: 600;
	line-height: 1.2;
	color: var(--color-text-maxcontrast);
	background: var(--color-main-background);
	padding: 10px 18px;
	border-bottom: 1px solid var(--color-border);
	white-space: nowrap;
}

.pos-form__lines th.num {
	text-align: end;
}

.pos-form__fields :deep(.select__label) {
	font-size: 14px;
	font-weight: 600;
}

.pos-form__add.pos-form__add {
	min-height: 40px;
	height: 40px;
}

.pos-form__barcode :deep(input:focus) {
	border-color: var(--color-primary-element);
}

.pos-form__remove-customer.pos-form__remove-customer {
	border-radius: 18px;
}

.pos-form__hint,
.pos-form__consent-hint {
	margin: 12px 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.pos-form__fields {
	display: grid;
	grid-template-columns: 1.3fr 1fr 1fr;
	gap: 16px;
	margin-bottom: 12px;
}

.pos-form__notes {
	margin-top: 12px;
}

.pos-form__fields {
	align-items: end;
}

.pos-form__header-button.pos-form__header-button {
	order: 1;
	min-height: 34px;
	height: 34px;
	padding-inline: 12px;
}

/* Nextcloud's server.css pads every dl, dt and dd and pins a dt at 130px,
   right-aligned and unwrapped; the totals set their own grid. */
.pos-totals {
	display: grid;
	grid-template-columns: max-content 1fr;
	column-gap: 16px;
	row-gap: 4px;
	align-items: baseline;
	margin: 0 0 12px;
	padding: 0;
}

.pos-totals__row {
	display: contents;
}

.pos-totals__row dt,
.pos-totals__row dd {
	padding: 0;
}

.pos-totals__row dt {
	width: auto;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	white-space: normal;
	text-align: start;
}

.pos-totals__row dd {
	margin: 0;
}

.pos-totals__row--total dd {
	font-size: 20px;
	font-weight: 700;
}

.pos-form__closes.pos-form__closes {
	margin: 12px 0;
	padding: 10px 14px;
	border: 1px solid var(--color-success);
	border-radius: 8px;
}

.pos-form__checkout.pos-form__checkout {
	margin-top: 12px;
	min-height: 48px;
	height: 48px;
	font-size: 16px;
}

.pos-form__secondary {
	display: flex;
	gap: 8px;
	margin-top: 10px;
}

.pos-form__customer {
	display: flex;
	align-items: flex-start;
	gap: 12px;
}

.pos-form__avatar {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 40px;
	height: 40px;
	border-radius: 50%;
	background: var(--color-background-dark);
	color: var(--cn-board-text-soft, var(--color-main-text));
	font-weight: 700;
	font-size: 16px;
	flex-shrink: 0;
}

.pos-form__customer-text {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-width: 0;
}

.pos-form__customer-meta {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.pos-form__customer-empty {
	display: flex;
	align-items: center;
	gap: 12px;
}

.pos-form__error {
	color: var(--color-error);
	font-size: 12px;
}

.pos-form__consent {
	margin-top: 12px;
}

.pos-form__receipt {
	margin: 0;
	padding: 14px;
	border: 1px dashed var(--color-border-dark);
	border-radius: 6px;
	font-family: var(--font-face-monospace, monospace);
	font-size: 13px;
	line-height: 1.5;
	white-space: pre-wrap;
}

@media (max-width: 1024px) {
	.pos-form__layout,
	.pos-form__fields {
		grid-template-columns: minmax(0, 1fr);
	}
}
</style>
