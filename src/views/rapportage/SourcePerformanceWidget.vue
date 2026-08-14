<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Source performance — sortable table of total / won / conversion / avg
  per lead source.

  @spec openspec/specs/lead-management/spec.md
-->
<template>
	<NcEmptyContent
		v-if="!rows.length"
		:name="t('pipelinq', 'No source data')"
		:description="t('pipelinq', 'No lead source performance to report yet.')" />

	<CnDataTable v-else :rows="rows" :columns="columns" borderless />
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { NcEmptyContent } from '@nextcloud/vue'

export default {
	name: 'SourcePerformanceWidget',
	components: { CnDataTable, NcEmptyContent },
	props: {
		data: {
			type: Array,
			default: () => [],
		},
	},
	computed: {
		/**
		 * Column descriptors consumed by CnDataTable. Each row is
		 * rendered with sortable per-column behaviour.
		 *
		 * @spec openspec/specs/lead-management/spec.md
		 */
		columns() {
			return [
				{ key: 'source', label: t('pipelinq', 'Source'), sortable: true },
				{
					key: 'total',
					label: t('pipelinq', 'Total leads'),
					sortable: true,
					align: 'right',
				},
				{
					key: 'won',
					label: t('pipelinq', 'Won'),
					sortable: true,
					align: 'right',
				},
				{
					key: 'conversionRate',
					label: t('pipelinq', 'Conversion %'),
					sortable: true,
					align: 'right',
					format: (v) => `${v}%`,
				},
				{
					key: 'avgWonValue',
					label: t('pipelinq', 'Avg deal value'),
					sortable: true,
					align: 'right',
					format: (v) =>
						v > 0 ? `EUR ${v.toLocaleString('nl-NL')}` : '—',
				},
			]
		},
		rows() {
			return Array.isArray(this.data) ? this.data : []
		},
	},
}
</script>
