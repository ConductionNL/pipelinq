<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<div class="pos-totals">
		<div class="pos-totals__row">
			<span>{{ t('pipelinq', 'Subtotal') }}</span>
			<span>{{ formatEur(totals.subtotal) }}</span>
		</div>
		<div
			v-if="totals.discountTotal > 0"
			class="pos-totals__row pos-totals__row--discount">
			<span>{{ t('pipelinq', 'Discount') }}</span>
			<span>− {{ formatEur(totals.discountTotal) }}</span>
		</div>
		<div
			v-for="rate in totals.taxBreakdown"
			:key="rate.rate"
			class="pos-totals__row pos-totals__row--tax">
			<span
				>{{ t('pipelinq', 'VAT {rate}%', { rate: rate.rate }) }} ({{
					t('pipelinq', 'base')
				}}
				{{ formatEur(rate.base) }})</span
			>
			<span>{{ formatEur(rate.tax) }}</span>
		</div>
		<div class="pos-totals__row pos-totals__row--total">
			<span>{{ t('pipelinq', 'Total') }}</span>
			<span
				>{{ formatEur(totals.total) }}
				<small class="pos-totals__mode">{{ priceModeSuffix }}</small></span
			>
		</div>
	</div>
</template>

<script>
import { computeTotals, formatEur } from '../../services/posTotals.js'

export default {
	name: 'PosTotalsPanel',
	props: {
		lines: {
			type: Array,
			default: () => [],
		},
		priceMode: {
			type: String,
			default: 'excl',
		},
	},
	computed: {
		/**
		 * Server-mirroring total computation. The same formula runs
		 * authoritatively on the backend (PosTransactionService); this is a
		 * real-time preview only and is never persisted from the client.
		 *
		 * @return {object} The computed totals.
		 */
		totals() {
			return computeTotals(this.lines, this.priceMode)
		},
		/**
		 * Inclusive / exclusive BTW suffix shown next to the total.
		 *
		 * @return {string} The suffix.
		 */
		priceModeSuffix() {
			return this.priceMode === 'incl'
				? t('pipelinq', 'incl. VAT')
				: t('pipelinq', 'excl. VAT')
		},
	},
	methods: {
		formatEur,
	},
}
</script>

<style scoped>
.pos-totals {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 360px;
	margin-left: auto;
}

.pos-totals__row {
	display: flex;
	justify-content: space-between;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.pos-totals__row--discount {
	color: var(--color-error);
}

.pos-totals__row--tax {
	font-size: 13px;
}

.pos-totals__row--total {
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
	font-size: 18px;
	font-weight: 700;
	color: var(--color-primary-element);
}

.pos-totals__mode {
	font-size: 12px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
}
</style>
