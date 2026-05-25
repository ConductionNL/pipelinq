<template>
	<div v-if="editing || isNew">
		<div class="product-detail__header">
			<NcButton @click="onFormCancel">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New product') }}
			</h2>
			<h2 v-else>
				{{ productData.name || t('pipelinq', 'Product') }}
			</h2>
		</div>
		<ProductForm
			:product="productData"
			@save="onFormSave"
			@cancel="onFormCancel" />
	</div>

	<CnDetailPage
		v-else
		:title="productData.name || t('pipelinq', 'Product')"
		:subtitle="t('pipelinq', 'Product')"
		:back-route="{ name: 'Products' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="!isNew && !loading"
		object-type="pipelinq_product"
		:object-id="productId"
		:sidebar-props="sidebarProps">
		<template #actions>
			<NcButton type="primary" @click="editing = true">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton type="error" @click="confirmDelete">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Product Information')">
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
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Linked Leads')">
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
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import ProductForm from './ProductForm.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatCurrency as formatLocaleCurrency } from '../../services/localeUtils.js'

export default {
	name: 'ProductDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
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
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.product || {}
			return {
				title: t('pipelinq', 'Product'),
				register: config.register || '',
				schema: config.schema || '',
				hiddenTabs: ['tasks'],
			}
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
		async confirmDelete() {
			if (confirm(t('pipelinq', 'Are you sure you want to delete this product?'))) {
				const success = await this.objectStore.deleteObject('product', this.productId)
				if (success) {
					this.$router.push({ name: 'Products' })
				} else {
					const error = this.objectStore.getError('product')
					showError(error?.message || t('pipelinq', 'Failed to delete product.'))
				}
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
			return formatLocaleCurrency(value)
		},
	},
}
</script>

<style scoped>
.product-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
	padding: 20px 20px 0;
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
</style>
