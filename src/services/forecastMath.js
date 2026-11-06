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
			closed_won: 0,
		}
		point.commit += Number(row.commit || 0)
		point.best_case += Number(row.best_case || 0)
		point.pipeline += Number(row.pipeline || 0)
		point.closed_won += Number(row.closed_won || 0)
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

/**
 * The quarter before a `Q<n>-<year>` period id ("Q1-2027" gives "Q4-2026").
 *
 * @param {string} periodId The period id.
 * @return {string} The previous period id, or '' for an id it cannot read.
 * @spec openspec/changes/forecast-accuracy-rows/specs/forecasting/spec.md#requirement-the-forecast-shows-last-quarters-accuracy-per-seller
 */
export function previousPeriodId(periodId) {
	const match = /^Q([1-4])-(\d{4})$/.exec(String(periodId || ''))
	if (!match) {
		return ''
	}
	const quarter = Number(match[1])
	const year = Number(match[2])
	return quarter === 1 ? 'Q4-' + (year - 1) : 'Q' + (quarter - 1) + '-' + year
}

/**
 * Forecast accuracy per seller for a closed period: the commit of the
 * seller's first snapshot against the closed-won amount of the last one. A
 * seller with a single snapshot, or no commit in the first one, has no score.
 *
 * @param {Array<object>} snapshots The period's rows (owner_id, as_of_date, commit, closed_won).
 * @return {Array<{owner_id: string, weekOneCommit: number, actual: number, score: number}>} One row per seller, in the order sellers first appear.
 * @spec openspec/changes/forecast-accuracy-rows/specs/forecasting/spec.md#requirement-the-forecast-shows-last-quarters-accuracy-per-seller
 */
export function accuracyRows(snapshots) {
	const byOwner = new Map()
	for (const row of snapshots || []) {
		if (!row || !row.owner_id) {
			continue
		}
		const rows = byOwner.get(row.owner_id) || []
		rows.push(row)
		byOwner.set(row.owner_id, rows)
	}
	const out = []
	for (const [owner, rows] of byOwner) {
		if (rows.length < 2) {
			continue
		}
		const sorted = [...rows].sort((a, b) => String(a.as_of_date).localeCompare(String(b.as_of_date)))
		const weekOneCommit = Number(sorted[0].commit || 0)
		if (!(weekOneCommit > 0)) {
			continue
		}
		const actual = Number(sorted[sorted.length - 1].closed_won || 0)
		out.push({ owner_id: owner, weekOneCommit, actual, score: actual / weekOneCommit })
	}
	return out
}

/**
 * The ISO week number of a date ("2026-10-05" is week 41).
 *
 * @param {string} date An ISO date (YYYY-MM-DD).
 * @return {number|string} The week, or '' when the date does not parse.
 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
 */
export function isoWeekOf(date) {
	const day = new Date(String(date) + 'T12:00:00Z')
	if (Number.isNaN(day.getTime())) {
		return ''
	}
	const thursday = new Date(day)
	thursday.setUTCDate(day.getUTCDate() + 3 - ((day.getUTCDay() + 6) % 7))
	const firstThursday = new Date(Date.UTC(thursday.getUTCFullYear(), 0, 4))
	firstThursday.setUTCDate(firstThursday.getUTCDate() + 3 - ((firstThursday.getUTCDay() + 6) % 7))
	return 1 + Math.round((thursday - firstThursday) / (7 * 24 * 3600 * 1000))
}

/**
 * How each seller's commit moved between the two newest snapshot dates:
 * into commit when it rose, out of it when it fell, with the amount. Sellers
 * whose commit did not move are left out; the largest move comes first.
 *
 * @param {Array<object>} snapshots Rows with owner_id, as_of_date and commit.
 * @return {Array<{owner_id: string, direction: 'into'|'out', amount: number}>} The moves.
 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
 */
export function commitMovements(snapshots) {
	const dates = [...new Set(snapshots.map((row) => String(row.as_of_date || '')).filter(Boolean))].sort()
	if (dates.length < 2) {
		return []
	}
	const [previous, current] = dates.slice(-2)
	const commitOn = (date) => {
		const byOwner = new Map()
		for (const row of snapshots) {
			if (String(row.as_of_date) === date) {
				byOwner.set(row.owner_id, (byOwner.get(row.owner_id) || 0) + Number(row.commit || 0))
			}
		}
		return byOwner
	}
	const before = commitOn(previous)
	const after = commitOn(current)
	const owners = new Set([...before.keys(), ...after.keys()])
	const moves = []
	for (const owner of owners) {
		const change = (after.get(owner) || 0) - (before.get(owner) || 0)
		if (change !== 0) {
			moves.push({ owner_id: owner, direction: change > 0 ? 'into' : 'out', amount: Math.abs(change) })
		}
	}
	return moves.sort((a, b) => b.amount - a.amount)
}

