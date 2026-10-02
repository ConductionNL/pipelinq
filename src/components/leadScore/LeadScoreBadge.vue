<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/specs/lead-management/spec.md#requirement-the-board-card-shows-the-score-req-lscore-002 -->
<template>
	<span v-if="band === null" class="lead-score-badge__dash">—</span>
	<span
		v-else
		class="lead-score-badge"
		@click.stop
		@keydown.enter.stop
		@keydown.space.stop>
		<NcPopover popupRole="dialog" placement="bottom-start">
			<template #trigger>
				<button
					type="button"
					class="lead-score-badge__button"
					:class="'lead-score-badge__button--' + band"
					:aria-label="accessibleName"
					:title="accessibleName"
					aria-haspopup="dialog">
					<span class="lead-score-badge__value">{{ score }}</span>
					<span v-if="!compact" class="lead-score-badge__band">{{
						bandLabel
					}}</span>
				</button>
			</template>
			<LeadScoreExplanation :lead="lead" />
		</NcPopover>
	</span>
</template>

<script>
import { NcPopover } from '@nextcloud/vue'
import LeadScoreExplanation from './LeadScoreExplanation.vue'
import { normaliseScore, scoreBand } from '../../services/leadScore.js'

/**
 * A lead's stored qualification score as a number with its band as text
 * (High, Medium, Low), so the band is never conveyed by colour alone.
 * Opens the explanation of the score. Shows a dash for a lead that was
 * saved before the score existed. `compact` hides the band word for the
 * board card; the accessible name still carries it.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-the-board-card-shows-the-score-req-lscore-002
 */
export default {
	name: 'LeadScoreBadge',
	components: {
		NcPopover,
		LeadScoreExplanation,
	},

	props: {
		/** The lead; its `qualificationScore` is the number shown. */
		lead: {
			type: Object,
			required: true,
		},

		/** Hide the band word (board card). */
		compact: {
			type: Boolean,
			default: false,
		},
	},

	computed: {
		/**
		 * @return {number|null}
		 * @spec openspec/specs/lead-management/spec.md#requirement-the-lead-list-shows-and-sorts-by-score-req-lscore-001
		 */
		score() {
			return normaliseScore(this.lead.qualificationScore)
		},

		/**
		 * @return {('high'|'medium'|'low'|null)}
		 * @spec openspec/specs/lead-management/spec.md#requirement-the-lead-list-shows-and-sorts-by-score-req-lscore-001
		 */
		band() {
			return scoreBand(this.score)
		},

		/**
		 * @return {string}
		 * @spec openspec/specs/lead-management/spec.md#requirement-the-lead-list-shows-and-sorts-by-score-req-lscore-001
		 */
		bandLabel() {
			if (this.band === 'high') return t('pipelinq', 'High')
			if (this.band === 'medium') return t('pipelinq', 'Medium')
			return t('pipelinq', 'Low')
		},

		/**
		 * "Score 92, high": the number and the band, for screen readers.
		 *
		 * @return {string}
		 * @spec openspec/specs/lead-management/spec.md#requirement-the-board-card-shows-the-score-req-lscore-002
		 */
		accessibleName() {
			return t('pipelinq', 'Score {score}, {band}', {
				score: this.score,
				band: this.bandLabel.toLowerCase(),
			})
		},
	},
}
</script>

<style scoped>
.lead-score-badge {
	display: inline-flex;
}

.lead-score-badge__button {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	min-height: 24px;
	margin: 0;
	padding: 0 8px;
	border: 1px solid transparent;
	border-radius: var(--border-radius-pill, 999px);
	font-size: 12px;
	font-weight: 600;
	cursor: pointer;
}

.lead-score-badge__button:focus-visible {
	outline: 2px solid var(--color-main-text);
	outline-offset: 2px;
}

.lead-score-badge__button--high {
	background: var(--color-success);
	color: var(--color-success-text, var(--color-primary-element-text));
}

.lead-score-badge__button--medium {
	background: var(--color-warning);
	color: var(--color-warning-text, var(--color-main-text));
}

.lead-score-badge__button--low {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	border-color: var(--color-border-dark);
}

.lead-score-badge__value {
	font-variant-numeric: tabular-nums;
}

.lead-score-badge__band {
	font-weight: 400;
}

.lead-score-badge__dash {
	color: var(--color-text-maxcontrast);
}
</style>
