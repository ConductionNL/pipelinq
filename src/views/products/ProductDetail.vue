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
		:sidebar="{ enabled: !isNew && !loading }"
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
					<label>{{ t('pipelinq', 'BTW Class') }}</label>
					<span>{{ btwClassLabel }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Barcode') }}</label>
					<span>{{ productData.barcode || '-' }}</span>
				</div>
				<div v-if="productData.type === 'service'" class="info-field">
					<label>{{ t('pipelinq', 'Duration') }}</label>
					<span>{{ durationLabel }}</span>
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

		<CnDetailCard :title="t('pipelinq', 'Variants')">
			<ProductVariantPanel
				:product="productData"
				:highlight-sku="highlightVariantSku"
				@saved="onPanelSaved" />
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Modifier groups')">
			<ModifierGroupPanel
				:product="productData"
				@saved="onPanelSaved" />
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Price tiers')">
			<PriceTierTable
				:product="productData"
				@saved="onPanelSaved" />
		</CnDetailCard>

		<CnDetailCard :title="linkedLeadsTitle">
			<div v-if="linkedLeads.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No leads are using this product yet.') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Lead') }}</th>
							<th>{{ t('pipelinq', 'Stage') }}</th>
							<th>{{ t('pipelinq', 'Quantity') }}</th>
							<th>{{ t('pipelinq', 'Total') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="item in linkedLeads"
							:key="item.id"
							class="viewTableRow"
							@click="openLead(item)">
							<td>
								<a href="#" @click.prevent.stop="openLead(item)">
									{{ item.leadTitle || t('pipelinq', '[Deleted lead]') }}
								</a>
							</td>
							<td>{{ item.leadStage || '-' }}</td>
							<td>{{ item.quantity }}</td>
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
import ProductVariantPanel from '../../components/products/ProductVariantPanel.vue'
import ModifierGroupPanel from '../../components/products/ModifierGroupPanel.vue'
import PriceTierTable from '../../components/products/PriceTierTable.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatCurrency as formatLocaleCurrency } from '../../services/localeUtils.js'

export default {
	name: 'ProductDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		ProductForm,
		ProductVariantPanel,
		ModifierGroupPanel,
		PriceTierTable,
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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-8
		 */
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.productId || this.productId === 'new'
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-6
		 */
		loading() {
			return this.objectStore.loading.product || false
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-12
		 */
		productData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('product', this.productId) || {}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-13
		 */
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.product || {}
			return {
				title: t('pipelinq', 'Product'),
				register: config.register || '',
				schema: config.schema || '',
				hiddenTabs: ['tasks'],
			}
		},
		/**
		 * Human-readable BTW class label.
		 *
		 * @return {string} The label.
		 */
		btwClassLabel() {
			const map = {
				hoog: t('pipelinq', 'Hoog (21%)'),
				laag: t('pipelinq', 'Laag (9%)'),
				nul: t('pipelinq', 'Nul (0%)'),
				vrijgesteld: t('pipelinq', 'Vrijgesteld'),
			}
			return map[this.productData.btwClass] || '-'
		},
		/**
		 * Human-readable service duration label.
		 *
		 * @return {string} The label.
		 */
		durationLabel() {
			const minutes = Number(this.productData.duration)
			if (!minutes) {
				return '-'
			}
			if (minutes % 60 === 0) {
				const hours = minutes / 60
				return t('pipelinq', '{count} hour(s)', { count: hours })
			}
			return t('pipelinq', '{count} minute(s)', { count: minutes })
		},
		/**
		 * Variant SKU to highlight from the barcode-search ?variant query.
		 *
		 * @return {string} The SKU.
		 */
		highlightVariantSku() {
			return this.$route?.query?.variant || ''
		},
		/**
		 * Title for the "Linked Leads" card, including the live count.
		 *
		 * @return {string} The localized title.
		 * @spec openspec/changes/lead-product-link/tasks.md#task-5.1
		 */
		linkedLeadsTitle() {
			return t('pipelinq', 'Linked Leads ({count})', { count: this.linkedLeads.length })
		},
	},
	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-7
	 */
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('product', this.productId)
			await this.fetchRelated()
		}
	},
	methods: {
		/**
		 * @param formData
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-10
		 */
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
		/**
		 * Refresh the product after a variant / modifier / tier panel saved.
		 */
		async onPanelSaved() {
			await this.objectStore.fetchObject('product', this.productId)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-9
		 */
		onFormCancel() {
			if (this.isNew) {
				this.$router.push({ name: 'Products' })
			} else {
				this.editing = false
			}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-3
		 */
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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-4
		 */
		async fetchRelated() {
			try {
				const leadProducts = await this.objectStore.fetchCollection('leadProduct', {
					_limit: 50,
					product: this.productId,
				})
				const items = leadProducts || []
				// Resolve parent lead title + stage so the table is human-readable.
				const enriched = await Promise.all(items.map(async (lp) => {
					if (!lp.lead) return { ...lp, leadTitle: null, leadStage: null, leadCreatedAt: null }
					try {
						const lead = await this.objectStore.fetchObject('lead', lp.lead)
						return {
							...lp,
							leadTitle: lead?.title || null,
							leadStage: lead?.stage || null,
							leadCreatedAt: lead?._dateCreated || lead?.createdAt || null,
						}
					} catch {
						return { ...lp, leadTitle: null, leadStage: null, leadCreatedAt: null }
					}
				}))
				// Sort by lead creation date descending (most recent first); fall
				// back to the leadProduct id for stability when dates are equal.
				enriched.sort((a, b) => {
					const aDate = a.leadCreatedAt || ''
					const bDate = b.leadCreatedAt || ''
					if (aDate === bDate) return (a.id || '').localeCompare(b.id || '')
					return aDate < bDate ? 1 : -1
				})
				this.linkedLeads = enriched
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
		/**
		 * @param item
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-11
		 */
		openLead(item) {
			if (item.lead) {
				this.$router.push({ name: 'LeadDetail', params: { id: item.lead } })
			}
		},
		/**
		 * @param value
		 * @spec openspec/changes/reverse-2026-05-26-fe-products-ui/tasks.md#task-5
		 */
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
