<template>
	<div class="product-detail">
		<div class="product-detail__header">
			<NcButton @click="$router.push({ name: 'Products' })">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New product') }}
			</h2>
			<h2 v-else>
				{{ productData.name || t('pipelinq', 'Product') }}
			</h2>
		</div>

		<NcLoadingIcon v-if="loading" />

		<!-- Edit / Create mode -->
		<ProductForm
			v-else-if="editing || isNew"
			:product="productData"
			@save="onFormSave"
			@cancel="onFormCancel" />

		<!-- View mode -->
		<div v-else class="product-detail__info">
			<div class="product-detail__actions">
				<NcButton type="primary" @click="editing = true">
					{{ t('pipelinq', 'Edit') }}
				</NcButton>
				<NcButton type="error" @click="showDeleteWarning">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</div>

			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Type') }}</label>
					<span>{{ productData.type || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<span class="status-badge" :class="'status--' + (productData.status || 'active')">
						{{ productData.status || 'active' }}
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'SKU') }}</label>
					<span>{{ productData.sku || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Unit Price') }}</label>
					<span>{{ formatCurrency(productData.unitPrice) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Cost') }}</label>
					<span>{{ productData.cost ? formatCurrency(productData.cost) : '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Unit') }}</label>
					<span>{{ productData.unit || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Tax Rate') }}</label>
					<span>{{ productData.taxRate !== undefined ? productData.taxRate + '%' : '21%' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Category') }}</label>
					<span>{{ categoryName || '-' }}</span>
				</div>
			</div>
			<div v-if="productData.description" class="info-field info-field--full">
				<label>{{ t('pipelinq', 'Description') }}</label>
				<p>{{ productData.description }}</p>
			</div>
		</div>

		<!-- Linked Leads section -->
		<div v-if="!isNew && !loading && !editing" class="product-detail__section">
			<div class="section-header">
				<h3>{{ t('pipelinq', 'Linked Leads') }}</h3>
			</div>

			<div v-if="linkedLeads.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No leads linked to this product') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Lead') }}</th>
							<th>{{ t('pipelinq', 'Quantity') }}</th>
							<th>{{ t('pipelinq', 'Unit Price') }}</th>
							<th>{{ t('pipelinq', 'Total') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="item in linkedLeads"
							:key="item.id"
							class="viewTableRow"
							@click="openLead(item)">
							<td>{{ item.leadTitle || item.lead }}</td>
							<td>{{ item.quantity }}</td>
							<td>{{ formatCurrency(item.unitPrice) }}</td>
							<td>{{ formatCurrency(item.total) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<!-- Delete warning dialog -->
		<NcDialog
			v-if="showDelete"
			:name="t('pipelinq', 'Delete product')"
			@closing="showDelete = false">
			<p>
				{{ t('pipelinq', 'Are you sure you want to delete "{name}"?', { name: productData.name }) }}
			</p>
			<p v-if="linkedLeads.length" class="delete-warning">
				{{ n('pipelinq', 'This product is linked to %n lead.', 'This product is linked to %n leads.', linkedLeads.length) }}
			</p>
			<template #actions>
				<NcButton @click="showDelete = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="confirmDelete">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcDialog } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import ProductForm from './ProductForm.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ProductDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcDialog,
		ProductForm,
	},
	props: {
		productId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			editing: false,
			linkedLeads: [],
			showDelete: false,
			categoryName: '',
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.productId || this.productId === 'new'
		},
		loading() {
			return this.objectStore.loading.product || false
		},
		productData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('product', this.productId) || {}
		},
	},
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('product', this.productId)
			await this.fetchRelated()
		}
	},
	methods: {
		async onFormSave(formData) {
			const result = await this.objectStore.saveObject('product', formData)
			if (result) {
				if (this.isNew) {
					this.$router.push({ name: 'ProductDetail', params: { id: result.id } })
				} else {
					await this.objectStore.fetchObject('product', this.productId)
					this.editing = false
				}
			} else {
				const error = this.objectStore.getError('product')
				showError(error?.message || t('pipelinq', 'Failed to save product. Please try again.'))
			}
		},
		onFormCancel() {
			if (this.isNew) {
				this.$router.push({ name: 'Products' })
			} else {
				this.editing = false
			}
		},
		showDeleteWarning() {
			this.showDelete = true
		},
		async confirmDelete() {
			this.showDelete = false
			const success = await this.objectStore.deleteObject('product', this.productId)
			if (success) {
				this.$router.push({ name: 'Products' })
			} else {
				const error = this.objectStore.getError('product')
				showError(error?.message || t('pipelinq', 'Failed to delete product.'))
			}
		},
		async fetchRelated() {
			try {
				const leadProducts = await this.objectStore.fetchCollection('leadProduct', {
					_limit: 50,
					product: this.productId,
				})
				this.linkedLeads = leadProducts || []
			} catch {
				this.linkedLeads = []
			}

			if (this.productData.category) {
				try {
					const cat = await this.objectStore.fetchObject('productCategory', this.productData.category)
					this.categoryName = cat?.name || ''
				} catch {
					this.categoryName = ''
				}
			}
		},
		openLead(item) {
			if (item.lead) {
				this.$router.push({ name: 'LeadDetail', params: { id: item.lead } })
			}
		},
		formatCurrency(value) {
			if (!value && value !== 0) return '-'
			return 'EUR ' + Number(value).toLocaleString('nl-NL', { minimumFractionDigits: 2 })
		},
	},
}
</script>

<style scoped>
.product-detail {
	padding: 20px;
	max-width: 800px;
}

.product-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
}

.product-detail__actions {
	display: flex;
	gap: 12px;
	margin-bottom: 20px;
}

.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.info-field {
	margin-bottom: 8px;
}

.info-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.info-field span,
.info-field p {
	margin: 0;
}

.info-field--full {
	margin-top: 16px;
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

.product-detail__section {
	margin-top: 40px;
	border-top: 1px solid var(--color-border);
	padding-top: 20px;
}

.section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
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
	background-color: var(--color-main-background);
}

.viewTable th,
.viewTable td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.viewTableRow {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background: var(--color-background-hover);
}

.section-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
}

.delete-warning {
	font-weight: bold;
	margin-top: 12px;
}
</style>
