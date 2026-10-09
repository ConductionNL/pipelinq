<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The Supply card on the product page (products-stock-on-hand), as board
  - PqProduct draws it ("Levering"): manufacturer, unit of measure, weight,
  - dimensions and the Stock tracked line, with "Open in shillinq".
  -
  - The Stock tracked line reads GET /api/products/{id}/stock on every load;
  - pipelinq keeps no copy of the number (D3). It shows the quantity available
  - summed over shillinq's locations, a second line naming the locations when
  - there are two or more, and on hand and reserved in the tooltip.
  -->
<template>
	<div class="product-supply" data-testid="product-supply">
		<dl class="product-supply__list">
			<template v-for="row in rows" :key="row.key">
				<dt>{{ row.label }}</dt>
				<dd>{{ row.value }}</dd>
			</template>
			<dt>{{ t('pipelinq', 'Stock tracked') }}</dt>
			<dd data-testid="product-stock">
				<span v-if="loading">{{ t('pipelinq', 'Loading…') }}</span>
				<template v-else>
					<span :title="stockTooltip">{{ stockText }}</span>
					<span
						v-if="locationsLine"
						class="product-supply__locations"
						data-testid="product-stock-locations">
						{{ locationsLine }}
					</span>
				</template>
			</dd>
		</dl>
		<a
			v-if="stock && stock.state !== 'no-shillinq'"
			class="product-supply__link"
			:href="shillinqUrl"
			target="_blank"
			rel="noopener">
			{{ t('pipelinq', 'Open in shillinq') }}
		</a>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ProductSupplyWidget',

	props: {
		/** The product, from the detail page. */
		objectData: {
			type: Object,
			default: null,
		},

		/** The product id, from the detail page. */
		objectId: {
			type: String,
			default: '',
		},

		/** The manifest widget title. */
		title: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			stock: null,
			loading: false,
		}
	},

	computed: {
		/**
		 * The plain supply fields that have a value.
		 *
		 * @return {Array<{key: string, label: string, value: string}>}
		 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
		 */
		rows() {
			const product = this.objectData || {}
			const rows = [
				{ key: 'manufacturer', label: t('pipelinq', 'Manufacturer'), value: product.manufacturer || '' },
				{ key: 'unit', label: t('pipelinq', 'Unit of measure'), value: product.unitOfMeasure || product.unit || '' },
				{ key: 'weight', label: t('pipelinq', 'Weight'), value: typeof product.weight === 'number' ? `${this.number(product.weight)} kg` : '' },
				{ key: 'dimensions', label: t('pipelinq', 'Dimensions'), value: this.dimensions(product.dimensions) },
			]
			return rows.filter((row) => row.value !== '')
		},

		/**
		 * The Stock tracked value.
		 *
		 * @return {string}
		 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-untracked-products-and-missing-stock-read-plainly-req-pst-002
		 */
		stockText() {
			const stock = this.stock
			if (!stock || stock.state === 'untracked') {
				return t('pipelinq', 'No')
			}
			if (stock.state === 'no-shillinq') {
				return t('pipelinq', 'Stock is kept in shillinq, which is not installed')
			}
			if (stock.state === 'no-access') {
				return t('pipelinq', 'No access to stock in shillinq')
			}
			const amount = [this.number(stock.available), stock.unit]
				.filter(Boolean)
				.join(' ')
			return t('pipelinq', 'Yes, {amount}', { amount })
		},

		/**
		 * On hand and reserved, for the tooltip.
		 *
		 * @return {string}
		 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
		 */
		stockTooltip() {
			if (!this.stock || this.stock.state !== 'ok') {
				return ''
			}
			return t('pipelinq', '{onHand} on hand, {reserved} reserved', {
				onHand: this.number(this.stock.onHand),
				reserved: this.number(this.stock.reserved),
			})
		},

		/**
		 * The locations, when there are two or more.
		 *
		 * @return {string}
		 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
		 */
		locationsLine() {
			const locations = (this.stock && this.stock.state === 'ok' && this.stock.locations) || []
			if (locations.length < 2) {
				return ''
			}
			return locations
				.map((location) => `${location.name} ${this.number(location.available)}`)
				.join(' · ')
		},

		/**
		 * The stock list in shillinq.
		 *
		 * @return {string}
		 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
		 */
		shillinqUrl() {
			return generateUrl('/apps/shillinq/inventory/stock')
		},
	},

	watch: {
		objectId: {
			immediate: true,
			/**
			 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
			 */
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the stock line from the server.
		 *
		 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
		 */
		async load() {
			if (!this.objectId) {
				this.stock = null
				return
			}
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/pipelinq/api/products/{id}/stock', { id: this.objectId }),
				)
				this.stock = data
			} catch (error) {
				this.stock = error?.response?.data?.state
					? error.response.data
					: { state: 'no-access' }
			} finally {
				this.loading = false
			}
		},

		/**
		 * A number in the user's locale.
		 *
		 * @param {number} value The number.
		 * @return {string}
		 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
		 */
		number(value) {
			return Number(value || 0).toLocaleString()
		},

		/**
		 * Length x width x height, when set.
		 *
		 * @param {object} dimensions The dimensions object.
		 * @return {string}
		 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
		 */
		dimensions(dimensions) {
			if (!dimensions || typeof dimensions !== 'object') {
				return ''
			}
			const parts = [dimensions.length, dimensions.width, dimensions.height]
			if (parts.some((part) => typeof part !== 'number')) {
				return ''
			}
			const unit = dimensions.unit ? ` ${dimensions.unit}` : ''
			return parts.map((part) => this.number(part)).join(' x ') + unit
		},
	},
}
</script>

<style scoped>
.product-supply {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.product-supply__list {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 12px;
	margin: 0;
}

.product-supply__list dt {
	color: var(--color-text-maxcontrast);
}

.product-supply__list dd {
	display: flex;
	flex-direction: column;
	margin: 0;
}

.product-supply__locations {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.product-supply__link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>
