<template>
	<div class="quote-line-items">
		<div class="quote-line-items__header">
			<h3>{{ t('pipelinq', 'Line Items') }}</h3>
			<div v-if="editable" class="quote-line-items__actions">
				<NcButton type="secondary" @click="showAddDialog = true">
					{{ t('pipelinq', 'Add product') }}
				</NcButton>
				<NcButton type="tertiary" @click="showCustomDialog = true">
					{{ t('pipelinq', 'Custom line') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="lineItems.length === 0" class="quote-line-items__empty">
			<p>{{ t('pipelinq', 'No line items added to this quote yet.') }}</p>
		</div>

		<div v-else>
			<div class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Description') }}</th>
							<th>{{ t('pipelinq', 'Qty') }}</th>
							<th>{{ t('pipelinq', 'Unit Price') }}</th>
							<th>{{ t('pipelinq', 'Discount') }}</th>
							<th>{{ t('pipelinq', 'Total') }}</th>
							<th v-if="editable" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in sortedLineItems" :key="item.id">
							<td>{{ item.description }}</td>
							<td>
								<input
									v-if="editable"
									v-model.number="item.quantity"
									type="number"
									min="0.01"
									step="0.01"
									class="inline-input inline-input--qty"
									@change="updateLineItem(item)">
								<span v-else>{{ item.quantity }}</span>
							</td>
							<td>
								<input
									v-if="editable"
									v-model.number="item.unitPrice"
									type="number"
									min="0"
									step="0.01"
									class="inline-input inline-input--price"
									@change="updateLineItem(item)">
								<span v-else>{{ formatCurrency(item.unitPrice) }}</span>
							</td>
							<td>
								<input
									v-if="editable"
									v-model.number="item.discount"
									type="number"
									min="0"
									max="100"
									step="0.01"
									class="inline-input inline-input--discount"
									@change="updateLineItem(item)">
								<span v-else>{{ item.discount || 0 }}%</span>
							</td>
							<td class="total-cell">
								{{ formatCurrency(calculateTotal(item)) }}
							</td>
							<td v-if="editable">
								<NcButton type="tertiary" @click="removeLineItem(item)">
									{{ t('pipelinq', 'Remove') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
					<tfoot>
						<tr class="subtotal-row">
							<td :colspan="editable ? 4 : 4" class="total-label">
								{{ t('pipelinq', 'Subtotal') }}
							</td>
							<td class="total-cell">{{ formatCurrency(subtotal) }}</td>
							<td v-if="editable" />
						</tr>
						<tr class="tax-row">
							<td :colspan="editable ? 4 : 4" class="total-label">
								{{ t('pipelinq', 'BTW ({rate}%)', { rate: taxRate }) }}
							</td>
							<td class="total-cell">{{ formatCurrency(taxAmount) }}</td>
							<td v-if="editable" />
						</tr>
						<tr class="total-row">
							<td :colspan="editable ? 4 : 4" class="total-label">
								{{ t('pipelinq', 'Total') }}
							</td>
							<td class="total-cell total-cell--grand">
								{{ formatCurrency(grandTotal) }}
							</td>
							<td v-if="editable" />
						</tr>
					</tfoot>
				</table>
			</div>
		</div>

		<!-- Add product dialog -->
		<div v-if="showAddDialog" class="create-overlay" @click.self="showAddDialog = false">
			<div class="create-dialog">
				<div class="create-dialog__header">
					<h3>{{ t('pipelinq', 'Add Product') }}</h3>
					<NcButton type="tertiary" @click="showAddDialog = false">
						&#x2715;
					</NcButton>
				</div>
				<div class="create-dialog__body">
					<div class="form-group">
						<label>{{ t('pipelinq', 'Product') }} *</label>
						<NcSelect
							v-model="addForm.product"
							:options="productOptions"
							:placeholder="t('pipelinq', 'Search products...')"
							label="name"
							:reduce="opt => opt.id"
							@input="onProductSelect" />
					</div>
					<div class="form-row">
						<div class="form-group">
							<label>{{ t('pipelinq', 'Quantity') }}</label>
							<NcTextField
								:value="String(addForm.quantity)"
								type="number"
								@update:value="v => addForm.quantity = Number(v)" />
						</div>
						<div class="form-group">
							<label>{{ t('pipelinq', 'Unit Price') }}</label>
							<NcTextField
								:value="String(addForm.unitPrice)"
								type="number"
								@update:value="v => addForm.unitPrice = Number(v)" />
						</div>
						<div class="form-group">
							<label>{{ t('pipelinq', 'Discount (%)') }}</label>
							<NcTextField
								:value="String(addForm.discount)"
								type="number"
								@update:value="v => addForm.discount = Number(v)" />
						</div>
					</div>
					<div class="form-actions">
						<NcButton type="primary" :disabled="!addForm.product" @click="addProductLineItem">
							{{ t('pipelinq', 'Add') }}
						</NcButton>
						<NcButton @click="showAddDialog = false">
							{{ t('pipelinq', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>

		<!-- Custom line item dialog -->
		<div v-if="showCustomDialog" class="create-overlay" @click.self="showCustomDialog = false">
			<div class="create-dialog">
				<div class="create-dialog__header">
					<h3>{{ t('pipelinq', 'Custom Line Item') }}</h3>
					<NcButton type="tertiary" @click="showCustomDialog = false">
						&#x2715;
					</NcButton>
				</div>
				<div class="create-dialog__body">
					<div class="form-group">
						<label>{{ t('pipelinq', 'Description') }} *</label>
						<NcTextField
							:value="customForm.description"
							@update:value="v => customForm.description = v" />
					</div>
					<div class="form-row">
						<div class="form-group">
							<label>{{ t('pipelinq', 'Quantity') }}</label>
							<NcTextField
								:value="String(customForm.quantity)"
								type="number"
								@update:value="v => customForm.quantity = Number(v)" />
						</div>
						<div class="form-group">
							<label>{{ t('pipelinq', 'Unit Price') }}</label>
							<NcTextField
								:value="String(customForm.unitPrice)"
								type="number"
								@update:value="v => customForm.unitPrice = Number(v)" />
						</div>
						<div class="form-group">
							<label>{{ t('pipelinq', 'Discount (%)') }}</label>
							<NcTextField
								:value="String(customForm.discount)"
								type="number"
								@update:value="v => customForm.discount = Number(v)" />
						</div>
					</div>
					<div class="form-actions">
						<NcButton type="primary" :disabled="!customForm.description.trim()" @click="addCustomLineItem">
							{{ t('pipelinq', 'Add') }}
						</NcButton>
						<NcButton @click="showCustomDialog = false">
							{{ t('pipelinq', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'QuoteLineItems',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},
	props: {
		quoteId: {
			type: String,
			required: true,
		},
		taxRate: {
			type: Number,
			default: 21,
		},
		editable: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['totals-changed'],
	data() {
		return {
			lineItems: [],
			products: [],
			loading: false,
			showAddDialog: false,
			showCustomDialog: false,
			addForm: {
				product: null,
				quantity: 1,
				unitPrice: 0,
				discount: 0,
			},
			customForm: {
				description: '',
				quantity: 1,
				unitPrice: 0,
				discount: 0,
			},
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		productOptions() {
			return this.products
				.filter(p => p.status !== 'inactive')
				.map(p => ({ id: p.id, name: p.name || p.id }))
		},
		sortedLineItems() {
			return [...this.lineItems].sort((a, b) => (a.sortOrder || 0) - (b.sortOrder || 0))
		},
		subtotal() {
			return this.lineItems.reduce((sum, item) => sum + this.calculateTotal(item), 0)
		},
		taxAmount() {
			return this.subtotal * (this.taxRate / 100)
		},
		grandTotal() {
			return this.subtotal + this.taxAmount
		},
	},
	async mounted() {
		await this.fetchData()
	},
	methods: {
		async fetchData() {
			this.loading = true
			try {
				const [items, prods] = await Promise.all([
					this.objectStore.fetchCollection('quoteLineItem', {
						_limit: 100,
						quote: this.quoteId,
					}),
					this.objectStore.fetchCollection('product', { _limit: 200 }),
				])
				this.lineItems = (items || []).map(item => ({ ...item }))
				this.products = prods || []
			} catch {
				this.lineItems = []
				this.products = []
			} finally {
				this.loading = false
			}
			this.emitTotals()
		},
		calculateTotal(item) {
			const qty = Number(item.quantity) || 0
			const price = Number(item.unitPrice) || 0
			const discount = Number(item.discount) || 0
			return (qty * price) * (1 - discount / 100)
		},
		onProductSelect(productId) {
			const product = this.products.find(p => p.id === productId)
			if (product) {
				this.addForm.unitPrice = Number(product.unitPrice) || 0
			}
		},
		async addProductLineItem() {
			if (!this.addForm.product) return
			const product = this.products.find(p => p.id === this.addForm.product)
			try {
				const total = this.calculateTotal(this.addForm)
				await this.objectStore.saveObject('quoteLineItem', {
					quote: this.quoteId,
					product: this.addForm.product,
					description: product?.name || '',
					quantity: this.addForm.quantity,
					unitPrice: this.addForm.unitPrice,
					discount: this.addForm.discount,
					total,
					sortOrder: this.lineItems.length,
				})
				this.showAddDialog = false
				this.resetAddForm()
				await this.fetchData()
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to add line item'))
			}
		},
		async addCustomLineItem() {
			if (!this.customForm.description.trim()) return
			try {
				const total = this.calculateTotal(this.customForm)
				await this.objectStore.saveObject('quoteLineItem', {
					quote: this.quoteId,
					description: this.customForm.description,
					quantity: this.customForm.quantity,
					unitPrice: this.customForm.unitPrice,
					discount: this.customForm.discount,
					total,
					sortOrder: this.lineItems.length,
				})
				this.showCustomDialog = false
				this.resetCustomForm()
				await this.fetchData()
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to add line item'))
			}
		},
		async updateLineItem(item) {
			try {
				const total = this.calculateTotal(item)
				await this.objectStore.saveObject('quoteLineItem', {
					id: item.id,
					quantity: item.quantity,
					unitPrice: item.unitPrice,
					discount: item.discount,
					total,
				})
				item.total = total
				this.emitTotals()
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to update line item'))
			}
		},
		async removeLineItem(item) {
			if (!confirm(t('pipelinq', 'Remove this line item?'))) return
			try {
				await this.objectStore.deleteObject('quoteLineItem', item.id)
				await this.fetchData()
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to remove line item'))
			}
		},
		emitTotals() {
			this.$emit('totals-changed', {
				subtotal: this.subtotal,
				taxAmount: this.taxAmount,
				total: this.grandTotal,
				itemCount: this.lineItems.length,
			})
		},
		resetAddForm() {
			this.addForm = { product: null, quantity: 1, unitPrice: 0, discount: 0 }
		},
		resetCustomForm() {
			this.customForm = { description: '', quantity: 1, unitPrice: 0, discount: 0 }
		},
		formatCurrency(value) {
			if (value === null || value === undefined) return '-'
			return 'EUR ' + Number(value).toLocaleString('nl-NL', { minimumFractionDigits: 2 })
		},
	},
}
</script>

<style scoped>
.quote-line-items__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.quote-line-items__header h3 {
	margin: 0;
}

.quote-line-items__actions {
	display: flex;
	gap: 8px;
}

.quote-line-items__empty {
	color: var(--color-text-maxcontrast);
	padding: 12px 0;
}

.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
}

.viewTable th,
.viewTable td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.inline-input {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 4px 6px;
	font-size: 13px;
	background: var(--color-main-background);
}

.inline-input--qty { width: 70px; }
.inline-input--price, .inline-input--discount { width: 90px; }

.total-cell { font-weight: 600; }
.subtotal-row, .tax-row { background: var(--color-background-hover); }
.total-row { background: var(--color-background-dark); }
.total-label { text-align: right; font-weight: 700; }
.total-cell--grand { font-size: 15px; }

.create-overlay {
	position: fixed;
	top: 0; left: 0; right: 0; bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.create-dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
	width: 500px;
	max-width: 90vw;
	max-height: 85vh;
	overflow-y: auto;
}

.create-dialog__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
}

.create-dialog__header h3 { margin: 0; }
.create-dialog__body { padding: 20px; }

.form-group { margin-bottom: 12px; }
.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}

.form-row { display: flex; gap: 12px; }
.form-row .form-group { flex: 1; }
.form-actions { display: flex; gap: 8px; margin-top: 16px; }
</style>
