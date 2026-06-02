<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<template>
	<CnChartWidget
		v-if="hasData"
		type="bar"
		:title="t('pipelinq', 'Pipeline value per stage')"
		:series="series"
		:categories="categories"
		:height="280" />
	<div v-else class="widget-empty">
		{{ t('pipelinq', 'No pipeline data available') }}
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'

export default {
	name: 'PipelineFunnelWidget',
	components: {
		CnChartWidget,
	},
	props: {
		/**
		 * Stage value rows: { stage, count, totalValue, weightedValue }.
		 *
		 * @type {Array<object>}
		 */
		stageValues: {
			type: Array,
			default: () => [],
		},
	},
	computed: {
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.2
		 * @return {boolean} Whether any stage data is present.
		 */
		hasData() {
			return Array.isArray(this.stageValues) && this.stageValues.length > 0
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.2
		 * @return {Array<string>} Stage names for the x-axis.
		 */
		categories() {
			return this.stageValues.map(s => s.stage)
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.2
		 * @return {Array<object>} Total and weighted value series.
		 */
		series() {
			return [
				{
					name: t('pipelinq', 'Total value'),
					data: this.stageValues.map(s => Math.round(Number(s.totalValue) || 0)),
				},
				{
					name: t('pipelinq', 'Weighted value'),
					data: this.stageValues.map(s => Math.round(Number(s.weightedValue) || 0)),
				},
			]
		},
	},
}
</script>

<style scoped>
.widget-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}
</style>
