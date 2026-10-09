<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/specs/lead-scoring-win-probability/spec.md#requirement-win-probability-is-surfaced-on-the-pipeline-list-and-deal-detail -->
<template>
	<span class="lead-win-cell">
		<span v-if="percent === null" class="lead-win-cell__dash">—</span>
		<template v-else>
			<span class="lead-win-cell__line">
				<span class="lead-win-cell__bar" aria-hidden="true">
					<span
						class="lead-win-cell__fill"
						:class="'lead-win-cell__fill--' + tone"
						:style="{ width: percent + '%' }" />
				</span>
				<span class="lead-win-cell__percent" :class="'lead-win-cell__percent--' + tone">{{ percent }}%</span>
			</span>
			<span v-if="staleDays !== null" class="lead-win-cell__stale">{{
				t('pipelinq', 'Out of date {days} days', { days: staleDays })
			}}</span>
		</template>
	</span>
</template>

<script>
import { getDaysAge, getStaleThreshold } from '../../../services/pipelineUtils.js'
import { useSettingsStore } from '../../../store/modules/settings.js'

/**
 * Win chance cell of the Leads list, as the board draws it: a bar with the
 * percentage beside it, and a "Verouderd N dagen" badge under it when the lead
 * has stood still past the stale threshold. The percentage is the lead's
 * `probability`, else its stored qualification score.
 *
 * @spec openspec/specs/lead-scoring-win-probability/spec.md#requirement-win-probability-is-surfaced-on-the-pipeline-list-and-deal-detail
 */
export default {
	name: 'LeadWinChanceCell',

	props: {
		/** Raw cell value (0..100). */
		value: {
			type: [Number, String],
			default: null,
		},

		/** The whole lead row. */
		row: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * The percentage, 0 to 100, or null when the lead has none.
		 *
		 * @return {?number}
		 */
		percent() {
			const raw = [this.value, this.row?.probability, this.row?.qualificationScore]
				.find((v) => v !== null && v !== undefined && v !== '' && !Number.isNaN(Number(v)))
			return raw === undefined ? null : Math.max(0, Math.min(100, Math.round(Number(raw))))
		},

		/**
		 * Bar and number colour: green from 60, amber from 30, red below.
		 *
		 * @return {string}
		 */
		tone() {
			if (this.percent >= 60) return 'good'
			if (this.percent >= 30) return 'warn'
			return 'bad'
		},

		/**
		 * Days since the lead was last touched, when that passes the stale
		 * threshold of an open lead; otherwise null.
		 *
		 * @return {?number}
		 */
		staleDays() {
			if (this.row?.status && this.row.status !== 'open') return null
			const modified = this.row?._dateModified || this.row?.['@self']?.updated
			if (!modified) return null
			const days = getDaysAge({ _dateModified: modified })
			let threshold = 14
			try {
				threshold = getStaleThreshold(useSettingsStore().config)
			} catch {
				// Settings not ready: keep the default.
			}
			return days >= threshold ? days : null
		},
	},
}
</script>

<style scoped>
.lead-win-cell {
	display: inline-flex;
	flex-direction: column;
	gap: 2px;
}

.lead-win-cell__line {
	display: inline-flex;
	align-items: center;
	gap: 12px;
}

.lead-win-cell__bar {
	width: 70px;
	height: 6px;
	border-radius: 3px;
	background: var(--color-background-dark);
	overflow: hidden;
}

.lead-win-cell__fill {
	display: block;
	height: 100%;
}

.lead-win-cell__fill--good { background: var(--color-element-success, var(--color-success)); }
.lead-win-cell__fill--warn { background: var(--color-element-warning, var(--color-warning)); }
.lead-win-cell__fill--bad { background: var(--color-element-error, var(--color-error)); }

.lead-win-cell__percent {
	font-weight: 700;
	font-size: 13px;
}

.lead-win-cell__percent--good { color: var(--color-success-text, var(--color-main-text)); }
.lead-win-cell__percent--warn { color: var(--color-warning-text, var(--color-main-text)); }
.lead-win-cell__percent--bad { color: var(--color-error-text, var(--color-main-text)); }

.lead-win-cell__stale {
	align-self: flex-start;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 12px;
	font-weight: 600;
	color: var(--color-error-text);
	background: var(--color-error);
	background: color-mix(in srgb, var(--color-error) 14%, transparent);
}
</style>
