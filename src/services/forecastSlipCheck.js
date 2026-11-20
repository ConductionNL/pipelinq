// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Whether a lead edit moves a commit or best-case deal's expected close date
 * out of its forecast period, so the form can ask for a slip reason before it
 * saves (REQ-FRC-012). It mirrors the server's `ForecastSlipRule`: the period
 * is the calendar quarter of the OLD close date, and only an open deal in
 * `commit` or `best_case` needs a reason. The server stays the authority: it
 * undoes a slip saved without a reason. This check only spares the seller that
 * round trip.
 */

/** The forecast categories whose slips need a reason. */
export const SLIP_CATEGORIES = ['commit', 'best_case']

/** The shortest slip reason the server accepts (JUSTIFICATION_MIN_LENGTH). */
export const SLIP_REASON_MIN_LENGTH = 10

/** Lead statuses that are no longer open. */
const CLOSED_STATUSES = ['won', 'lost']

/**
 * The calendar day of a close date value.
 *
 * @param {string|Date|null|undefined} value A `Y-m-d` date, a date-time string or a Date.
 * @return {{year: number, month: number, day: number}|null} The day, or null when it is no date.
 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
 */
export function closeDay(value) {
	let text = value
	if (value instanceof Date) {
		if (Number.isNaN(value.getTime())) {
			return null
		}
		text = `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`
	}
	const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(text ?? '').trim())
	if (!match) {
		return null
	}
	const year = Number(match[1])
	const month = Number(match[2])
	const day = Number(match[3])
	const probe = new Date(Date.UTC(year, month - 1, day))
	if (probe.getUTCMonth() !== month - 1 || probe.getUTCDate() !== day) {
		return null
	}
	return { year, month, day }
}

/**
 * A comparable number for a calendar day (`YYYYMMDD`).
 *
 * @param {{year: number, month: number, day: number}} d The day.
 * @return {number} The day as one number.
 */
function dayNumber(d) {
	return d.year * 10000 + d.month * 100 + d.day
}

/**
 * The last day of the calendar quarter a day falls in.
 *
 * @param {{year: number, month: number, day: number}} d The day.
 * @return {{year: number, month: number, day: number}} The quarter's last day.
 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
 */
export function quarterEnd(d) {
	const endMonth = Math.ceil(d.month / 3) * 3
	const lastDay = new Date(Date.UTC(d.year, endMonth, 0)).getUTCDate()
	return { year: d.year, month: endMonth, day: lastDay }
}

/**
 * Whether saving `changes` over `lead` slips the deal out of its period.
 * A field the form did not send keeps the lead's stored value.
 *
 * @param {object|null} lead The lead as stored.
 * @param {object|null} changes The form's values.
 * @return {boolean} True when the save needs a slip reason.
 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
 */
export function slipsOutOfPeriod(lead, changes) {
	const before = lead || {}
	const after = { ...before, ...(changes || {}) }
	if (!SLIP_CATEGORIES.includes(after.forecast_category)) {
		return false
	}
	if (CLOSED_STATUSES.includes(after.status)) {
		return false
	}
	const oldClose = closeDay(before.expectedCloseDate)
	const newClose = closeDay(after.expectedCloseDate)
	if (!oldClose || !newClose) {
		return false
	}
	return dayNumber(newClose) > dayNumber(quarterEnd(oldClose))
}

/**
 * Whether a slip reason is long enough for the server.
 *
 * @param {string|null|undefined} reason The reason as typed.
 * @return {boolean} True when it has at least SLIP_REASON_MIN_LENGTH characters.
 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
 */
export function isSlipReasonValid(reason) {
	return String(reason ?? '').trim().length >= SLIP_REASON_MIN_LENGTH
}

/**
 * Whether the save must stop for a reason: it slips, and the values carry no
 * valid reason yet.
 *
 * @param {object|null} lead The lead as stored.
 * @param {object|null} changes The form's values.
 * @return {boolean} True when the reason dialog must open.
 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
 */
export function needsSlipReason(lead, changes) {
	return slipsOutOfPeriod(lead, changes) && !isSlipReasonValid(changes?.slip_reason)
}
