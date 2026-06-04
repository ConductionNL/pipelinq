<template>
	<div class="product-barcode-search">
		<div class="product-barcode-search__header">
			<h2>{{ t('pipelinq', 'Barcode lookup') }}</h2>
			<p class="product-barcode-search__intro">
				{{ t('pipelinq', 'Scan a product or variant barcode to jump straight to the matching product.') }}
			</p>
		</div>

		<BarcodeScanner
			:status="scanStatus"
			:error-message="scanError"
			@scan="onScan" />

		<NcEmptyContent
			v-if="notFoundBarcode"
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
import { NcButton, NcEmptyContent } from '@nextcloud/vue'
import BarcodeOff from 'vue-material-design-icons/BarcodeOff.vue'
import BarcodeScanner from '../../components/products/BarcodeScanner.vue'
import { useBarcodeProductLookup } from '../../composables/useBarcodeProductLookup.js'

export default {
	name: 'ProductBarcodeSearch',
	components: {
		NcButton,
		NcEmptyContent,
		BarcodeOff,
		BarcodeScanner,
	},
	setup() {
		const { lookupByBarcode } = useBarcodeProductLookup()
		return { lookupByBarcode }
	},
	data() {
		return {
			scanStatus: 'idle',
			scanError: '',
			notFoundBarcode: '',
			errorTimer: null,
		}
	},
	beforeDestroy() {
		this.clearErrorTimer()
	},
	methods: {
		/**
		 * Resolve a scanned barcode server-side and navigate to the product.
		 *
		 * @param {string} barcode The scanned barcode.
		 */
		async onScan(barcode) {
			this.clearErrorTimer()
			this.scanStatus = 'loading'
			this.scanError = ''
			this.notFoundBarcode = ''

			const { product, variantIndex, status } = await this.lookupByBarcode(barcode)

			if (status === 'found') {
				this.scanStatus = 'found'
				const id = product.id || product.uuid || (product['@self'] && product['@self'].id)
				if (!id) {
					this.showNotFound(barcode)
					return
				}
				const query = {}
				if (variantIndex !== null && product.matchedVariantSku) {
					query.variant = product.matchedVariantSku
				}
				this.$router.push({ name: 'ProductDetail', params: { id }, query })
				return
			}

			if (status === 'ambiguous') {
				this.scanStatus = 'error'
				this.scanError = t('pipelinq', 'Multiple products found for barcode {barcode}', { barcode })
				this.scheduleErrorClear()
				return
			}

			// 'invalid' (malformed scan that fails the charset/length guard) and
			// 'not_found' both surface as "no product found".
			this.showNotFound(barcode)
		},
		/**
		 * Show the not-found state for a barcode and auto-clear it.
		 *
		 * @param {string} barcode The unmatched barcode.
		 */
		showNotFound(barcode) {
			this.scanStatus = 'error'
			this.scanError = t('pipelinq', 'No product found for barcode {barcode}', { barcode })
			this.notFoundBarcode = barcode
			this.scheduleErrorClear()
		},
		/**
		 * Auto-clear the error/empty state after 5 seconds.
		 */
		scheduleErrorClear() {
			this.clearErrorTimer()
			this.errorTimer = window.setTimeout(() => {
				this.scanStatus = 'idle'
				this.scanError = ''
				this.notFoundBarcode = ''
				this.errorTimer = null
			}, 5000)
		},
		/**
		 * Cancel any pending auto-clear timer.
		 */
		clearErrorTimer() {
			if (this.errorTimer !== null) {
				window.clearTimeout(this.errorTimer)
				this.errorTimer = null
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
</style>
