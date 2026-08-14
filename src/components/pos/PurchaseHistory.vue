<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS purchase-history panel — collapsible widget that shows the customer's
  - most recent confirmed / settled / refunded transactions next to the
  - checkout form.
  -
  - @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-003
  -->
<template>
	<section class="purchase-history" data-testid="purchase-history">
		<button
			type="button"
			class="purchase-history__toggle"
			:aria-expanded="!collapsed"
			@click="toggle">
			<span aria-hidden="true">{{ collapsed ? '▶' : '▼' }}</span>
			<span>{{ t('pipelinq', 'Purchase history') }} ({{ rows.length }})</span>
		</button>

		<div v-if="!collapsed" class="purchase-history__body">
			<p v-if="!rows.length" class="purchase-history__empty">
				{{ t('pipelinq', 'No previous purchases.') }}
			</p>
			<table v-else class="purchase-history__table">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Date') }}</th>
						<th scope="col">{{ t('pipelinq', 'Reference') }}</th>
						<th scope="col">{{ t('pipelinq', 'Items') }}</th>
						<th scope="col">{{ t('pipelinq', 'Total') }}</th>
						<th scope="col">{{ t('pipelinq', 'Tender') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.id">
						<td>{{ formatDate(row.createdAt) }}</td>
						<td>{{ row.reference || '—' }}</td>
						<td>{{ row.itemCount || 0 }}</td>
						<td>€{{ formatMoney(row.total) }}</td>
						<td>
							<span
								class="purchase-history__tender"
								:class="[tenderClass(row.tenderType)]">
								{{ tenderLabel(row.tenderType) }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</section>
</template>

<script>
export default {
	name: 'PurchaseHistory',
	props: {
		rows: {
			type: Array,
			default: () => [],
		},

		defaultCollapsed: {
			type: Boolean,
			default: true,
		},
	},

	data() {
		return {
			collapsed: this.defaultCollapsed,
		}
	},

	methods: {
		/**
		 * Toggle the panel.
		 */
		toggle() {
			this.collapsed = !this.collapsed
		},

		/**
		 * Format an ISO date to a Dutch locale day.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string} The formatted date.
		 */
		formatDate(iso) {
			if (!iso) {
				return '—'
			}
			try {
				return new Date(iso).toLocaleDateString('nl-NL', {
					day: '2-digit',
					month: '2-digit',
					year: 'numeric',
				})
			} catch {
				return iso
			}
		},

		/**
		 * Format a money amount to two decimals.
		 *
		 * @param {number} value The amount.
		 * @return {string} The formatted amount.
		 */
		formatMoney(value) {
			const num = typeof value === 'number' ? value : Number(value || 0)
			return num.toFixed(2)
		},

		/**
		 * Render a tender label for the i18n catalogue.
		 *
		 * @param {string} tender The tender code.
		 * @return {string} The translated label.
		 */
		tenderLabel(tender) {
			if (tender === 'card') {
				return t('pipelinq', 'Card')
			}
			if (tender === 'onAccount') {
				return t('pipelinq', 'On account')
			}
			return t('pipelinq', 'Cash')
		},

		/**
		 * Visual class for the tender pill.
		 *
		 * @param {string} tender The tender code.
		 * @return {string} The CSS class.
		 */
		tenderClass(tender) {
			return tender === 'onAccount'
				? 'purchase-history__tender--on-account'
				: ''
		},
	},
}
</script>

<style scoped>
.purchase-history {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
	margin: 16px 0;
}

.purchase-history__toggle {
	display: flex;
	align-items: center;
	gap: 8px;
	width: 100%;
	border: none;
	background: transparent;
	padding: 8px 12px;
	cursor: pointer;
	font-weight: 600;
	text-align: left;
}

.purchase-history__body {
	padding: 8px 12px 12px;
}

.purchase-history__empty {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.purchase-history__table {
	width: 100%;
	border-collapse: collapse;
}

.purchase-history__table th {
	font-size: 11px;
	text-align: left;
	color: var(--color-text-maxcontrast);
	padding: 4px 6px;
	border-bottom: 1px solid var(--color-border);
}

.purchase-history__table td {
	padding: 6px;
	font-size: 13px;
	border-bottom: 1px solid var(--color-border-dark);
}

.purchase-history__tender {
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 11px;
	background: var(--color-background-dark);
}

.purchase-history__tender--on-account {
	background: var(--color-warning, #d97706);
	color: var(--color-primary-text, #fff);
	font-weight: 600;
}
</style>
