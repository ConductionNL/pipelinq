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
						{{
							t('pipelinq', '{count} leads', { count: item.leadCount })
						}}
					</span>
				</div>
				<span class="product-revenue__value">{{
					formatCurrency(item.totalValue)
				}}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import { formatCurrency as formatLocaleCurrency } from '../services/localeUtils.js'
import { jsonRequestHeaders } from '../services/requestHeaders.js'
import { useObjectStore } from '../store/modules/object.js'

/**
 * @param {object} config The object-type registry.
 * @return {(string|null)} The URL of every lead product, or null unless lead products and products are registered.
 */
function leadProductsUrl(config) {
	if (!config.leadProduct || !config.product) {
		return null
	}
	return generateUrl(
		`/apps/openregister/api/objects/${config.leadProduct.register}/${config.leadProduct.schema}?_limit=500`,
	)
}

/**
 * The three products with the highest line-item total, with the number of
 * distinct leads that carry each.
 *
 * @param {Array<object>} lineItems The lead products.
 * @return {Array<{productId: string, totalValue: number, leadCount: number}>} The top three, highest total first.
 */
function topProductTotals(lineItems) {
	const productMap = {}
	for (const item of lineItems) {
		if (item.product) {
			addLineItem(productMap, item)
		}
	}
	return Object.values(productMap)
		.map(({ leads, ...entry }) => ({ ...entry, leadCount: leads.size }))
		.sort((a, b) => b.totalValue - a.totalValue)
		.slice(0, 3)
}

/**
 * @param {object} productMap The totals so far, by product id; changed in place.
 * @param {object} item A line item with a product.
 * @return {void}
 */
function addLineItem(productMap, item) {
	const productId = item.product
	if (!productMap[productId]) {
		productMap[productId] = {
			productId,
			totalValue: 0,
			leadCount: 0,
			leads: new Set(),
		}
	}
	productMap[productId].totalValue += Number(item.total) || 0
	if (item.lead) {
		productMap[productId].leads.add(item.lead)
	}
}

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
		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-3
		 */
		objectStore() {
			return useObjectStore()
		},
	},

	async mounted() {
		await this.fetchData()
	},

	methods: {
		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-1
		 */
		async fetchData() {
			this.loading = true
			try {
				const lineItems = await this.fetchLineItems()
				this.topProducts = lineItems.length === 0
					? []
					: await this.withProductNames(topProductTotals(lineItems))
			} catch {
				this.topProducts = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Every lead product (line item); none when the types are not registered or the server refuses.
		 *
		 * @return {Promise<Array<object>>} The line items.
		 */
		async fetchLineItems() {
			const lpUrl = leadProductsUrl(this.objectStore.objectTypeRegistry)
			if (lpUrl === null) {
				return []
			}
			const lpResponse = await fetch(lpUrl, {
				headers: jsonRequestHeaders(),
			})
			if (!lpResponse.ok) {
				return []
			}
			const lpData = await lpResponse.json()
			return lpData.results || lpData || []
		},

		/**
		 * Name each product entry, in turn; a product that cannot be read is unknown.
		 *
		 * @param {Array<object>} entries The product totals.
		 * @return {Promise<Array<object>>} The same entries, named.
		 */
		async withProductNames(entries) {
			for (const entry of entries) {
				try {
					const product = await this.objectStore.fetchObject(
						'product',
						entry.productId,
					)
					entry.name =
						product?.name || t('pipelinq', 'Unknown Product')
				} catch {
					entry.name = t('pipelinq', 'Unknown Product')
				}
			}
			return entries
		},

		/**
		 * @param {string} value The amount to format.
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-2
		 */
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
	color: var(--color-success, #46ba61);
}
</style>
