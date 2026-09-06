<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<div class="tax-breakdown">
		<div class="tax-breakdown__mode">
			{{ priceModeLabel }}
		</div>

		<section class="tax-breakdown__section">
			<h4>{{ t('pipelinq', 'Tax return') }}</h4>
			<table class="tax-breakdown__table">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Rate') }}</th>
						<th scope="col" class="num">
							{{ t('pipelinq', 'Base') }}
						</th>
						<th scope="col" class="num">
							{{ t('pipelinq', 'VAT') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in taxBreakdown" :key="`tax-${row.rate}`">
						<td>{{ row.rate }}%</td>
						<td class="num">
							{{ formatEur(row.base) }}
						</td>
						<td class="num">
							{{ formatEur(row.tax) }}
						</td>
					</tr>
					<tr v-if="taxBreakdown.length === 0">
						<td colspan="3" class="empty">
							{{ t('pipelinq', 'No items') }}
						</td>
					</tr>
				</tbody>
			</table>
		</section>

		<section v-if="invoiceBreakdown.length > 0" class="tax-breakdown__section">
			<h4>{{ t('pipelinq', 'Invoice split') }}</h4>
			<table class="tax-breakdown__table">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Rate') }}</th>
						<th scope="col" class="num">
							{{ t('pipelinq', 'Base') }}
						</th>
						<th scope="col" class="num">
							{{ t('pipelinq', 'VAT') }}
						</th>
						<th scope="col">{{ t('pipelinq', 'Description') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in invoiceBreakdown" :key="`gl-${row.rate}`">
						<td>{{ row.rate }}%</td>
						<td class="num">
							{{ formatEur(row.base) }}
						</td>
						<td class="num">
							{{ formatEur(row.tax) }}
						</td>
						<td>{{ row.description }}</td>
					</tr>
				</tbody>
			</table>
		</section>
	</div>
</template>

<script>
import { formatEur, rateDescription } from '../../services/posTotals.js'

export default {
	name: 'TaxBreakdownCard',
	props: {
		transaction: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * Persisted per-rate tax summary rows, sorted by rate ascending.
		 *
		 * @return {Array<object>} The tax breakdown rows.
		 */
		taxBreakdown() {
			return [...(this.transaction.taxBreakdown || [])].sort(
				(a, b) => a.rate - b.rate,
			)
		},

		/**
		 * Per-rate GL posting rows. Falls back to deriving descriptions from the
		 * tax breakdown for legacy records that predate the invoice breakdown.
		 *
		 * @return {Array<object>} The invoice breakdown rows.
		 */
		invoiceBreakdown() {
			const rows = this.transaction.invoiceBreakdown
			if (Array.isArray(rows) && rows.length > 0) {
				return [...rows].sort((a, b) => a.rate - b.rate)
			}
			return this.taxBreakdown.map((row) => ({
				...row,
				description: rateDescription(row.rate),
			}))
		},

		/**
		 * Whether prices are tax-inclusive.
		 *
		 * @return {boolean} True for incl mode.
		 */
		isInclusive() {
			return this.transaction.priceMode === 'incl'
		},

		/**
		 * Price mode label for the card header.
		 *
		 * @return {string} The label.
		 */
		priceModeLabel() {
			return this.isInclusive
				? t('pipelinq', 'Prices incl. VAT')
				: t('pipelinq', 'Prices excl. VAT')
		},
	},

	methods: {
		formatEur,
	},
}
</script>

<style scoped>
.tax-breakdown {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.tax-breakdown__mode {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.tax-breakdown__section h4 {
	margin: 0 0 8px;
	font-size: 14px;
	font-weight: 600;
}

.tax-breakdown__table {
	width: 100%;
	border-collapse: collapse;
}

.tax-breakdown__table th {
	text-align: start;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.tax-breakdown__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.num {
	text-align: end;
}

.empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
