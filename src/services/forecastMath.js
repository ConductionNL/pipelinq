/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Forecast display helpers.
 *
 * Pure presentation math (projected attainment, at-risk flag, accuracy colour
 * band) used by the forecast views. The authoritative computation lives on the
 * server; these helpers only format what the API returns.
 */

/**
 * Projected attainment: closed_won + commit + 0.5 * best_case.
 *
 * @param {number} closedWon The closed-won amount.
 * @param {number} commit The (override-applied) commit amount.
 * @param {number} bestCase The best-case amount.
 * @return {number} The projected attainment.
 */
export function projectedAttainment(closedWon, commit, bestCase) {
	return Number(closedWon || 0) + Number(commit || 0) + 0.5 * Number(bestCase || 0)
}

/**
 * Gap to quota (never negative).
 *
 * @param {number} quota The quota amount.
 * @param {number} projected The projected attainment.
 * @return {number} The gap to quota.
 */
export function gapToQuota(quota, projected) {
	return Math.max(0, Number(quota || 0) - Number(projected || 0))
}

/**
 * Whether a forecast is at risk for its quota.
 *
 * @param {number} projected The projected attainment.
 * @param {number} quota The quota amount.
 * @param {number} daysRemaining Days remaining in the period.
 * @param {number} [percentThreshold] The attainment percent threshold.
 * @param {number} [daysThreshold] The days-remaining threshold.
 * @return {boolean} True when at risk.
 */
export function isAtRisk(
	projected,
	quota,
	daysRemaining,
	percentThreshold = 90,
	daysThreshold = 30,
) {
	if (!quota || quota <= 0) {
		return false
	}
	const attainmentPercent = (Number(projected) / Number(quota)) * 100
	return attainmentPercent < percentThreshold && daysRemaining < daysThreshold
}

/**
 * Resolve the colour band for an accuracy score.
 *
 * @param {number} score The accuracy score (0.0-1.0).
 * @param {number} [green] The green threshold.
 * @param {number} [amber] The amber threshold.
 * @return {string} One of green, amber, red.
 */
export function accuracyBand(score, green = 0.9, amber = 0.75) {
	if (score > green) {
		return 'green'
	}
	if (score >= amber) {
		return 'amber'
	}
	return 'red'
}

/**
 * Attainment percent rounded to a whole number.
 *
 * @param {number} value The achieved amount.
 * @param {number} quota The quota amount.
 * @return {number} The percentage (0 when no quota).
 */
export function attainmentPercent(value, quota) {
	if (!quota || quota <= 0) {
		return 0
	}
	return Math.round((Number(value) / Number(quota)) * 100)
}
