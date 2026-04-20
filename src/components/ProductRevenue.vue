<template>
	<div class="product-revenue chart-card">
		<h3>{{ t('pipelinq', 'Top Products by Pipeline Value') }}</h3>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="topProducts.length === 0" class="chart-empty">
			{{ t('pipelinq', 'No product data yet') }}
		</div>

		<div v-else class="product-revenue__list">
			<div
				v-for="item in topProducts"
				:key="item.productId"
				class="product-revenue__item">
				<div class="product-revenue__info">
					<span class="product-revenue__name">{{ item.name }}</span>
					<span class="product-revenue__count">
						{{ t('pipelinq', '{count} leads', { count: item.leadCount }) }}
					</span>
				</div>
				<span class="product-revenue__value">{{ formatCurrency(item.totalValue) }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../store/modules/object.js'
import { formatCurrency as formatLocaleCurrency } from '../services/localeUtils.js'

export default {
	name: 'ProductRevenue',
	components: {
		NcLoadingIcon,
	},
	data() {
		return {
			loading: false,
			topProducts: [],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
	},
	async mounted() {
		await this.fetchData()
	},
	methods: {
		async fetchData() {
			this.loading = true
			try {
				const config = this.objectStore.objectTypeRegistry
				if (!config.leadProduct || !config.product) {
					this.topProducts = []
					return
				}

				// Fetch all lead products
				const lpUrl = `/apps/openregister/api/objects/${config.leadProduct.register}/${config.leadProduct.schema}?_limit=500`
				const lpResponse = await fetch(lpUrl, {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})
				if (!lpResponse.ok) {
					this.topProducts = []
					return
				}
				const lpData = await lpResponse.json()
				const lineItems = lpData.results || lpData || []

				if (lineItems.length === 0) {
					this.topProducts = []
					return
				}

				// Aggregate by product
				const productMap = {}
				for (const item of lineItems) {
					const productId = item.product
					if (!productId) continue
					const total = Number(item.total) || 0
					if (!productMap[productId]) {
						productMap[productId] = { productId, totalValue: 0, leadCount: 0, leads: new Set() }
					}
					productMap[productId].totalValue += total
					if (item.lead) {
						productMap[productId].leads.add(item.lead)
					}
				}

				// Set lead counts
				for (const entry of Object.values(productMap)) {
					entry.leadCount = entry.leads.size
					delete entry.leads
				}

				// Sort by total value descending, take top 3
				const sorted = Object.values(productMap)
					.sort((a, b) => b.totalValue - a.totalValue)
					.slice(0, 3)

				// Fetch product names
				for (const entry of sorted) {
					try {
						const product = await this.objectStore.fetchObject('product', entry.productId)
						entry.name = product?.name || t('pipelinq', 'Unknown Product')
					} catch {
						entry.name = t('pipelinq', 'Unknown Product')
					}
				}

				this.topProducts = sorted
			} catch {
				this.topProducts = []
			} finally {
				this.loading = false
			}
		},
		formatCurrency(value) {
			return formatLocaleCurrency(value)
		},
	},
}
</script>

<style scoped>
.product-revenue__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.product-revenue__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 8px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.product-revenue__info {
	display: flex;
	flex-direction: column;
}

.product-revenue__name {
	font-weight: 600;
	font-size: 14px;
}

.product-revenue__count {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.product-revenue__value {
	font-weight: 700;
	font-size: 15px;
	color: #46ba61;
}
</style>
