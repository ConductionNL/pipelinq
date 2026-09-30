<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/specs/lead-management/spec.md#requirement-the-lead-list-shows-and-sorts-by-score-req-lscore-001 -->
<template>
	<LeadScoreBadge :lead="lead" />
</template>

<script>
import LeadScoreBadge from '../../../components/leadScore/LeadScoreBadge.vue'

/**
 * Score cell for the Leads list, registered as the `lead-score` cell
 * widget. Shows the stored score with its band word, or a dash.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-the-lead-list-shows-and-sorts-by-score-req-lscore-001
 */
export default {
	name: 'LeadScoreCell',
	components: {
		LeadScoreBadge,
	},

	props: {
		/** Raw cell value (the stored qualificationScore). */
		value: {
			type: [Number, String],
			default: null,
		},

		/** The whole lead row, used by the explanation. */
		row: {
			type: Object,
			default: () => ({}),
		},
	},

	computed: {
		/**
		 * The row with the cell value as its score, so the badge reads the
		 * same number the column shows.
		 *
		 * @return {object}
		 * @spec openspec/specs/lead-management/spec.md#requirement-the-lead-list-shows-and-sorts-by-score-req-lscore-001
		 */
		lead() {
			return { ...this.row, qualificationScore: this.value }
		},
	},
}
</script>
