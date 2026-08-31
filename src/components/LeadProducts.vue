<template>
	<div class="lead-products">
		<div class="lead-products__header">
			<h3>{{ t('pipelinq', 'Products') }}</h3>
			<NcButton variant="secondary" @click="showAddDialog = true">
				{{ t('pipelinq', 'Add Product') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="lineItems.length === 0" class="lead-products__empty">
			<p>{{ t('pipelinq', 'No products added to this lead yet.') }}</p>
		</div>

		<div v-else>
			<div class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Product') }}</th>
							<th scope="col">{{ t('pipelinq', 'Qty') }}</th>
							<th scope="col">{{ t('pipelinq', 'Unit Price') }}</th>
							<th scope="col">{{ t('pipelinq', 'Discount') }}</th>
							<th scope="col">{{ t('pipelinq', 'Total') }}</th>
							<th scope="col">{{ t('pipelinq', 'Notes') }}</th>
							<th scope="col" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in lineItems" :key="item.id">
							<td>{{ getProductName(item.product) }}</td>
							<td>
								<input
									v-model.number="item.quantity"
									type="number"
									min="1"
									class="inline-input inline-input--qty"
									:aria-label="
										t('pipelinq', 'Quantity for {product}', {
											product: getProductName(item.product),
										})
									"
									@change="updateLineItem(item)" />
							</td>
							<td>
								<input
									v-model.number="item.unitPrice"
									type="number"
									min="0"
									step="0.01"
									class="inline-input inline-input--price"
									:aria-label="
										t('pipelinq', 'Unit price for {product}', {
											product: getProductName(item.product),
										})
									"
									@change="updateLineItem(item)" />
							</td>
							<td>
								<input
									v-model.number="item.discount"
									type="number"
									min="0"
									step="0.01"
									class="inline-input inline-input--discount"
									:aria-label="
										t('pipelinq', 'Discount for {product}', {
											product: getProductName(item.product),
										})
									"
									@change="updateLineItem(item)" />
							</td>
							<td class="total-cell">
								{{ formatCurrency(calculateTotal(item)) }}
							</td>
							<td>
								<input
									v-model="item.notes"
									type="text"
									class="inline-input inline-input--notes"
									:placeholder="t('pipelinq', 'Notes…')"
									:aria-label="
										t('pipelinq', 'Notes for {product}', {
											product: getProductName(item.product),
										})
									"
									@change="updateNotes(item)" />
							</td>
							<td>
								<NcButton
									variant="tertiary"
									@click="removeLineItem(item)">
									{{ t('pipelinq', 'Remove') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
					<tfoot>
						<tr class="total-row">
							<td colspan="4" class="total-label">
								{{ t('pipelinq', 'Total') }}
							</td>
							<td class="total-cell total-cell--grand">
								{{ formatCurrency(grandTotal) }}
							</td>
							<td />
							<td />
						</tr>
					</tfoot>
				</table>
			</div>

			<!-- Auto-calc hint -->
			<div v-if="hasManualOverride" class="auto-calc-hint">
				{{
					t(
						'pipelinq',
						'Lead value is manually set to {manual}. Calculated total: {calculated}.',
						{
							manual: formatCurrency(leadValue),
							calculated: formatCurrency(grandTotal),
						},
					)
				}}
				<NcButton
					variant="tertiary"
					@click="$emit('syncValue', grandTotal)">
					{{ t('pipelinq', 'Use calculated value') }}
				</NcButton>
			</div>
		</div>

		<!-- Add product dialog -->
		<div v-if="showAddDialog" class="create-overlay">
			<div class="create-dialog">
				<div class="create-dialog__header">
					<h3>{{ t('pipelinq', 'Add Product') }}</h3>
					<NcButton
						variant="tertiary"
						:aria-label="t('pipelinq', 'Close')"
						@click="showAddDialog = false">
						✕
					</NcButton>
				</div>
				<div class="create-dialog__body">
					<div class="form-group">
						<label>{{ t('pipelinq', 'Product') }} *</label>
						<NcSelect
							v-model="addForm.product"
							:options="productOptions"
							:aria-label-combobox="t('pipelinq', 'Product')"
							:placeholder="t('pipelinq', 'Search products…')"
							label="name"
							:reduce="(opt) => opt.id"
							@update:modelValue="onProductSelect" />
					</div>
					<div class="form-row">
						<div class="form-group">
							<NcTextField
								id="lead-product-quantity"
								:label="t('pipelinq', 'Quantity')"
								:modelValue="String(addForm.quantity)"
								type="number"
								@update:modelValue="
									(v) => (addForm.quantity = Number(v))
								" />
						</div>
						<div class="form-group">
							<NcTextField
								id="lead-product-unit-price"
								:label="t('pipelinq', 'Unit Price')"
								:modelValue="String(addForm.unitPrice)"
								type="number"
								@update:modelValue="
									(v) => (addForm.unitPrice = Number(v))
								" />
						</div>
						<div class="form-group">
							<NcTextField
								id="lead-product-discount"
								:label="t('pipelinq', 'Discount')"
								:modelValue="String(addForm.discount)"
								type="number"
								@update:modelValue="
									(v) => (addForm.discount = Number(v))
								" />
						</div>
					</div>
					<div class="form-group">
						<label for="lead-product-notes">{{
							t('pipelinq', 'Notes')
						}}</label>
						<textarea
							id="lead-product-notes"
							v-model="addForm.notes"
							rows="2" />
					</div>
					<div class="form-actions">
						<NcButton
							variant="primary"
							:disabled="!addForm.product"
							@click="addLineItem">
							{{ t('pipelinq', 'Add') }}
						</NcButton>
						<NcButton @click="showAddDialog = false">
							{{ t('pipelinq', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { formatCurrency as formatLocaleCurrency } from '../services/localeUtils.js'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'LeadProducts',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	props: {
		leadId: {
			type: String,
			required: true,
		},

		leadValue: {
			type: Number,
			default: null,
		},
	},

	emits: ['valueChanged', 'syncValue'],
	data() {
		return {
			lineItems: [],
			products: [],
			loading: false,
			showAddDialog: false,
			addForm: {
				product: null,
				quantity: 1,
				unitPrice: 0,
				discount: 0,
				notes: '',
			},
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-17
		 */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * @spec openspec/changes/2026-03-20-lead-product-link/tasks.md#task-1.1
		 */
		productOptions() {
			return this.products.map((p) => ({
				id: p.id,
				name: p.sku ? `${p.name || p.id} (${p.sku})` : p.name || p.id,
			}))
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-15
		 */
		grandTotal() {
			return this.lineItems.reduce(
				(sum, item) => sum + this.calculateTotal(item),
				0,
			)
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-16
		 */
		hasManualOverride() {
			if (this.leadValue === null || this.leadValue === undefined) return false
			if (this.lineItems.length === 0) return false
			return Math.abs(Number(this.leadValue) - this.grandTotal) > 0.01
		},
	},

	async mounted() {
		await this.fetchData()
	},

	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-12
		 */
		async fetchData() {
			this.loading = true
			try {
				const [items, prods] = await Promise.all([
					this.objectStore.fetchCollection('leadProduct', {
						_limit: 100,
						lead: this.leadId,
					}),
					this.objectStore.fetchCollection('product', { _limit: 200 }),
				])
				this.lineItems = (items || []).map((item) => ({ ...item }))
				this.products = prods || []
			} catch {
				this.lineItems = []
				this.products = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {string} productId Identifier of the product.
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-14
		 */
		getProductName(productId) {
			const product = this.products.find((p) => p.id === productId)
			return product?.name || productId || '-'
		},

		/**
		 * @param {object} item The line item to total.
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-11
		 */
		calculateTotal(item) {
			const qty = Number(item.quantity) || 0
			const price = Number(item.unitPrice) || 0
			const discount = Number(item.discount) || 0
			return qty * price * (1 - discount / 100)
		},

		/**
		 * @param {string} productId Identifier of the product the user picked.
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-18
		 */
		onProductSelect(productId) {
			const product = this.products.find((p) => p.id === productId)
			if (product) {
				this.addForm.unitPrice = Number(product.unitPrice) || 0
			}
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-10
		 */
		async addLineItem() {
			if (!this.addForm.product) return

			try {
				const total = this.calculateTotal(this.addForm)
				await this.objectStore.saveObject('leadProduct', {
					lead: this.leadId,
					product: this.addForm.product,
					quantity: this.addForm.quantity,
					unitPrice: this.addForm.unitPrice,
					discount: this.addForm.discount,
					total,
					notes: this.addForm.notes,
				})
				this.showAddDialog = false
				this.resetAddForm()
				await this.fetchData()
				this.$emit('valueChanged', this.grandTotal)
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to add product'))
			}
		},

		/**
		 * @param {object} item The line item to persist.
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-22
		 */
		async updateLineItem(item) {
			try {
				const total = this.calculateTotal(item)
				await this.objectStore.saveObject('leadProduct', {
					id: item.id,
					quantity: item.quantity,
					unitPrice: item.unitPrice,
					discount: item.discount,
					notes: item.notes,
					total,
				})
				item.total = total
				this.$emit('valueChanged', this.grandTotal)
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to update line item'))
			}
		},

		/**
		 * @param {object} item The line item whose notes changed.
		 * @spec openspec/changes/2026-03-20-lead-product-link/tasks.md#task-2.2
		 */
		async updateNotes(item) {
			try {
				await this.objectStore.saveObject('leadProduct', { ...item })
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to update notes'))
			}
		},

		/**
		 * @param {object} item The line item to remove.
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-20
		 */
		async removeLineItem(item) {
			if (!confirm(t('pipelinq', 'Remove this product from the lead?'))) return

			try {
				await this.objectStore.deleteObject('leadProduct', item.id)
				await this.fetchData()
				this.$emit('valueChanged', this.grandTotal)
			} catch (e) {
				showError(e.message || t('pipelinq', 'Failed to remove line item'))
			}
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-21
		 */
		resetAddForm() {
			this.addForm = {
				product: null,
				quantity: 1,
				unitPrice: 0,
				discount: 0,
				notes: '',
			}
		},

		/**
		 * @param {string} value The amount to format.
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-13
		 */
		formatCurrency(value) {
			if (value === null || value === undefined) return '-'
			return formatLocaleCurrency(value)
		},
	},
}
</script>

<style scoped>
.lead-products {
	margin-top: 24px;
	border-top: 1px solid var(--color-border);
	padding-top: 16px;
}

.lead-products__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 12px;
}

.lead-products__header h3 {
	margin: 0;
}

.lead-products__empty {
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
	text-align: start;
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

.inline-input--qty {
	width: 60px;
}

.inline-input--price,
.inline-input--discount {
	width: 90px;
}

.inline-input--notes {
	width: 180px;
}

.total-cell {
	font-weight: 600;
}

.total-row {
	background: var(--color-background-dark);
}

.total-label {
	text-align: end;
	font-weight: 700;
}

.total-cell--grand {
	font-size: 15px;
}

.auto-calc-hint {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 8px;
	padding: 8px 12px;
	background: #fffbeb;
	border: 1px solid #fbbf24;
	border-radius: var(--border-radius);
	font-size: 13px;
	color: #92400e;
}

/* Add dialog */
.create-overlay {
	position: fixed;
	top: 0;
	inset-inline: 0;
	bottom: 0;
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

.create-dialog__header h3 {
	margin: 0;
}

.create-dialog__body {
	padding: 20px;
}

.form-group {
	margin-bottom: 12px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
	font-size: 13px;
}

.form-group textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	resize: vertical;
}

.form-row {
	display: flex;
	gap: 12px;
}

.form-row .form-group {
	flex: 1;
}

.form-actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
}
</style>
