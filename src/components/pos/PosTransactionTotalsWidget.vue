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
  - With `content.layout: "summary"` (board PqKassabon, the side column) it
  - is a short list instead: the number of lines, the amount without VAT,
  - the VAT per rate and the total, with the tax return and the invoice
  - split folded away under it.
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
		<template v-if="!summary" #title-icon>
			<CnIcon name="Cash" :size="20" />
		</template>
		<template v-if="!summary" #title-meta>
			<span class="pos-tx-totals__mode">{{ priceModeLabel }}</span>
		</template>

		<template v-if="summary">
			<dl class="pos-tx-totals__list">
				<div v-if="lineCount !== null" class="pos-tx-totals__item" data-testid="pos-totals-row">
					<dt>{{ t('pipelinq', 'Lines') }}</dt>
					<dd>{{ n('pipelinq', '%n line', '%n lines', lineCount) }}</dd>
				</div>
				<div class="pos-tx-totals__item" data-testid="pos-totals-row">
					<dt>{{ t('pipelinq', 'Excl. VAT') }}</dt>
					<dd>{{ formatEur(amountExclVat) }}</dd>
				</div>
				<div
					v-for="row in vatPerRate"
					:key="`vat-${row.rate}`"
					class="pos-tx-totals__item"
					data-testid="pos-totals-row">
					<dt>{{ t('pipelinq', 'VAT {rate}%', { rate: row.rate }) }}</dt>
					<dd>{{ formatEur(row.tax) }}</dd>
				</div>
				<div class="pos-tx-totals__item pos-tx-totals__item--total" data-testid="pos-totals-row">
					<dt>{{ t('pipelinq', 'Total') }}</dt>
					<dd data-testid="pos-totals-total">
						{{ formatEur(transaction.total) }}
					</dd>
				</div>
			</dl>
			<details class="pos-tx-totals__breakdown" data-testid="pos-totals-breakdown">
				<summary>{{ t('pipelinq', 'Tax return and invoice split') }}</summary>
				<table class="pos-tx-totals__table">
					<caption class="hidden-visually">
						{{ t('pipelinq', 'Tax return') }}
					</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Rate') }}</th>
							<th scope="col" class="num">{{ t('pipelinq', 'Base') }}</th>
							<th scope="col" class="num">{{ t('pipelinq', 'VAT') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in taxBreakdown" :key="`sum-tax-${row.rate}`">
							<td>{{ row.rate }}%</td>
							<td class="num">{{ formatEur(row.base) }}</td>
							<td class="num">{{ formatEur(row.tax) }}</td>
						</tr>
					</tbody>
				</table>
				<table class="pos-tx-totals__table">
					<caption class="hidden-visually">
						{{ t('pipelinq', 'Invoice split') }}
					</caption>
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Rate') }}</th>
							<th scope="col" class="num">{{ t('pipelinq', 'Base') }}</th>
							<th scope="col" class="num">{{ t('pipelinq', 'VAT') }}</th>
							<th scope="col">{{ t('pipelinq', 'Description') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in invoiceBreakdown" :key="`sum-gl-${row.rate}`">
							<td>{{ row.rate }}%</td>
							<td class="num">{{ formatEur(row.base) }}</td>
							<td class="num">{{ formatEur(row.tax) }}</td>
							<td>{{ rateLabel(row.rate) }}</td>
						</tr>
					</tbody>
				</table>
			</details>
		</template>

		<template v-else>
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
			<div
				v-if="discountTotal > 0"
				class="pos-tx-totals__row pos-tx-totals__row--discount">
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
					<small class="pos-tx-totals__suffix">{{
						priceModeSuffix
					}}</small>
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
						<td>{{ rateLabel(row.rate) }}</td>
					</tr>
				</tbody>
			</table>
		</section>
		</template>
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { formatEur, rateLabel } from '../../services/posTotals.js'

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

		/** The transaction id, from the detail page. */
		objectId: {
			type: String,
			default: '',
		},

		/** The manifest widget title. */
		title: {
			type: String,
			default: '',
		},

		/** The widget content; `layout: "summary"` draws the short list. */
		content: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			/** The number of lines on the transaction, null until read. */
			lineCount: null,
		}
	},

	computed: {
		/**
		 * Whether the card draws the short summary list.
		 *
		 * @return {boolean} True for `content.layout: "summary"`.
		 *
		 * @spec openspec/specs/pos-nl-btw-engine/spec.md#req-btw-005-tax-breakdown-display-on-detail-view
		 */
		summary() {
			return this.content?.layout === 'summary'
		},

		/**
		 * The id of the transaction shown.
		 *
		 * @return {string} The id, or '' before it loads.
		 *
		 * @spec openspec/specs/pos-nl-btw-engine/spec.md#req-btw-005-tax-breakdown-display-on-detail-view
		 */
		transactionId() {
			return this.objectId || this.transaction.id || ''
		},

		/**
		 * The amount without VAT: the stored total less the stored VAT.
		 *
		 * @return {number} The amount.
		 *
		 * @spec openspec/specs/pos-nl-btw-engine/spec.md#req-btw-005-tax-breakdown-display-on-detail-view
		 */
		amountExclVat() {
			return Math.round(((Number(this.transaction.total) || 0) - (Number(this.transaction.totalTax) || 0)) * 100) / 100
		},

		/**
		 * The VAT per rate, highest rate first, as the board lists it.
		 *
		 * @return {Array<object>} The tax breakdown rows.
		 *
		 * @spec openspec/specs/pos-nl-btw-engine/spec.md#req-btw-005-tax-breakdown-display-on-detail-view
		 */
		vatPerRate() {
			return [...this.taxBreakdown].sort((a, b) => b.rate - a.rate)
		},

		/**
		 * The transaction, or an empty object before it loads.
		 *
		 * @return {object} The transaction.
		 *
		 * @spec openspec/specs/pos-transaction-core/spec.md#req-pos-003-real-time-total-calculation
		 */
		transaction() {
			return this.objectData || {}
		},

		/**
		 * The transaction's total discount, 0 when unset.
		 *
		 * @return {number} The discount total.
		 *
		 * @spec openspec/specs/pos-transaction-core/spec.md#req-pos-003-real-time-total-calculation
		 */
		discountTotal() {
			return Number(this.transaction.discountTotal) || 0
		},

		/**
		 * The stored per-rate VAT rows, lowest rate first.
		 *
		 * @return {Array<object>} The tax breakdown rows.
		 *
		 * @spec openspec/specs/pos-nl-btw-engine/spec.md#req-btw-005-tax-breakdown-display-on-detail-view
		 */
		taxBreakdown() {
			const rows = Array.isArray(this.transaction.taxBreakdown)
				? this.transaction.taxBreakdown
				: []
			return [...rows].sort((a, b) => a.rate - b.rate)
		},

		/**
		 * The GL posting rows. A record from before the invoice breakdown
		 * existed uses the tax breakdown instead. The Description column shows
		 * the translated rate name, not the stored Dutch GL text.
		 *
		 * @return {Array<object>} The invoice breakdown rows.
		 *
		 * @spec openspec/specs/pos-nl-btw-engine/spec.md#req-btw-003-invoice-breakdown-for-gl-posting
		 */
		invoiceBreakdown() {
			const rows = this.transaction.invoiceBreakdown
			if (Array.isArray(rows) && rows.length > 0) {
				return [...rows].sort((a, b) => a.rate - b.rate)
			}
			return this.taxBreakdown
		},

		/**
		 * The short VAT suffix for amounts in the transaction's price mode.
		 *
		 * @return {string} The suffix.
		 *
		 * @spec openspec/specs/pos-nl-btw-engine/spec.md#req-btw-004-price-mode-display-inclusive-vs-exclusive
		 */
		priceModeSuffix() {
			return this.transaction.priceMode === 'incl'
				? t('pipelinq', 'incl. VAT')
				: t('pipelinq', 'excl. VAT')
		},

		/**
		 * The label of the transaction's price mode.
		 *
		 * @return {string} The label.
		 *
		 * @spec openspec/specs/pos-nl-btw-engine/spec.md#req-btw-004-price-mode-display-inclusive-vs-exclusive
		 */
		priceModeLabel() {
			return this.transaction.priceMode === 'incl'
				? t('pipelinq', 'Prices incl. VAT')
				: t('pipelinq', 'Prices excl. VAT')
		},
	},

	watch: {
		transactionId: {
			immediate: true,
			handler() {
				this.countLines()
			},
		},
	},

	methods: {
		formatEur,
		rateLabel,
		n,

		/**
		 * Count the lines of the transaction for the summary list. A failed
		 * read leaves the row out.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/pos-nl-btw-engine/spec.md#req-btw-005-tax-breakdown-display-on-detail-view
		 */
		async countLines() {
			if (!this.summary || !this.transactionId) {
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/objects/pipelinq/posTransactionLine'),
					{ params: { transaction: this.transactionId, _limit: 1 } },
				)
				this.lineCount = Number.isFinite(Number(data?.total)) ? Number(data.total) : null
			} catch (error) {
				console.error('Could not count the transaction lines', error)
				this.lineCount = null
			}
		},
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

.pos-tx-totals__list {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 6px 16px;
	margin: 0;
	padding: 0;
	font-variant-numeric: tabular-nums;
}

.pos-tx-totals__item {
	display: contents;
}

/* Nextcloud's global dt rule (130px, right-aligned, padded) spaces the rows apart. */
.pos-tx-totals__item dt {
	width: auto;
	padding: 0;
	white-space: normal;
	text-align: start;
	color: var(--color-text-maxcontrast);
}

.pos-tx-totals__item dd {
	margin: 0;
	padding: 0;
}

.pos-tx-totals__item--total dt {
	align-self: center;
}

.pos-tx-totals__item--total dd {
	font-size: 20px;
	font-weight: 700;
}

.pos-tx-totals__breakdown {
	margin-top: 12px;
	font-size: 13px;
}

.pos-tx-totals__breakdown summary {
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

.pos-tx-totals__breakdown table {
	margin-top: 8px;
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
