<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - KpiFigure: one figure drawn as a KPI, the number large with a short line
  - under it saying what it counts. For a card that already has its own title.
  -->
<template>
	<div class="kpi-figure">
		<strong class="kpi-figure__value">{{ value }}</strong>
		<span v-if="caption" class="kpi-figure__caption">{{ caption }}</span>
	</div>
</template>

<script>
export default {
	name: 'KpiFigure',
	props: {
		/** The figure, already formatted when it needs to be. */
		value: {
			type: [Number, String],
			required: true,
		},

		/** What the figure counts, shown under it. */
		caption: {
			type: String,
			default: '',
		},
	},
}
</script>

<style scoped>
.kpi-figure {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.kpi-figure__value {
	font-size: 26px;
	font-weight: 700;
	line-height: 1.2;
	font-variant-numeric: tabular-nums;
}

.kpi-figure__caption {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
