<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<tr class="pos-line-row">
		<td class="pos-line-row__product">
			<NcSelect
				:modelValue="selectedProduct"
				:options="productOptions"
				:inputLabel="t('pipelinq', 'Product')"
				:placeholder="t('pipelinq', 'Search product…')"
				label="label"
				:clearable="true"
				@update:modelValue="onProductSelect" />
		</td>
		<td class="pos-line-row__description">
			<NcTextField
				v-model="local.description"
				:label="t('pipelinq', 'Description')"
				:labelVisible="false"
				@update:modelValue="emitUpdate" />
		</td>
		<td class="pos-line-row__num">
			<NcInputField
				v-model="local.quantity"
				type="number"
				:label="t('pipelinq', 'Quantity')"
				:labelVisible="false"
				min="0.001"
				step="0.001"
				@update:modelValue="emitUpdate" />
		</td>
		<td class="pos-line-row__num">
			<NcInputField
				v-model="local.unitPrice"
				type="number"
				:label="t('pipelinq', 'Unit price')"
				:labelVisible="false"
				min="0"
				step="0.01"
				@update:modelValue="emitUpdate" />
		</td>
		<td class="pos-line-row__num">
			<NcInputField
				v-model="local.discount"
				type="number"
				:label="t('pipelinq', 'Discount %')"
				:labelVisible="false"
				min="0"
				max="100"
				step="1"
				@update:modelValue="emitUpdate" />
		</td>
		<td class="pos-line-row__num">
			<NcSelect
				:modelValue="selectedTaxRate"
				:options="taxRateOptions"
				:inputLabel="t('pipelinq', 'VAT rate')"
				:clearable="false"
				@update:modelValue="onTaxRateSelect" />
		</td>
		<td class="pos-line-row__total">
			{{ formatEur(computed.lineTotal) }}
		</td>
		<td class="pos-line-row__actions">
			<NcButton
				variant="tertiary"
				:aria-label="t('pipelinq', 'Remove line')"
				@click="$emit('remove')">
				<template #icon>
					<Delete :size="20" />
				</template>
			</NcButton>
		</td>
	</tr>
</template>

<script>
import { NcButton, NcInputField, NcSelect, NcTextField } from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { formatEur, recalculateLine } from '../../services/posTotals.js'

export default {
	name: 'PosLineItemRow',
	components: {
		NcSelect,
		NcTextField,
		NcInputField,
		NcButton,
		Delete,
	},

	props: {
		line: {
			type: Object,
			required: true,
		},

		products: {
			type: Array,
			default: () => [],
		},

		priceMode: {
			type: String,
			default: 'excl',
		},
	},

	data() {
		return {
			local: {
				description: this.line.description || '',
				quantity: this.line.quantity ?? 1,
				unitPrice: this.line.unitPrice ?? 0,
				discount: this.line.discount ?? 0,
				taxRate: this.line.taxRate ?? 21,
				product: this.line.product || null,
			},
		}
	},

	computed: {
		/**
		 * Product picker options derived from the catalog.
		 *
		 * @return {Array<object>} The select options.
		 */
		productOptions() {
			return this.products.map((p) => ({
				id: p.id,
				label: p.sku ? `${p.name} (${p.sku})` : p.name,
				name: p.name,
				unitPrice: p.unitPrice,
				taxRate: p.taxRate,
			}))
		},

		/**
		 * The currently selected product option, if any.
		 *
		 * @return {object|null} The option.
		 */
		selectedProduct() {
			return (
				this.productOptions.find((o) => o.id === this.local.product) || null
			)
		},

		/**
		 * Available VAT rate options.
		 *
		 * @return {Array<object>} The select options.
		 */
		taxRateOptions() {
			return [
				{ id: 0, label: '0%' },
				{ id: 9, label: '9%' },
				{ id: 21, label: '21%' },
			]
		},

		/**
		 * The selected VAT rate option.
		 *
		 * @return {object} The option.
		 */
		selectedTaxRate() {
			return (
				this.taxRateOptions.find((o) => o.id === Number(this.local.taxRate))
				|| this.taxRateOptions[2]
			)
		},

		/**
		 * Server-mirroring computed taxAmount + lineTotal for display.
		 *
		 * @return {object} The computed line.
		 */
		computed() {
			return recalculateLine(this.local, this.priceMode)
		},
	},

	methods: {
		formatEur,
		/**
		 * Fill description / unitPrice / taxRate from the chosen product.
		 *
		 * @param {object|null} option The selected product option.
		 */
		onProductSelect(option) {
			if (option) {
				this.local.product = option.id
				this.local.description = option.name
				this.local.unitPrice = option.unitPrice ?? this.local.unitPrice
				this.local.taxRate = option.taxRate ?? 21
			} else {
				this.local.product = null
			}
			this.emitUpdate()
		},

		/**
		 * Apply a VAT rate selection.
		 *
		 * @param {object} option The selected rate option.
		 */
		onTaxRateSelect(option) {
			this.local.taxRate = option ? option.id : 21
			this.emitUpdate()
		},

		/**
		 * Emit the recomputed line to the parent.
		 */
		emitUpdate() {
			this.$emit(
				'update:line',
				recalculateLine({ ...this.line, ...this.local }, this.priceMode),
			)
		},
	},
}
</script>

<style scoped>
.pos-line-row__total {
	text-align: right;
	font-weight: 600;
	white-space: nowrap;
}

.pos-line-row__num {
	max-width: 90px;
}

.pos-line-row__actions {
	text-align: center;
}
</style>
