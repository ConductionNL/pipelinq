<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Detail-grid widget for PosTransactionDetail: the receipt totals. The tax
  - return (VAT per rate with its base), then subtotal, discount, VAT and
  - total, then the invoice split per rate that the bookkeeping export posts.
  - It holds what the old Tax breakdown card, the totals panel and the Total /
  - VAT tiles showed.
  -
  - It reads the totals the server stored on the transaction, not a client
  - recomputation over the lines: those are the figures that were booked. The
  - page hands the transaction over as `objectData` and re-reads it on
  - `cn:page:refresh`, so there is nothing to fetch here.
  -->
<template>
	<CnWidgetWrapper
		class="pos-tx-totals"
		:title="title || t('pipelinq', 'Totals')"
		titleIconPosition="left"
		:showActions="false"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="Cash" :size="20" />
		</template>
		<template #title-meta>
			<span class="pos-tx-totals__mode">{{ priceModeLabel }}</span>
		</template>

		<h4 class="pos-tx-totals__heading">
			{{ t('pipelinq', 'Tax return') }}
		</h4>
		<table class="pos-tx-totals__table">
			<thead>
				<tr>
					<th scope="col">{{ t('pipelinq', 'Rate') }}</th>
					<th scope="col" class="num">{{ t('pipelinq', 'Base') }}</th>
					<th scope="col" class="num">{{ t('pipelinq', 'VAT') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in taxBreakdown" :key="`tax-${row.rate}`">
					<td>{{ row.rate }}%</td>
					<td class="num">{{ formatEur(row.base) }}</td>
					<td class="num">{{ formatEur(row.tax) }}</td>
				</tr>
				<tr v-if="taxBreakdown.length === 0">
					<td colspan="3" class="pos-tx-totals__empty">
						{{ t('pipelinq', 'No items') }}
					</td>
				</tr>
			</tbody>
		</table>

		<dl class="pos-tx-totals__summary">
			<div class="pos-tx-totals__row">
				<dt>{{ t('pipelinq', 'Subtotal') }}</dt>
				<dd>{{ formatEur(transaction.subtotal) }}</dd>
			</div>
			<div v-if="discountTotal > 0" class="pos-tx-totals__row pos-tx-totals__row--discount">
				<dt>{{ t('pipelinq', 'Discount') }}</dt>
				<dd>− {{ formatEur(discountTotal) }}</dd>
			</div>
			<div class="pos-tx-totals__row">
				<dt>{{ t('pipelinq', 'VAT') }}</dt>
				<dd>{{ formatEur(transaction.totalTax) }}</dd>
			</div>
			<div class="pos-tx-totals__row pos-tx-totals__row--total">
				<dt>{{ t('pipelinq', 'Total') }}</dt>
				<dd>
					{{ formatEur(transaction.total) }}
					<small class="pos-tx-totals__suffix">{{ priceModeSuffix }}</small>
				</dd>
			</div>
		</dl>

		<section v-if="invoiceBreakdown.length > 0" class="pos-tx-totals__split">
			<h4 class="pos-tx-totals__heading">
				{{ t('pipelinq', 'Invoice split') }}
			</h4>
			<table class="pos-tx-totals__table">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Rate') }}</th>
						<th scope="col" class="num">{{ t('pipelinq', 'Base') }}</th>
						<th scope="col" class="num">{{ t('pipelinq', 'VAT') }}</th>
						<th scope="col">{{ t('pipelinq', 'Description') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in invoiceBreakdown" :key="`gl-${row.rate}`">
						<td>{{ row.rate }}%</td>
						<td class="num">{{ formatEur(row.base) }}</td>
						<td class="num">{{ formatEur(row.tax) }}</td>
						<td>{{ row.description }}</td>
					</tr>
				</tbody>
			</table>
		</section>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { formatEur, rateDescription } from '../../services/posTotals.js'

export default {
	name: 'PosTransactionTotalsWidget',
	components: { CnIcon, CnWidgetWrapper },
	// The host also hands over register / schema / store and the widget
	// content; none of them belong on the root element.
	inheritAttrs: false,

	props: {
		/** The transaction, from the detail page. */
		objectData: {
			type: Object,
			default: null,
		},

		/** The manifest widget title. */
		title: {
			type: String,
			default: '',
		},
	},

	computed: {
		transaction() {
			return this.objectData || {}
		},

		discountTotal() {
			return Number(this.transaction.discountTotal) || 0
		},

		/**
		 * The stored per-rate VAT rows, lowest rate first.
		 *
		 * @return {Array<object>} The tax breakdown rows.
		 */
		taxBreakdown() {
			const rows = Array.isArray(this.transaction.taxBreakdown) ? this.transaction.taxBreakdown : []
			return [...rows].sort((a, b) => a.rate - b.rate)
		},

		/**
		 * The GL posting rows. A record from before the invoice breakdown
		 * existed gets its descriptions from the tax breakdown instead.
		 *
		 * @return {Array<object>} The invoice breakdown rows.
		 */
		invoiceBreakdown() {
			const rows = this.transaction.invoiceBreakdown
			if (Array.isArray(rows) && rows.length > 0) {
				return [...rows].sort((a, b) => a.rate - b.rate)
			}
			return this.taxBreakdown.map((row) => ({ ...row, description: rateDescription(row.rate) }))
		},

		priceModeSuffix() {
			return this.transaction.priceMode === 'incl'
				? t('pipelinq', 'incl. VAT')
				: t('pipelinq', 'excl. VAT')
		},

		priceModeLabel() {
			return this.transaction.priceMode === 'incl'
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
.pos-tx-totals {
	height: 100%;
	display: flex;
	flex-direction: column;
}

.pos-tx-totals__mode {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.pos-tx-totals__table {
	width: 100%;
	border-collapse: collapse;
	font-variant-numeric: tabular-nums;
}

.pos-tx-totals__table th {
	padding: 4px 6px;
	border-bottom: 1px solid var(--color-border);
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-align: start;
}

.pos-tx-totals__table td {
	padding: 6px;
	border-bottom: 1px solid var(--color-border);
}

.pos-tx-totals__table tr:last-child td {
	border-bottom: none;
}

.pos-tx-totals__table .num {
	text-align: end;
}

.pos-tx-totals__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.pos-tx-totals__summary {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 12px 0 0;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.pos-tx-totals__row {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 12px;
}

.pos-tx-totals__row dt {
	color: var(--color-text-maxcontrast);
}

.pos-tx-totals__row dd {
	margin: 0;
	font-variant-numeric: tabular-nums;
}

.pos-tx-totals__row--discount dd {
	color: var(--color-error-text);
}

.pos-tx-totals__row--total {
	margin-top: 6px;
	padding-top: 10px;
	border-top: 1px solid var(--color-border);
	font-size: 18px;
	font-weight: 700;
}

.pos-tx-totals__suffix {
	font-size: 12px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}

.pos-tx-totals__row--total dt {
	color: var(--color-main-text);
}

.pos-tx-totals__split {
	margin-top: 16px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.pos-tx-totals__heading {
	margin: 0 0 6px;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}
</style>
