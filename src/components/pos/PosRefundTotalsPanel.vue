<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<div class="pos-refund-totals">
		<div class="pos-refund-totals__row">
			<span>{{ t('pipelinq', 'Refund amount (excl. BTW)') }}</span>
			<span>{{ formatEur(totals.refundAmount) }}</span>
		</div>
		<div class="pos-refund-totals__row pos-refund-totals__row--tax">
			<span>{{ t('pipelinq', 'VAT') }}</span>
			<span>{{ formatEur(totals.totalTax) }}</span>
		</div>
		<div class="pos-refund-totals__row pos-refund-totals__row--total">
			<span>{{ t('pipelinq', 'Total refund') }}</span>
			<span>{{ formatEur(totals.total) }}</span>
		</div>
	</div>
</template>

<script>
import { computeRefundTotals, formatEur } from '../../services/posTotals.js'

export default {
	name: 'PosRefundTotalsPanel',
	props: {
		lines: {
			type: Array,
			default: () => [],
		},
	},

	computed: {
		/**
		 * Real-time refund totals. The same proportional formula runs
		 * authoritatively on the backend (PosRefundService); this is a preview
		 * only and is never persisted from the client.
		 *
		 * @return {object} The refund totals (refundAmount, totalTax, total).
		 */
		totals() {
			return computeRefundTotals(this.lines)
		},
	},

	methods: {
		formatEur,
	},
}
</script>

<style scoped>
.pos-refund-totals {
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-width: 360px;
	margin-left: auto;
}

.pos-refund-totals__row {
	display: flex;
	justify-content: space-between;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.pos-refund-totals__row--tax {
	font-size: 13px;
}

.pos-refund-totals__row--total {
	margin-top: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
	font-size: 18px;
	font-weight: 700;
	color: var(--color-primary-element);
}
</style>
