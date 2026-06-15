<template>
	<div class="renewals-due-widget">
		<div v-if="!loaded" class="chart-empty">
			{{ t('pipelinq', 'Loading…') }}
		</div>
		<div v-else-if="rows.length === 0" class="chart-empty">
			{{ t('pipelinq', 'No renewals due') }}
		</div>
		<ul v-else class="renewals-list">
			<li v-for="row in rows" :key="row.id" class="renewals-row">
				<router-link :to="{ name: 'ContractDetail', params: { id: row.id } }" class="renewals-link">
					<span class="renewals-title">{{ row.title }}</span>
					<span class="renewals-meta">{{ row.endDate }} · €{{ row.value }}</span>
				</router-link>
			</li>
		</ul>
	</div>
</template>

<script>
// "Renewals due" dashboard widget (contract-renewal-tracking): lists expiring
// contracts ordered by endDate, each deep-linking to the contract detail.
//
// @spec openspec/changes/contract-renewal-tracking/specs/dashboard/spec.md#requirement-renewals-due-widget
import { getContracts } from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'RenewalsDueWidget',
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			loaded: false,
			contracts: [],
		}
	},
	computed: {
		/**
		 * Expiring contracts ordered by endDate (soonest first).
		 *
		 * @spec openspec/changes/contract-renewal-tracking/specs/dashboard/spec.md#requirement-renewals-due-widget
		 * @return {Array<object>} The display rows.
		 */
		rows() {
			return (this.contracts || [])
				.filter(c => c.status === 'expiring')
				.sort((a, b) => String(a.endDate || '').localeCompare(String(b.endDate || '')))
				.map(c => ({
					id: c.id || c.uuid,
					title: c.title || c.contractNumber || t('pipelinq', 'Contract'),
					endDate: c.endDate || '',
					value: Number(c.valuePerInterval || 0).toLocaleString('nl-NL', { maximumFractionDigits: 0 }),
				}))
		},
	},
	methods: {
		/**
		 * @spec openspec/changes/contract-renewal-tracking/specs/dashboard/spec.md#requirement-renewals-due-widget
		 */
		async load() {
			try {
				this.contracts = await getContracts()
			} catch (err) {
				console.error('RenewalsDueWidget fetch error:', err)
			} finally {
				this.loaded = true
			}
		},
	},
}
</script>

<style scoped>
.renewals-list {
	list-style: none;
	margin: 0;
	padding: 0;
}
.renewals-row {
	border-bottom: 1px solid var(--color-border);
}
.renewals-link {
	display: flex;
	justify-content: space-between;
	padding: 8px 4px;
	color: var(--color-main-text);
}
.renewals-meta {
	color: var(--color-text-maxcontrast);
}
.chart-empty {
	padding: 16px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}
</style>
