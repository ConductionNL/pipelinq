<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<tr v-if="!editing" class="pos-line-row pos-line-row--read" data-testid="pos-line-read">
		<td class="pos-line-row__product">
			<strong class="pos-line-row__name">{{ productName }}</strong>
			<span v-if="lineNote" class="pos-line-row__note">{{ lineNote }}</span>
		</td>
		<td class="pos-line-row__num">
			{{ formatNumber(line.quantity) }}
		</td>
		<td class="pos-line-row__num">
			{{ formatEur(line.unitPrice) }}
		</td>
		<td class="pos-line-row__num">
			{{ formatNumber(line.discount) }}%
		</td>
		<td class="pos-line-row__num">
			{{ formatNumber(line.taxRate) }}%
		</td>
		<td class="pos-line-row__total">
			{{ formatEur(readTotal) }}
		</td>
		<td class="pos-line-row__actions">
			<NcButton
				variant="tertiary"
				:aria-label="t('pipelinq', 'Edit line {name}', { name: productName })"
				data-testid="pos-line-edit"
				@click="$emit('edit')">
				<template #icon>
					<Pencil :size="18" />
				</template>
			</NcButton>
			<NcButton
				variant="tertiary"
				:aria-label="t('pipelinq', 'Remove line')"
				@click="$emit('remove')">
				<template #icon>
					<Delete :size="18" />
				</template>
			</NcButton>
		</td>
	</tr>
	<tr v-else class="pos-line-row pos-line-row--edit" data-testid="pos-line-edit-row">
		<td class="pos-line-row__product">
			<NcSelect
				:modelValue="selectedProduct"
				:options="productOptions"
				:inputLabel="t('pipelinq', 'Product')"
				:placeholder="t('pipelinq', 'Search product…')"
				label="label"
				:clearable="true"
				@update:modelValue="onProductSelect" />
			<NcTextField
				v-model="local.description"
				class="pos-line-row__description"
				:label="t('pipelinq', 'Description')"
				@update:modelValue="emitUpdate" />
		</td>
		<td class="pos-line-row__num">
			<NcInputField
				v-model="local.quantity"
				type="number"
				:label="t('pipelinq', 'Quantity')"
				min="0.001"
				step="0.001"
				@update:modelValue="emitUpdate" />
		</td>
		<td class="pos-line-row__num">
			<NcInputField
				v-model="local.unitPrice"
				type="number"
				:label="t('pipelinq', 'Unit price')"
				min="0"
				step="0.01"
				@update:modelValue="emitUpdate" />
		</td>
		<td class="pos-line-row__num">
			<NcInputField
				v-model="local.discount"
				type="number"
				:label="t('pipelinq', 'Discount %')"
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
				:aria-label="t('pipelinq', 'Done editing the line')"
				data-testid="pos-line-done"
				@click="$emit('done')">
				<template #icon>
					<Check :size="18" />
				</template>
			</NcButton>
			<NcButton
				variant="tertiary"
				:aria-label="t('pipelinq', 'Remove line')"
				@click="$emit('remove')">
				<template #icon>
					<Delete :size="18" />
				</template>
			</NcButton>
		</td>
	</tr>
</template>

<script>
import { NcButton, NcInputField, NcSelect, NcTextField } from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import { getUserLocale } from '../../services/localeUtils.js'
import { formatEur, recalculateLine } from '../../services/posTotals.js'

export default {
	name: 'PosLineItemRow',
	components: {
		NcSelect,
		NcTextField,
		NcInputField,
		NcButton,
		Check,
		Delete,
		Pencil,
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

		/**
		 * Whether the line shows its inputs. A line reads as a table row
		 * (product, quantity, prices, VAT, total) until it is edited.
		 */
		editing: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['remove', 'update:line', 'edit', 'done'],

	data() {
		return {
			local: this.localFrom(this.line),
		}
	},

	computed: {
		/**
		 * The line's product as the row names it: the catalogue name, else
		 * the line's description.
		 *
		 * @return {string} The name.
		 * @spec openspec/changes/pos-sale-lines-board-table/specs/pos-transactions/spec.md#requirement-the-sale-lines-read-as-a-table-and-stay-editable
		 */
		productName() {
			const product = this.products.find((p) => this.line.product && (p.id === this.line.product || p['@self']?.id === this.line.product))
			return product?.name || this.line.description || t('pipelinq', 'Item')
		},

		/**
		 * The line's own description under the product name, when it says
		 * more than the name.
		 *
		 * @return {string} The description, or ''.
		 * @spec openspec/changes/pos-sale-lines-board-table/specs/pos-transactions/spec.md#requirement-the-sale-lines-read-as-a-table-and-stay-editable
		 */
		lineNote() {
			const description = String(this.line.description || '').trim()
			return description !== '' && description !== this.productName ? description : ''
		},

		/**
		 * The line total the read row shows.
		 *
		 * @return {number} The total.
		 * @spec openspec/changes/pos-sale-lines-board-table/specs/pos-transactions/spec.md#requirement-the-sale-lines-read-as-a-table-and-stay-editable
		 */
		readTotal() {
			return recalculateLine(this.line, this.priceMode).lineTotal
		},

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

	watch: {
		editing(value) {
			if (value) {
				this.local = this.localFrom(this.line)
			}
		},
	},

	methods: {
		formatEur,

		/**
		 * A number as the locale writes it, without trailing zeros.
		 *
		 * @param {number|string} value The number.
		 * @return {string} The text.
		 * @spec openspec/changes/pos-sale-lines-board-table/specs/pos-transactions/spec.md#requirement-the-sale-lines-read-as-a-table-and-stay-editable
		 */
		formatNumber(value) {
			return (Number(value) || 0).toLocaleString(getUserLocale(), { maximumFractionDigits: 3 })
		},

		/**
		 * The editable fields of a line.
		 *
		 * @param {object} line The line.
		 * @return {object} The fields.
		 * @spec openspec/changes/pos-sale-lines-board-table/specs/pos-transactions/spec.md#requirement-the-sale-lines-read-as-a-table-and-stay-editable
		 */
		localFrom(line) {
			return {
				description: line.description || '',
				quantity: line.quantity ?? 1,
				unitPrice: line.unitPrice ?? 0,
				discount: line.discount ?? 0,
				taxRate: line.taxRate ?? 21,
				product: line.product || null,
			}
		},

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
.pos-line-row td {
	padding: 12px 18px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
	font-size: 14px;
	line-height: 1.2;
}

.pos-line-row:last-child td {
	border-bottom: 0;
}

.pos-line-row__name {
	display: block;
	font-weight: 600;
}

.pos-line-row__note {
	display: block;
	margin-top: 2px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.pos-line-row__num,
.pos-line-row__total {
	text-align: end;
	white-space: nowrap;
}

.pos-line-row--edit .pos-line-row__num {
	max-width: 110px;
}

.pos-line-row__description {
	margin-top: 6px;
}

.pos-line-row__actions {
	white-space: nowrap;
	text-align: end;
	padding-inline: 6px 12px;
}

.pos-line-row__actions > * {
	display: inline-flex;
}
</style>
