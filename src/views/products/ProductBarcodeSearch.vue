<template>
	<div class="product-barcode-search">
		<div class="product-barcode-search__header">
			<h2>{{ t('pipelinq', 'Barcode lookup') }}</h2>
			<p class="product-barcode-search__intro">
				{{ t('pipelinq', 'Scan a product or variant barcode to jump straight to the matching product.') }}
			</p>
		</div>

		<BarcodeInput @scan="onScan" />

		<NcLoadingIcon v-if="loading" :size="32" class="product-barcode-search__loading" />

		<NcEmptyContent
			v-else-if="notFoundBarcode"
			:name="t('pipelinq', 'No product found')"
			:description="t('pipelinq', 'No product found for barcode {barcode}', { barcode: notFoundBarcode })">
			<template #icon>
				<BarcodeOff :size="48" />
			</template>
			<template #action>
				<NcButton @click="goToList">
					{{ t('pipelinq', 'Search by name instead') }}
				</NcButton>
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import BarcodeOff from 'vue-material-design-icons/BarcodeOff.vue'
import BarcodeInput from '../../components/products/BarcodeInput.vue'

export default {
	name: 'ProductBarcodeSearch',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		BarcodeOff,
		BarcodeInput,
	},
	data() {
		return {
			loading: false,
			notFoundBarcode: '',
		}
	},
	methods: {
		/**
		 * Resolve a scanned barcode server-side and navigate to the product.
		 *
		 * @param {string} barcode The scanned barcode.
		 */
		async onScan(barcode) {
			this.loading = true
			this.notFoundBarcode = ''
			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/products/barcode-lookup'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify({ barcode }),
					},
				)

				if (response.status === 404) {
					this.notFoundBarcode = barcode
					return
				}

				if (!response.ok) {
					showError(t('pipelinq', 'Barcode lookup failed'))
					return
				}

				const data = await response.json()
				const product = data.product || {}
				const id = product.id || product.uuid || (product['@self'] && product['@self'].id)
				if (!id) {
					this.notFoundBarcode = barcode
					return
				}

				const query = {}
				if (product.matchedVariantSku) {
					query.variant = product.matchedVariantSku
				}
				this.$router.push({ name: 'ProductDetail', params: { id }, query })
			} catch (e) {
				showError(t('pipelinq', 'Barcode lookup failed'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Navigate to the product list to search by name.
		 */
		goToList() {
			this.$router.push({ name: 'Products' })
		},
	},
}
</script>

<style scoped>
.product-barcode-search {
	padding: 20px;
	max-width: 720px;
}

.product-barcode-search__header {
	margin-bottom: 16px;
}

.product-barcode-search__intro {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.product-barcode-search__loading {
	margin-top: 24px;
}
</style>
