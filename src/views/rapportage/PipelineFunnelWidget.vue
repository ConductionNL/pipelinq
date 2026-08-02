<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Pipeline value per stage — bar chart with two series (total + weighted).

  @spec openspec/specs/lead-management/spec.md
-->
<template>
	<div class="pipeline-funnel-widget">
		<div class="pipeline-funnel-widget__filters">
			<NcSelect
				v-model="selectedPipeline"
				:options="pipelineOptions"
				:clearable="true"
				:input-label="t('pipelinq', 'Pipeline')"
				label="label"
				track-by="value"
				@update:model-value="$emit('pipeline-change', selectedPipeline ? selectedPipeline.value : null)" />
		</div>

		<NcEmptyContent
			v-if="!filteredData.length"
			:name="t('pipelinq', 'No pipeline data')"
			:description="t('pipelinq', 'There are no leads in this pipeline yet.')" />

		<CnChartWidget
			v-else
			type="bar"
			:title="t('pipelinq', 'Pipeline value per stage')"
			:categories="categories"
			:series="series" />
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { NcSelect, NcEmptyContent } from '@nextcloud/vue'

export default {
	name: 'PipelineFunnelWidget',
	components: { CnChartWidget, NcSelect, NcEmptyContent },
	props: {
		data: {
			type: Array,
			default: () => [],
		},
		pipelineOptions: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['pipeline-change'],
	data() {
		return {
			selectedPipeline: null,
		}
	},
	computed: {
		filteredData() {
			return Array.isArray(this.data) ? this.data : []
		},
		categories() {
			return this.filteredData.map(row => row.stage)
		},
		series() {
			return [
				{
					name: t('pipelinq', 'Total value'),
					data: this.filteredData.map(row => Math.round(row.totalValue || 0)),
				},
				{
					name: t('pipelinq', 'Weighted value'),
					data: this.filteredData.map(row => Math.round(row.weightedValue || 0)),
				},
			]
		},
	},
}
</script>

<style scoped>
.pipeline-funnel-widget__filters {
	margin-bottom: 12px;
	max-width: 260px;
}
</style>
