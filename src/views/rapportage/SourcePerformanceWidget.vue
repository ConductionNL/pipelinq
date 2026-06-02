<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<template>
	<CnTableWidget
		v-if="hasData"
		:title="t('pipelinq', 'Source performance')"
		:rows="rows"
		:columns="columns" />
	<div v-else class="widget-empty">
		{{ t('pipelinq', 'No source data available') }}
	</div>
</template>

<script>
import { CnTableWidget } from '@conduction/nextcloud-vue'

export default {
	name: 'SourcePerformanceWidget',
	components: {
		CnTableWidget,
	},
	props: {
		/**
		 * Source rows: { source, total, won, conversionRate, avgWonValue }.
		 *
		 * @type {Array<object>}
		 */
		sourcePerformance: {
			type: Array,
			default: () => [],
		},
	},
	computed: {
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.3
		 * @return {boolean} Whether any source data is present.
		 */
		hasData() {
			return Array.isArray(this.sourcePerformance) && this.sourcePerformance.length > 0
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.3
		 * @return {Array<object>} Sortable column definitions.
		 */
		columns() {
			return [
				{ key: 'source', label: t('pipelinq', 'Source'), sortable: true },
				{ key: 'total', label: t('pipelinq', 'Total leads'), sortable: true },
				{ key: 'won', label: t('pipelinq', 'Won'), sortable: true },
				{ key: 'conversion', label: t('pipelinq', 'Conversion rate'), sortable: true },
				{ key: 'avgValue', label: t('pipelinq', 'Avg deal value'), sortable: true },
			]
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.3
		 * @return {Array<object>} Display rows with formatted cells.
		 */
		rows() {
			return this.sourcePerformance.map(s => ({
				source: s.source,
				total: s.total,
				won: s.won,
				conversion: `${Number(s.conversionRate || 0)}%`,
				avgValue: (s.avgWonValue === null || s.avgWonValue === undefined)
					? '—'
					: 'EUR ' + Number(s.avgWonValue).toLocaleString('nl-NL'),
			}))
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
