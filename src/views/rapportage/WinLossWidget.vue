<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Win/loss pie chart + KPI stats block with a date-range selector.

  Per ADR-017 (self-contained components) the CnChartWidget and
  CnStatsBlock are rendered directly without an outer CnDetailCard wrap.

  @spec openspec/specs/lead-management/spec.md
-->
<template>
	<div class="win-loss-widget">
		<div class="win-loss-widget__filters">
			<NcSelect
				v-model="selectedRange"
				:options="rangeOptions"
				:clearable="false"
				:inputLabel="t('pipelinq', 'Date range')"
				label="label"
				trackBy="value"
				@update:modelValue="onRangeChange" />
		</div>

		<NcEmptyContent
			v-if="!hasData"
			:name="t('pipelinq', 'No closed deals')"
			:description="
				t('pipelinq', 'No won or lost leads in the selected range.')
			" />

		<template v-else>
			<CnStatsBlock :stats="statsCards" />
			<CnChartWidget
				type="pie"
				:title="t('pipelinq', 'Won vs lost')"
				:labels="[t('pipelinq', 'Won'), t('pipelinq', 'Lost')]"
				:series="pieSeries" />
		</template>
	</div>
</template>

<script>
import { CnChartWidget, CnStatsBlock } from '@conduction/nextcloud-vue'
import { NcEmptyContent, NcSelect } from '@nextcloud/vue'

export default {
	name: 'WinLossWidget',
	components: { CnChartWidget, CnStatsBlock, NcSelect, NcEmptyContent },
	props: {
		data: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['range-change'],
	data() {
		return {
			selectedRange: { value: 'all', label: '' },
		}
	},

	computed: {
		hasData() {
			const won = this.data?.wonCount || 0
			const lost = this.data?.lostCount || 0
			return won + lost > 0
		},

		pieSeries() {
			return [this.data?.wonCount || 0, this.data?.lostCount || 0]
		},

		/**
		 * @spec openspec/specs/lead-management/spec.md
		 */
		statsCards() {
			return [
				{
					label: t('pipelinq', 'Win rate'),
					value: `${this.data?.winRate || 0}%`,
				},
				{ label: t('pipelinq', 'Won'), value: this.data?.wonCount || 0 },
				{ label: t('pipelinq', 'Lost'), value: this.data?.lostCount || 0 },
				{
					label: t('pipelinq', 'Avg won deal value'),
					value:
						(this.data?.avgWonValue || 0) > 0
							? `EUR ${this.data.avgWonValue.toLocaleString('nl-NL')}`
							: '—',
				},
				{
					label: t('pipelinq', 'Avg days to close'),
					value:
						(this.data?.avgDaysToClose || 0) > 0
							? `${this.data.avgDaysToClose}d`
							: '—',
				},
			]
		},

		rangeOptions() {
			return [
				{ value: '30d', label: t('pipelinq', 'Last 30 days') },
				{ value: '90d', label: t('pipelinq', 'Last 90 days') },
				{ value: '12m', label: t('pipelinq', 'Last 12 months') },
				{ value: 'all', label: t('pipelinq', 'All time') },
			]
		},
	},

	/**
	 * @spec openspec/specs/lead-management/spec.md
	 */
	mounted() {
		this.selectedRange =
			this.rangeOptions.find((o) => o.value === 'all') || this.rangeOptions[0]
	},

	methods: {
		/**
		 * Translate the selected NcSelect option into a {from,to} range
		 * payload and emit it to the parent dashboard view.
		 *
		 * @param {object|null} option The selected option.
		 * @spec openspec/specs/lead-management/spec.md
		 */
		onRangeChange(option) {
			const value = option?.value || 'all'
			if (value === 'all') {
				this.$emit('range-change', null)
				return
			}
			const now = new Date()
			const from = new Date(now)
			if (value === '30d') from.setDate(now.getDate() - 30)
			else if (value === '90d') from.setDate(now.getDate() - 90)
			else if (value === '12m') from.setMonth(now.getMonth() - 12)
			const fmt = (d) => d.toISOString().slice(0, 10)
			this.$emit('range-change', { from: fmt(from), to: fmt(now) })
		},
	},
}
</script>

<style scoped>
.win-loss-widget__filters {
	margin-bottom: 12px;
	max-width: 220px;
}
</style>
