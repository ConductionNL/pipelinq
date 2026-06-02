<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<template>
	<div class="winloss">
		<div class="winloss__header">
			<h3 class="winloss__title">
				{{ t('pipelinq', 'Won / lost') }}
			</h3>
			<NcSelect
				:input-label="t('pipelinq', 'Date range')"
				:value="selectedRange"
				:options="rangeOptions"
				:clearable="false"
				:reduce="reduceRange"
				label="label"
				class="winloss__range"
				@input="onRangeChange" />
		</div>

		<div class="winloss__stats">
			<CnStatsBlock
				:title="t('pipelinq', 'Win rate')"
				:count="winRate"
				count-label="%"
				variant="success"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Won')"
				:count="winLoss.wonCount || 0"
				:count-label="t('pipelinq', 'deals')"
				variant="success"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Lost')"
				:count="winLoss.lostCount || 0"
				:count-label="t('pipelinq', 'deals')"
				variant="error"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Avg won deal value')"
				:count="avgWonValue"
				count-label="EUR"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Avg time to close')"
				:count="winLoss.avgDaysToClose || 0"
				:count-label="t('pipelinq', 'days')"
				horizontal />
		</div>

		<CnChartWidget
			v-if="hasData"
			type="pie"
			:series="series"
			:labels="labels"
			:height="240" />
		<div v-else class="widget-empty">
			{{ t('pipelinq', 'No closed deals in this period') }}
		</div>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { CnChartWidget, CnStatsBlock } from '@conduction/nextcloud-vue'

export default {
	name: 'WinLossWidget',
	components: {
		NcSelect,
		CnChartWidget,
		CnStatsBlock,
	},
	props: {
		/**
		 * Win/loss summary object.
		 *
		 * @type {object}
		 */
		winLoss: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Currently selected date-range id.
		 *
		 * @type {string}
		 */
		range: {
			type: String,
			default: 'all',
		},
	},
	computed: {
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.5
		 * @return {string} The selected range id.
		 */
		selectedRange() {
			return this.range
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.5
		 * @return {Array<object>} Available date-range filter options.
		 */
		rangeOptions() {
			return [
				{ id: '30d', label: t('pipelinq', 'Last 30 days') },
				{ id: '90d', label: t('pipelinq', 'Last 90 days') },
				{ id: '12m', label: t('pipelinq', 'Last 12 months') },
				{ id: 'all', label: t('pipelinq', 'All time') },
			]
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.5
		 * @return {boolean} Whether any closed deal exists.
		 */
		hasData() {
			return (Number(this.winLoss.wonCount) || 0) + (Number(this.winLoss.lostCount) || 0) > 0
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.5
		 * @return {Array<number>} Won vs lost counts.
		 */
		series() {
			return [Number(this.winLoss.wonCount) || 0, Number(this.winLoss.lostCount) || 0]
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.5
		 * @return {Array<string>} Pie segment labels.
		 */
		labels() {
			return [t('pipelinq', 'Won'), t('pipelinq', 'Lost')]
		},
		/**
		 * @return {number} Win rate rounded for display.
		 */
		winRate() {
			return Number(this.winLoss.winRate) || 0
		},
		/**
		 * @return {number} Average won value rounded for display.
		 */
		avgWonValue() {
			return Math.round(Number(this.winLoss.avgWonValue) || 0)
		},
	},
	methods: {
		/**
		 * Reduce a range option to its id for NcSelect.
		 *
		 * @param {object} opt The option object.
		 * @return {string} The option id.
		 */
		reduceRange(opt) {
			return opt.id
		},
		/**
		 * Emit a range change so the parent can refetch analytics.
		 *
		 * @spec openspec/changes/lead-management/tasks.md#7.5
		 * @param {string} id The selected range id.
		 * @return {void}
		 */
		onRangeChange(id) {
			if (id && id !== this.range) {
				this.$emit('range-change', id)
			}
		},
	},
}
</script>

<style scoped>
.winloss__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	margin-bottom: 12px;
}

.winloss__title {
	margin: 0;
	font-size: 1.1em;
}

.winloss__range {
	min-width: 200px;
}

.winloss__stats {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
	gap: 12px;
	margin-bottom: 16px;
}

.widget-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>
