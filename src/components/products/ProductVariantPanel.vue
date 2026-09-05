<template>
	<div class="product-variant-panel">
		<div v-if="variants.length === 0" class="section-empty">
			<p>{{ t('pipelinq', 'No variants defined') }}</p>
		</div>
		<div v-else class="viewTableContainer">
			<table class="viewTable">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'SKU') }}</th>
						<th scope="col">{{ t('pipelinq', 'Name') }}</th>
						<th scope="col">{{ t('pipelinq', 'Attributes') }}</th>
						<th scope="col">{{ t('pipelinq', 'Price') }}</th>
						<th scope="col">{{ t('pipelinq', 'Barcode') }}</th>
						<th scope="col">{{ t('pipelinq', 'Status') }}</th>
						<th scope="col" class="product-variant-panel__actions-col" />
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="(variant, index) in variants"
						:key="index"
						class="viewTableRow"
						:class="{
							'product-variant-panel__row--highlight':
								variant.sku === highlightSku,
						}">
						<td>{{ variant.sku }}</td>
						<td>{{ variant.name || '-' }}</td>
						<td>{{ attributesLabel(variant.attributes) }}</td>
						<td>{{ formatCurrency(variant.unitPrice) }}</td>
						<td>{{ variant.barcode || '-' }}</td>
						<td>
							<span
								class="status-badge"
								:class="'status--' + (variant.status || 'active')">
								{{ variant.status || 'active' }}
							</span>
						</td>
						<td class="product-variant-panel__actions-col">
							<NcButton
								variant="tertiary"
								:aria-label="t('pipelinq', 'Edit variant')"
								@click="openEdit(index)">
								<template #icon>
									<Pencil :size="20" />
								</template>
							</NcButton>
							<NcButton
								variant="tertiary"
								:aria-label="t('pipelinq', 'Remove variant')"
								@click="removeVariant(index)">
								<template #icon>
									<Delete :size="20" />
								</template>
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="product-variant-panel__toolbar">
			<NcButton @click="openAdd">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('pipelinq', 'Add variant') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ t('pipelinq', 'Save variants') }}
			</NcButton>
		</div>

		<ProductVariantDialog
			v-if="dialogOpen"
			:variant="editingVariant"
			:defaultPrice="product.unitPrice || 0"
			:existingSkus="variantSkus"
			@close="dialogOpen = false"
			@save="onDialogSave" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ProductVariantDialog from '../../modals/ProductVariantDialog.vue'
import { formatCurrency as formatLocaleCurrency } from '../../services/localeUtils.js'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ProductVariantPanel',
	components: {
		NcButton,
		Plus,
		Pencil,
		Delete,
		ProductVariantDialog,
	},

	props: {
		product: {
			type: Object,
			required: true,
		},

		highlightSku: {
			type: String,
			default: '',
		},
	},

	emits: ['saved'],
	data() {
		return {
			variants: [],
			dialogOpen: false,
			editingIndex: null,
			saving: false,
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		editingVariant() {
			return this.editingIndex !== null
				? this.variants[this.editingIndex]
				: null
		},

		variantSkus() {
			return this.variants.map((v) => v.sku)
		},
	},

	watch: {
		product: {
			immediate: true,
			handler() {
				this.loadVariants()
			},
		},
	},

	methods: {
		/**
		 * Deep-clone the product's variants into editable state.
		 */
		loadVariants() {
			const raw = Array.isArray(this.product.variants)
				? this.product.variants
				: []
			this.variants = raw.map((v) => ({
				sku: v.sku || '',
				name: v.name || '',
				attributes: { ...(v.attributes || {}) },
				unitPrice: Number(v.unitPrice ?? this.product.unitPrice ?? 0),
				barcode: v.barcode || '',
				status: v.status || 'active',
			}))
		},

		/**
		 * Render an attributes map as a compact label.
		 *
		 * @param {object} attributes The attributes map.
		 * @return {string} The label.
		 */
		attributesLabel(attributes) {
			if (!attributes || typeof attributes !== 'object') {
				return '-'
			}
			const parts = Object.entries(attributes).map(([k, v]) => `${k}: ${v}`)
			return parts.length > 0 ? parts.join(', ') : '-'
		},

		/**
		 * Format a currency value.
		 *
		 * @param {number} value The value.
		 * @return {string} The formatted value.
		 */
		formatCurrency(value) {
			if (!value && value !== 0) {
				return '-'
			}
			return formatLocaleCurrency(value)
		},

		/**
		 * Open the add-variant dialog.
		 */
		openAdd() {
			this.editingIndex = null
			this.dialogOpen = true
		},

		/**
		 * Open the edit-variant dialog for a row.
		 *
		 * @param {number} index The row index.
		 */
		openEdit(index) {
			this.editingIndex = index
			this.dialogOpen = true
		},

		/**
		 * Apply a saved variant from the dialog (SKU uniqueness already enforced).
		 *
		 * @param {object} variant The variant data.
		 */
		onDialogSave(variant) {
			if (this.editingIndex !== null) {
				this.variants.splice(this.editingIndex, 1, variant)
			} else {
				this.variants.push(variant)
			}
			this.dialogOpen = false
			this.editingIndex = null
		},

		/**
		 * Remove a variant row.
		 *
		 * @param {number} index The row index.
		 */
		removeVariant(index) {
			this.variants.splice(index, 1)
		},

		/**
		 * Persist the variants to the product.
		 *
		 * @spec openspec/specs/pos-product-catalogue/spec.md#REQ-PPC-001
		 */
		async save() {
			this.saving = true
			try {
				const result = await this.objectStore.saveObject('product', {
					...this.product,
					variants: this.variants,
				})
				if (result) {
					showSuccess(t('pipelinq', 'Variants saved'))
					this.$emit('saved', result)
				} else {
					showError(t('pipelinq', 'Failed to save variants'))
				}
			} catch {
				showError(t('pipelinq', 'Failed to save variants'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.product-variant-panel__toolbar {
	display: flex;
	gap: 12px;
	margin-top: 12px;
}

.product-variant-panel__actions-col {
	width: 96px;
	text-align: end;
	white-space: nowrap;
}

.product-variant-panel__row--highlight {
	background: var(--color-primary-element-light);
}

.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
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
}

.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
}

.status--active {
	background: #dcfce7;
	color: #166534;
	border: 1px solid #86efac;
}

.status--inactive {
	background: #f3f4f6;
	color: #6b7280;
	border: 1px solid #d1d5db;
}

.section-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 16px;
}
</style>
