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

/**
 * Turn snapshot rows into one point per snapshot date, the amounts of every
 * owner on that date added up, oldest first. A single owner's rows pass
 * through as one point each.
 *
 * @param {Array<object>} snapshots Rows with as_of_date, commit, best_case and pipeline.
 * @return {Array<{date: string, commit: number, best_case: number, pipeline: number}>}
 * @spec exclude forecast snapshots have no owning requirement, see
 *   ForecastSettings::load for the same gap
 */
export function sumSnapshotsByDate(snapshots) {
	const byDate = new Map()
	for (const row of snapshots) {
		const date = row.as_of_date
		const point = byDate.get(date) || {
			date,
			commit: 0,
			best_case: 0,
			pipeline: 0,
		}
		point.commit += Number(row.commit || 0)
		point.best_case += Number(row.best_case || 0)
		point.pipeline += Number(row.pipeline || 0)
		byDate.set(date, point)
	}
	return [...byDate.values()].sort((a, b) =>
		String(a.date).localeCompare(String(b.date)),
	)
}

/**
 * Keep each owner's newest snapshot, so a period that has been snapshotted
 * every week counts every owner once.
 *
 * @param {Array<object>} snapshots Rows with owner_id and as_of_date.
 * @return {Array<object>} One row per owner, in the order owners first appear.
 * @spec exclude forecast snapshots have no owning requirement, see
 *   ForecastSettings::load for the same gap
 */
export function latestPerOwner(snapshots) {
	const latest = new Map()
	for (const row of snapshots) {
		const current = latest.get(row.owner_id)
		if (!current || String(row.as_of_date) > String(current.as_of_date)) {
			latest.set(row.owner_id, row)
		}
	}
	return [...latest.values()]
}
