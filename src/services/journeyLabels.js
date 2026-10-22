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

