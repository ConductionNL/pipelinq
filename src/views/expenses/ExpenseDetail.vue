<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Expense detail view with embedded Shillinq AP card (REQ-AP-006). Falls
  back to a "new" placeholder when the route id is "new" so the same
  component handles both create and detail paths.
-->
<template>
	<div>
		<CnDetailPage v-if="expenseId !== 'new'"
			:title="expense.title || t('pipelinq', 'Expense')"
			:subtitle="t('pipelinq', 'Expense')"
			:back-route="{ name: 'Expenses' }"
			:back-label="t('pipelinq', 'Back to list')"
			:loading="loading"
			:sidebar="{ enabled: !loading }"
			object-type="pipelinq_expense"
			:object-id="expenseId">
			<CnDetailCard :title="t('pipelinq', 'Expense information')">
				<div class="info-grid">
					<div class="info-field">
						<label>{{ t('pipelinq', 'Title') }}</label>
						<span>{{ expense.title || '-' }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Description') }}</label>
						<span>{{ expense.description || '-' }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Amount') }}</label>
						<span>{{ formattedAmount }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Category') }}</label>
						<span>{{ expense.category || '-' }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Billable') }}</label>
						<span>{{ expense.billable ? t('pipelinq', 'Yes') : t('pipelinq', 'No') }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Status') }}</label>
						<span>{{ expense.status || '-' }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Approved by') }}</label>
						<span>{{ expense.approvedBy || '-' }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Approved at') }}</label>
						<span>{{ expense.approvedAt || '-' }}</span>
					</div>
				</div>
			</CnDetailCard>

			<!-- Shillinq AP card (REQ-AP-006) -->
			<ExpenseShillinqApCard :expense="cardExpense" @updated="onApUpdated" />
		</CnDetailPage>

		<div v-else>
			<h2>{{ t('pipelinq', 'New expense') }}</h2>
			<p>{{ t('pipelinq', 'Expense creation will be available once expense-capture-core ships.') }}</p>
		</div>
	</div>
</template>

<script>
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import ExpenseShillinqApCard from './ExpenseShillinqApCard.vue'

export default {
	name: 'ExpenseDetail',
	components: {
		CnDetailPage,
		CnDetailCard,
		ExpenseShillinqApCard,
	},
	setup() {
		const objectStore = useObjectStore()
		return { objectStore }
	},
	data() {
		return {
			expense: {},
			loading: false,
		}
	},
	computed: {
		expenseId() {
			return this.$route?.params?.id || 'new'
		},
		formattedAmount() {
			if (typeof this.expense.amount !== 'number') {
				return '-'
			}
			const currency = this.expense.currency || 'EUR'
			try {
				return new Intl.NumberFormat('nl-NL', { style: 'currency', currency }).format(this.expense.amount)
			} catch (e) {
				return `${this.expense.amount} ${currency}`
			}
		},
		/**
		 * Inject the route id so the card component can call the retry endpoint
		 * even when the underlying expense object's `id` field is missing.
		 *
		 * @return {object} The expense decorated with `id`.
		 */
		cardExpense() {
			return { ...this.expense, id: this.expenseId }
		},
	},
	mounted() {
		if (this.expenseId !== 'new') {
			this.load()
		}
	},
	methods: {
		async load() {
			this.loading = true
			try {
				const obj = await this.objectStore.fetchObject({ schemaSlug: 'expense', id: this.expenseId })
				if (obj) {
					this.expense = obj
				}
			} catch (e) {
				this.expense = {}
			} finally {
				this.loading = false
			}
		},
		/**
		 * Apply the AP retry result to the local expense state. REQ-AP-006.
		 *
		 * @param {object} payload The retry response payload.
		 */
		onApUpdated(payload) {
			if (payload && typeof payload === 'object') {
				this.expense = { ...this.expense, ...payload }
			}
		},
	},
}
</script>

<style scoped>
.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px 24px;
}

.info-field {
	display: flex;
	flex-direction: column;
}

.info-field label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast, #555);
	margin-bottom: 4px;
}

.info-field span {
	font-size: 1em;
}
</style>
