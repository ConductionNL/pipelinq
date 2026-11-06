<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The fee table on the product page, as board PqProduct draws it
  - ("Tarieventabel (leges)"): the tariffs in force today, the source they
  - come from, and the day the next set of tariffs goes live.
  -->

<template>
	<div class="product-tariffs" data-testid="product-tariffs">
		<p v-if="rows.length === 0" class="product-tariffs__muted">
			{{ t('pipelinq', 'No tariffs set for this product.') }}
		</p>
		<table v-else class="product-tariffs__table">
			<thead>
				<tr>
					<th scope="col">{{ t('pipelinq', 'Tariff') }}</th>
					<th scope="col" class="product-tariffs__amount">{{ t('pipelinq', 'Amount') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(row, index) in rows" :key="index">
					<td>{{ row.label }}</td>
					<td class="product-tariffs__amount">{{ amount(row.price) }}</td>
				</tr>
			</tbody>
		</table>
		<p v-if="source" class="product-tariffs__muted">{{ source }}</p>
		<p v-if="scheduledNote" class="product-tariffs__muted" data-testid="product-tariffs-scheduled">
			{{ scheduledNote }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { longDate, objectCurrency } from '../../services/cellFormatters.js'
import { currentTariffs, nextTariffDate } from '../../services/productTariffs.js'

/**
 * Today as `YYYY-MM-DD`, in the reader's time zone.
 *
 * @return {string}
 */
function localToday() {
	const now = new Date()
	const pad = (n) => String(n).padStart(2, '0')
	return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

export default {
	name: 'ProductTariffsWidget',

	inheritAttrs: false,

	props: {
		/** The product, from the detail page. */
		objectData: {
			type: Object,
			default: null,
		},

	},

	data() {
		return { today: localToday() }
	},

	computed: {
		/**
		 * @return {Array<object>} The tariffs in force today.
		 * @spec openspec/specs/product-catalog/spec.md
		 */
		rows() {
			return currentTariffs(this.objectData?.tariffs, this.today)
		},

		/**
		 * @return {string} Where the tariffs come from.
		 * @spec openspec/specs/product-catalog/spec.md
		 */
		source() {
			return this.objectData?.tariffSource || ''
		},

		/**
		 * @return {string} The day the next tariffs go live, as a sentence.
		 * @spec openspec/specs/product-catalog/spec.md
		 */
		scheduledNote() {
			const next = nextTariffDate(this.objectData?.tariffs, this.today)
			if (!next) {
				return ''
			}
			return t(
				'pipelinq',
				'Scheduled publication: the tariffs of {year} go live on {date}. Until then residents see the tariffs of {current}.',
				{ year: next.slice(0, 4), date: longDate(next), current: this.today.slice(0, 4) },
			)
		},
	},

	methods: {
		t,

		/**
		 * @param {unknown} price The tariff's amount.
		 * @return {string} "Free" for nothing, else the amount.
		 * @spec openspec/specs/product-catalog/spec.md
		 */
		amount(price) {
			if (Number(price) === 0 && price !== null && price !== '') {
				return t('pipelinq', 'Free')
			}
			return objectCurrency(price, this.objectData)
		},
	},
}
</script>

<style scoped>
.product-tariffs {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.product-tariffs__table {
	width: 100%;
	border-collapse: collapse;
}

.product-tariffs__table th,
.product-tariffs__table td {
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
}

.product-tariffs__table th {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.product-tariffs__table .product-tariffs__amount {
	text-align: end;
}

.product-tariffs__muted {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
