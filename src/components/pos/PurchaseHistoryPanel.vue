<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<div class="purchase-history">
		<button class="purchase-history__header"
			type="button"
			:aria-expanded="String(!collapsed)"
			@click="collapsed = !collapsed">
			<span>{{ collapsed ? '▶' : '▼' }}</span>
			<span>{{ t('pipelinq', 'Purchase history ({count})', { count }) }}</span>
			<span v-if="lifetimeSpend" class="purchase-history__spend">
				{{ formatCurrency(lifetimeSpend) }}
			</span>
		</button>
		<div v-if="!collapsed" class="purchase-history__body">
			<p v-if="!transactions.length" class="purchase-history__empty">
				{{ t('pipelinq', 'No previous purchases') }}
			</p>
			<ul v-else class="purchase-history__list">
				<li v-for="tx in transactions" :key="tx.id" class="purchase-history__item">
					<span>{{ formatDate(tx.date) }}</span>
					<span>{{ t('pipelinq', '{count} items', { count: tx.itemCount }) }}</span>
					<span>{{ formatCurrency(tx.total) }}</span>
					<span class="purchase-history__tender">{{ tenderLabel(tx.tenderType) }}</span>
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
export default {
	name: 'PurchaseHistoryPanel',
	props: {
		transactions: {
			type: Array,
			default: () => [],
		},
		count: {
			type: Number,
			default: 0,
		},
		lifetimeSpend: {
			type: Number,
			default: 0,
		},
	},
	data() {
		return {
			collapsed: true,
		}
	},
	methods: {
		/**
		 * Format an ISO date as DD-MM-YYYY.
		 *
		 * @param {string} iso The ISO date string.
		 * @return {string} The formatted date.
		 */
		formatDate(iso) {
			const d = new Date(iso)
			if (Number.isNaN(d.getTime())) {
				return iso || ''
			}
			const pad = (n) => String(n).padStart(2, '0')
			return `${pad(d.getDate())}-${pad(d.getMonth() + 1)}-${d.getFullYear()}`
		},
		/**
		 * Format a number as euro currency.
		 *
		 * @param {number} value The amount.
		 * @return {string} The formatted amount.
		 */
		formatCurrency(value) {
			return `€${Number(value || 0).toFixed(2)}`
		},
		/**
		 * Human label for a tender type.
		 *
		 * @param {string} tender The tender type.
		 * @return {string} The label.
		 */
		tenderLabel(tender) {
			const map = {
				cash: t('pipelinq', 'Cash'),
				card: t('pipelinq', 'Card'),
				onAccount: t('pipelinq', 'On account'),
			}
			return map[tender] || tender || ''
		},
	},
}
</script>

<style scoped>
.purchase-history {
	margin: 16px 0;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.purchase-history__header {
	display: flex;
	gap: 8px;
	align-items: center;
	width: 100%;
	padding: 10px 12px;
	background: none;
	border: none;
	cursor: pointer;
	text-align: start;
	font-weight: bold;
}

.purchase-history__spend {
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
}

.purchase-history__body {
	padding: 0 12px 12px;
}

.purchase-history__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.purchase-history__item {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 8px;
	padding: 4px 0;
	border-top: 1px solid var(--color-border);
	font-size: 13px;
}

.purchase-history__empty {
	color: var(--color-text-maxcontrast);
}
</style>
