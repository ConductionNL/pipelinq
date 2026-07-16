<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Aging distribution donut chart — 4 buckets keyed by _dateModified.

  @spec openspec/specs/lead-management/spec.md
-->
<template>
	<NcEmptyContent
		v-if="!hasData"
		:name="t('pipelinq', 'No aging data')"
		:description="t('pipelinq', 'There are no open leads to distribute into aging buckets.')" />

	<CnChartWidget
		v-else
		type="donut"
		:title="t('pipelinq', 'Lead aging distribution')"
		:labels="labels"
		:series="series" />
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'
import { NcEmptyContent } from '@nextcloud/vue'

export default {
	name: 'LeadAgingWidget',
	components: { CnChartWidget, NcEmptyContent },
	props: {
		data: {
			type: Array,
			default: () => [],
		},
	},
	computed: {
		buckets() {
			return Array.isArray(this.data) ? this.data : []
		},
		labels() {
			return this.buckets.map(b => {
				const total = b.totalValue > 0 ? ` (EUR ${b.totalValue.toLocaleString('nl-NL')})` : ''
				return `${b.bucket}: ${b.count}${total}`
			})
		},
		series() {
			return this.buckets.map(b => b.count || 0)
		},
		hasData() {
			return this.buckets.some(b => (b.count || 0) > 0)
		},
	},
}
</script>
