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
 * How a journey run ended, in words.
 *
 * @param {string} state The stored state.
 * @param {function(string, string): string} t The translator.
 * @return {string} The label, falling back to the stored value.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
 */
export function runStateLabel(state, t) {
	const labels = {
		sent: t('pipelinq', 'Sent'),
		refused: t('pipelinq', 'Refused'),
		'task-created': t('pipelinq', 'Task created'),
		failed: t('pipelinq', 'Failed'),
	}
	return labels[state] || state
}

/**
 * Why a journey refused or failed, in words.
 *
 * @param {string} reason The stored reason.
 * @param {function(string, string): string} t The translator.
 * @return {string} The label, falling back to the stored value.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
 */
export function runReasonLabel(reason, t) {
	const labels = {
		no_consent: t('pipelinq', 'No consent for this channel'),
		suppressed_dunning: t(
			'pipelinq',
			'Skipped: this customer is being chased for an unpaid invoice',
		),
		template_missing: t('pipelinq', 'The template is gone'),
		no_transport: t('pipelinq', 'No mail transport is configured'),
		no_email: t('pipelinq', 'This contact has no email address'),
		transport_rejected: t('pipelinq', 'The mail transport rejected it'),
	}
	return labels[reason] || reason
}

/**
 * How many runs ended each way.
 *
 * A refusal is counted on its own, because a journey that reached nobody
 * because nobody consented looks exactly like a journey with a small
 * audience until these two numbers are side by side.
 *
 * @param {Array<object>} runs The journey runs.
 * @return {object} `{sent, refused, failed}`.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
 */
export function runCounts(runs) {
	const counts = { sent: 0, refused: 0, failed: 0 }
	for (const run of runs || []) {
		if (run.state === 'refused') counts.refused += 1
		else if (run.state === 'failed') counts.failed += 1
		else counts.sent += 1
	}
	return counts
}

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
