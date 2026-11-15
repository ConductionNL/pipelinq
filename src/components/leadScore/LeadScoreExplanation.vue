<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/specs/lead-management/spec.md#requirement-a-person-can-see-why-a-lead-has-its-score-req-lscore-003 -->
<template>
	<div class="lead-score-explanation">
		<p class="lead-score-explanation__title">
			{{ t('pipelinq', 'Why this score') }}
		</p>
		<ul v-if="explanation.matched.length" class="lead-score-explanation__list">
			<li v-for="criterion in explanation.matched" :key="criterion.id">
				<span>{{ t('pipelinq', criterion.label) }}</span>
				<span class="lead-score-explanation__points"
					>+{{ criterion.points }}</span
				>
			</li>
		</ul>
		<p v-else class="lead-score-explanation__empty">
			{{
				t(
					'pipelinq',
					'No criterion adds points yet. Add a value, a client or an expected close date to raise the score.',
				)
			}}
		</p>
		<p class="lead-score-explanation__total">
			<span>{{ t('pipelinq', 'Total') }}</span>
			<span class="lead-score-explanation__points">{{
				explanation.total
			}}</span>
		</p>
		<p v-if="drifted" class="lead-score-explanation__drift">
			{{
				t(
					'pipelinq',
					'The score changed since it was calculated. Save the lead to recalculate it.',
				)
			}}
		</p>
	</div>
</template>

<script>
import { explainScore, normaliseScore } from '../../services/leadScore.js'

/**
 * Lists the criteria that added points to a lead's qualification score.
 * The stored score stays the number shown; this only annotates it, and
 * says so when the listed total differs from the stored one.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-a-person-can-see-why-a-lead-has-its-score-req-lscore-003
 */
export default {
	name: 'LeadScoreExplanation',

	props: {
		/** The lead the score belongs to. */
		lead: {
			type: Object,
			required: true,
		},
	},

	computed: {
		/**
		 * @return {{matched: Array<object>, total: number}}
		 * @spec openspec/specs/lead-management/spec.md#requirement-a-person-can-see-why-a-lead-has-its-score-req-lscore-003
		 */
		explanation() {
			return explainScore(this.lead)
		},

		/**
		 * True when the stored score and the listed total differ.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/lead-management/spec.md#requirement-a-person-can-see-why-a-lead-has-its-score-req-lscore-003
		 */
		drifted() {
			const stored = normaliseScore(this.lead.qualificationScore)
			return stored !== null && stored !== this.explanation.total
		},
	},
}
</script>

<style scoped>
.lead-score-explanation {
	padding: 12px;
	min-width: 240px;
	max-width: 320px;
}

.lead-score-explanation__title {
	font-weight: 600;
	margin-bottom: 8px;
}

.lead-score-explanation__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.lead-score-explanation__list li,
.lead-score-explanation__total {
	display: flex;
	justify-content: space-between;
	gap: 12px;
	padding: 2px 0;
}

.lead-score-explanation__total {
	border-top: 1px solid var(--color-border);
	margin-top: 6px;
	padding-top: 6px;
	font-weight: 600;
}

.lead-score-explanation__points {
	font-variant-numeric: tabular-nums;
}

.lead-score-explanation__empty,
.lead-score-explanation__drift {
	color: var(--color-text-maxcontrast);
	margin-top: 6px;
}
</style>
