// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the site-traffic block on the blast performance dashboard should say,
 * given how its per-blast reads went.
 *
 * WHY THIS IS A FUNCTION AND NOT THREE LINES IN THE COMPONENT. The dashboard
 * used to carry this decision in one nullable boolean, `trafficConnected`, and
 * that value meant three different things at once: still loading, nothing to
 * ask about, and every ask failed. The template read all three as "loading", so
 * an instance with no blasts showed a spinner that never stopped.
 *
 * The distinction is the whole defect, and the component's vitest environment
 * mounts nothing, so keeping it here is what makes it testable at all.
 */

/**
 * Classify the outcome of the per-blast performance reads.
 *
 * @param {object} counts          How the fan-out went.
 * @param {number} counts.asked    Blasts a request was actually sent for.
 * @param {number} counts.answered Requests that came back without throwing.
 *
 * @return {'no-blasts'|'unreadable'|'answered'} What the block should say.
 *
 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
 */
export function trafficOutcome({ asked, answered }) {
	// Order matters. `asked === 0` has to win, because answered is then also 0
	// and "every request failed" would be a claim about requests never sent.
	if (!asked) {
		return 'no-blasts'
	}

	if (!answered) {
		return 'unreadable'
	}

	return 'answered'
}
