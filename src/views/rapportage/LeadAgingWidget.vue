<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<template>
	<CnChartWidget
		v-if="hasData"
		type="donut"
		:title="t('pipelinq', 'Lead aging')"
		:series="series"
		:labels="labels"
		:height="280" />
	<div v-else class="widget-empty">
		{{ t('pipelinq', 'No leads to age') }}
	</div>
</template>

<script>
import { CnChartWidget } from '@conduction/nextcloud-vue'

export default {
	name: 'LeadAgingWidget',
	components: {
		CnChartWidget,
	},
	props: {
		/**
		 * Aging buckets: { bucket, count, totalValue }.
		 *
		 * @type {Array<object>}
		 */
		agingBuckets: {
			type: Array,
			default: () => [],
		},
	},
	computed: {
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.4
		 * @return {boolean} Whether any bucket has leads.
		 */
		hasData() {
			return Array.isArray(this.agingBuckets)
				&& this.agingBuckets.some(b => (Number(b.count) || 0) > 0)
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.4
		 * @return {Array<number>} Counts per bucket.
		 */
		series() {
			return this.agingBuckets.map(b => Number(b.count) || 0)
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.4
		 * @return {Array<string>} Bucket labels including total value.
		 */
		labels() {
			return this.agingBuckets.map((b) => {
				const value = Math.round(Number(b.totalValue) || 0)
				return `${b.bucket} (EUR ${value.toLocaleString('nl-NL')})`
			})
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
