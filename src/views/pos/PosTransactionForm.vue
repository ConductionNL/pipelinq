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
					:value.sync="transaction.terminalId"
					:label="t('pipelinq', 'Terminal')" />
				<NcSelect
					:value="selectedClient"
					:options="clientOptions"
					:input-label="t('pipelinq', 'Client (optional)')"
					label="label"
					:clearable="true"
					@input="onClientSelect" />
				<NcSelect
					:value="selectedPriceMode"
					:options="priceModeOptions"
					:input-label="t('pipelinq', 'Price mode')"
					label="label"
					:clearable="false"
					@input="onPriceModeSelect" />
				<NcTextField
					:value.sync="transaction.notes"
					:label="t('pipelinq', 'Notes')" />
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

			<div class="pos-form__actions">
				<NcButton type="primary" :disabled="saving" @click="save">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcLoadingIcon } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import Plus from 'vue-material-design-icons/Plus.vue'
import PosLineItemRow from '../../components/pos/PosLineItemRow.vue'
import PosTotalsPanel from '../../components/pos/PosTotalsPanel.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { recalculateLine } from '../../services/posTotals.js'

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
	},
	props: {
		posTransactionId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			transaction: { status: 'draft', terminalId: '', notes: '', client: null, priceMode: 'excl' },
			lines: [],
			products: [],
			clients: [],
			deletedLineIds: [],
			loading: false,
			saving: false,
			keyCounter: 0,
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
				{ id: 'excl', label: t('pipelinq', 'Excl. BTW') },
				{ id: 'incl', label: t('pipelinq', 'Incl. BTW') },
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
				this.products = this.objectStore.getCollection('product')?.results || []
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
				this.clients = this.objectStore.getCollection('client')?.results || []
			} catch {
				this.clients = []
			}
		},
		/**
		 * Load an existing transaction and its lines.
		 */
		async loadTransaction() {
			const tx = await this.objectStore.fetchObject('posTransaction', this.transactionId)
			this.transaction = { ...tx }
			await this.objectStore.fetchCollection('posTransactionLine', { transaction: this.transactionId, _limit: 500 })
			const rows = this.objectStore.getCollection('posTransactionLine')?.results || []
			this.lines = rows
				.filter(l => l.transaction === this.transactionId)
				.sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
				.map(l => ({ ...l, _key: this.nextKey() }))
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
			this.$set(this.lines, index, { ...line, _key: this.lines[index]._key })
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
		 * Persist the transaction header and its lines.
		 *
		 * Totals are intentionally NOT sent: the backend recomputes them
		 * server-side on confirm. The client only persists the editable header
		 * + line inputs.
		 */
		async save() {
			this.saving = true
			try {
				const header = {
					...this.transaction,
					status: this.transaction.status || 'draft',
				}
				const savedTx = await this.objectStore.saveObject('posTransaction', header)
				if (!savedTx) {
					showError(t('pipelinq', 'Failed to save transaction.'))
					return
				}
				const txId = savedTx.id || this.transactionId

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
			this.$set(this.transaction, 'priceMode', option ? option.id : 'excl')
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
</style>
