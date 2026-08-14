<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<tr
		class="pos-refund-row"
		:class="{ 'pos-refund-row--selected': local.selected }">
		<td class="pos-refund-row__select">
			<NcCheckboxRadioSwitch
				:model-value="local.selected"
				:aria-label="t('pipelinq', 'Select line for refund')"
				@update:model-value="onSelectToggle" />
		</td>
		<td class="pos-refund-row__description">
			{{ originalLine.description }}
		</td>
		<td class="pos-refund-row__num">
			{{ originalLine.quantity }}
		</td>
		<td class="pos-refund-row__num">
			{{ formatEur(originalLine.unitPrice) }}
		</td>
		<td class="pos-refund-row__num">
			<NcInputField
				v-model="local.returnedQuantity"
				type="number"
				:label="t('pipelinq', 'Returned quantity')"
				:label-visible="false"
				:disabled="!local.selected"
				:error="qtyError"
				:helper-text="
					qtyError
						? t('pipelinq', 'Max {max}', { max: originalLine.quantity })
						: ''
				"
				min="0.001"
				:max="String(originalLine.quantity)"
				step="0.001"
				@update:model-value="emitUpdate" />
		</td>
		<td class="pos-refund-row__reason">
			<NcSelect
				:model-value="selectedReason"
				:options="reasonOptions"
				:input-label="t('pipelinq', 'Return reason')"
				:placeholder="t('pipelinq', 'Choose a reason…')"
				label="label"
				:clearable="false"
				:disabled="!local.selected"
				@update:model-value="onReasonSelect" />
		</td>
		<td class="pos-refund-row__restock">
			<NcCheckboxRadioSwitch
				:model-value="local.restock"
				:disabled="!local.selected"
				@update:model-value="onRestockToggle">
				{{ t('pipelinq', 'Restock') }}
			</NcCheckboxRadioSwitch>
		</td>
		<td class="pos-refund-row__total">
			{{ formatEur(computed.lineTotal) }}
		</td>
		<td class="pos-refund-row__actions">
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
import {
	NcSelect,
	NcInputField,
	NcCheckboxRadioSwitch,
	NcButton,
} from '@nextcloud/vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import { refundLineAmounts, formatEur } from '../../services/posTotals.js'

export default {
	name: 'PosRefundLineRow',
	components: {
		NcSelect,
		NcInputField,
		NcCheckboxRadioSwitch,
		NcButton,
		Delete,
	},
	props: {
		/** The candidate refund line state (selected, returnedQuantity, returnReason, restock, originalLine id). */
		line: {
			type: Object,
			required: true,
		},
		/** The original posTransactionLine this row refunds. */
		originalLine: {
			type: Object,
			required: true,
		},
		/** Active refundReason objects for the picker. */
		reasons: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:line', 'remove'],
	data() {
		return {
			local: {
				selected: this.line.selected ?? false,
				returnedQuantity:
					this.line.returnedQuantity ?? this.originalLine.quantity ?? 1,
				returnReason: this.line.returnReason || null,
				restock: this.line.restock ?? true,
			},
		}
	},
	computed: {
		/**
		 * Reason picker options from the active refundReason objects.
		 *
		 * @return {Array<object>} The select options.
		 */
		reasonOptions() {
			return this.reasons.map((r) => ({ id: r.id, label: r.label || r.code }))
		},
		/**
		 * The currently selected reason option, if any.
		 *
		 * @return {object|null} The option.
		 */
		selectedReason() {
			return (
				this.reasonOptions.find((o) => o.id === this.local.returnReason)
				|| null
			)
		},
		/**
		 * Whether the entered returned quantity exceeds the original quantity.
		 *
		 * @return {boolean} True when invalid.
		 */
		qtyError() {
			return (
				Number(this.local.returnedQuantity)
				> Number(this.originalLine.quantity)
			)
		},
		/**
		 * Server-mirroring proportional refund amounts for display.
		 *
		 * @return {object} The computed amounts (ratio, taxAmount, lineTotal).
		 */
		computed() {
			if (!this.local.selected) {
				return { ratio: 0, taxAmount: 0, lineTotal: 0 }
			}
			return refundLineAmounts(this.originalLine, this.local.returnedQuantity)
		},
	},
	methods: {
		formatEur,
		/**
		 * Toggle whether this line is part of the refund.
		 *
		 * @param {boolean} value The new selected state.
		 */
		onSelectToggle(value) {
			this.local.selected = value
			this.emitUpdate()
		},
		/**
		 * Toggle the restock flag.
		 *
		 * @param {boolean} value The new restock state.
		 */
		onRestockToggle(value) {
			this.local.restock = value
			this.emitUpdate()
		},
		/**
		 * Apply a reason selection.
		 *
		 * @param {object|null} option The chosen reason option.
		 */
		onReasonSelect(option) {
			this.local.returnReason = option ? option.id : null
			this.emitUpdate()
		},
		/**
		 * Emit the recomputed candidate line to the parent.
		 */
		emitUpdate() {
			const amounts = refundLineAmounts(
				this.originalLine,
				this.local.returnedQuantity,
			)
			this.$emit('update:line', {
				originalLine: this.originalLine.id,
				selected: this.local.selected,
				returnedQuantity: Number(this.local.returnedQuantity) || 0,
				returnReason: this.local.returnReason,
				restock: this.local.restock,
				taxAmount: amounts.taxAmount,
				lineTotal: amounts.lineTotal,
				valid: !this.qtyError,
			})
		},
	},
}
</script>

<style scoped>
.pos-refund-row--selected {
	background: var(--color-background-hover);
}

.pos-refund-row__total {
	text-align: right;
	font-weight: 600;
	white-space: nowrap;
}

.pos-refund-row__num {
	max-width: 90px;
	text-align: right;
}

.pos-refund-row__reason {
	min-width: 180px;
}

.pos-refund-row__actions {
	text-align: center;
}
</style>
