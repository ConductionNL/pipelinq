<template>
	<CnStatsBlock
		:title="t('pipelinq', 'Recurring revenue (MRR)')"
		:count="mrrLabel"
		:count-label="arrLabel"
		:icon="Repeat"
		variant="success"
		horizontal
		:route="{ name: 'Contracts' }" />
</template>

<script>
// MRR KPI card for the dashboard (contract-renewal-tracking). Shows current
// MRR with ARR as the sub-label, computed from active + expiring contracts.
//
// @spec openspec/changes/contract-renewal-tracking/specs/dashboard/spec.md#requirement-mrr-kpi-card
import { CnStatsBlock } from '@conduction/nextcloud-vue'
import Repeat from 'vue-material-design-icons/Repeat.vue'
import { getContracts } from '../../../services/dashboardData.js'
import { computeMrr, computeArr } from '../../../services/recurringRevenue.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'MrrKpiWidget',
	components: {
		CnStatsBlock,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			Repeat,
			mrr: 0,
			arr: 0,
		}
	},
	computed: {
		mrrLabel() {
			return '€' + this.mrr.toLocaleString('nl-NL', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
		},
		arrLabel() {
			return t('pipelinq', 'ARR €{arr}', { arr: this.arr.toLocaleString('nl-NL', { maximumFractionDigits: 0 }) })
		},
	},
	methods: {
		/**
		 * @spec openspec/changes/contract-renewal-tracking/specs/dashboard/spec.md#requirement-mrr-kpi-card
		 */
		async load() {
			try {
				const contracts = await getContracts()
				this.mrr = computeMrr(contracts)
				this.arr = computeArr(contracts)
			} catch (err) {
				console.error('MrrKpiWidget fetch error:', err)
			}
		},
	},
}
</script>
