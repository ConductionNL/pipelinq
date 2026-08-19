<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/specs/lead-scoring-win-probability/spec.md#requirement-win-probability-is-surfaced-on-the-pipeline-list-and-deal-detail -->
<template>
	<span class="lead-prob-cell">
		<span v-if="isEmpty" class="lead-prob-cell__dash">—</span>
		<template v-else>
			<span class="lead-prob-cell__value">{{ percent }}%</span>
			<span
				v-if="isLow"
				class="lead-prob-cell__badge"
				:aria-label="t('pipelinq', 'Low probability')">
				<AlertCircleOutline :size="14" class="lead-prob-cell__badge-icon" />
				<span class="lead-prob-cell__badge-label">{{ t('pipelinq', 'Low') }}</span>
			</span>
		</template>
	</span>
</template>

<script>
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'

/**
 * Lead probability cell renderer (Customer 360 / REQ-KB360-015).
 *
 * Displays the probability as a plain percentage. When the lead's
 * probability is < 30, a "Low" badge is appended next to the value.
 * Per WCAG AA, the badge ALWAYS combines an icon plus a text label
 * — colour is never the sole conveyor.
 *
 * @spec openspec/specs/customer-360/spec.md
 */
export default {
	name: 'LeadProbabilityCell',
	components: {
		AlertCircleOutline,
	},
	props: {
		/**
		 * Raw cell value (0..100).
		 *
		 * @type {*}
		 */
		value: {
			type: [Number, String],
			default: null,
		},
	},
	computed: {
		/**
		 * True when the raw value is missing or non-numeric.
		 *
		 * @return {boolean}
		 */
		isEmpty() {
			return this.value === null || this.value === undefined || this.value === '' || Number.isNaN(Number(this.value))
		},
		/**
		 * Probability as a rounded integer percentage.
		 *
		 * @return {number}
		 */
		percent() {
			return Math.round(Number(this.value) || 0)
		},
		/**
		 * True when the probability falls under the low-confidence threshold.
		 *
		 * @return {boolean}
		 */
		isLow() {
			return !this.isEmpty && this.percent < 30
		},
	},
}
</script>

<style scoped>
.lead-prob-cell {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}

.lead-prob-cell__badge {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-warning);
	color: var(--color-main-background);
	font-size: 11px;
	font-weight: 600;
	line-height: 1.2;
}

.lead-prob-cell__badge-icon {
	color: var(--color-main-background);
}

.lead-prob-cell__dash {
	color: var(--color-text-maxcontrast);
}
</style>
