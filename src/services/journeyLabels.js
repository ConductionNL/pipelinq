// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The words a journey run and a weekly review are read in.
 *
 * These live outside the components because they are the part that carries
 * meaning. `suppressed_dunning` is a stored code, and a marketer reading a
 * run log wants to know that the customer is being chased for money. A code
 * rendered raw answers nothing, and a wrong mapping is invisible in a
 * screenshot, so the mapping is a pure function with a test.
 *
 * Every function takes the translator rather than importing one, so the
 * module stays free of a Nextcloud runtime and can be tested offline.
 */

/**
 * What the flow engine did with a journey, in words.
 *
 * An empty string means it compiled cleanly and there is nothing to say.
 *
 * @param {object} journey The stored journey.
 * @param {function(string, string, object=): string} t The translator.
 * @return {string} The message, empty when the journey compiled.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
export function flowStatusMessage(journey, t) {
	if (journey?.flowStatus === 'engine_missing') {
		return t(
			'pipelinq',
			'This journey is saved but it will not run: this instance has no flow engine.',
		)
	}
	if (journey?.flowStatus === 'refused') {
		return t('pipelinq', 'The flow engine refused this journey: {reason}', {
			reason: journey.flowError || '',
		})
	}
	return ''
}

/**
 * The sources a review found nothing in at all.
 *
 * An empty source is NEVER a zero. "0 competitor moves" on an instance with
 * no watches configured is the kind of number a reader believes.
 *
 * @param {object} review The composed review.
 * @return {Array<string>} The empty source slugs.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
 */
export function degradedSources(review) {
	const degraded = review?.degraded
	return Array.isArray(degraded) ? degraded : []
}

/**
 * Whether a review's narrative was written by an agent (ADR-088).
 *
 * @param {object} review The composed review.
 * @return {boolean} True when an agent wrote it.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-narrative-mark-has-storage-and-a-renderer-and-no-writer-yet
 */
export function isAgentAuthored(review) {
	return Boolean(review?.agentAuthored) && Boolean(review?.agentAuthoredBy)
}
