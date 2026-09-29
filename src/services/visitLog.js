// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The two objects "Log a visit" writes: an outbound contact moment (a
 * `ticket` with ticketType `interaction` and channel `visit`) linked to the
 * client or lead, and, when a follow-up date is given, an open follow-up
 * task (`crmTask`) due on that day. The same paths the contact moment
 * quick log and the task list already use; no new schema or endpoint.
 *
 * Imports nothing, so it runs in the node test environment, where
 * tests/vitest/visitLog.spec.js validates both payloads against the real
 * schema fragments in lib/Settings.
 *
 * @spec openspec/changes/platform-phone-on-the-road/specs/mobile-experience/spec.md#requirement-a-visit-is-logged-in-one-small-sheet-req-mob-003
 */

/** The channel value a logged visit carries. */
export const VISIT_CHANNEL = 'visit'

/**
 * The deadline for a follow-up date picked as `YYYY-MM-DD`: that day at
 * 09:00 local time, as an ISO date-time. Null for an empty or invalid date.
 *
 * @param {string|null|undefined} day The picked day.
 * @return {string|null}
 * @spec openspec/changes/platform-phone-on-the-road/specs/mobile-experience/spec.md#requirement-a-visit-is-logged-in-one-small-sheet-req-mob-003
 */
export function followUpDeadline(day) {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(day ?? ''))
	if (!match) {
		return null
	}
	const date = new Date(
		Number(match[1]),
		Number(match[2]) - 1,
		Number(match[3]),
		9,
		0,
		0,
	)
	return Number.isNaN(date.getTime()) ? null : date.toISOString()
}

/**
 * Build the payloads for one logged visit.
 *
 * @param {object} input What the sheet collected.
 * @param {string} input.note The one-line note.
 * @param {string} [input.followUpDate] Optional `YYYY-MM-DD` follow-up day.
 * @param {string} [input.clientId] The client visited.
 * @param {string} [input.leadId] The lead the visit was about.
 * @param {string} [input.userId] The Nextcloud user logging it.
 * @param {string} input.title Title for the contact moment ("Visit").
 * @param {string} input.taskSubject Subject for the follow-up task.
 * @param {Date} [input.now] The moment of logging.
 * @return {{ticket: object, task: (object|null)}}
 * @spec openspec/changes/platform-phone-on-the-road/specs/mobile-experience/spec.md#requirement-a-visit-is-logged-in-one-small-sheet-req-mob-003
 */
export function buildVisitPayloads(input) {
	const note = String(input.note ?? '').trim()
	const ticket = {
		ticketType: 'interaction',
		title: input.title,
		channel: VISIT_CHANNEL,
		direction: 'outbound',
		occurredAt: (input.now ?? new Date()).toISOString(),
		description: note,
	}
	if (input.userId) ticket.assignee = input.userId
	if (input.clientId) ticket.client = input.clientId
	if (input.leadId) ticket.lead = input.leadId

	const deadline = followUpDeadline(input.followUpDate)
	let task = null
	if (deadline) {
		task = {
			type: 'followUpTask',
			subject: input.taskSubject,
			description: note,
			status: 'open',
			priority: 'normal',
			deadline,
			contactMomentSummary: note,
		}
		if (input.userId) {
			task.assigneeUserId = input.userId
			task.createdBy = input.userId
		}
		if (input.clientId) task.clientId = input.clientId
		if (input.leadId) task.lead = input.leadId
	}
	return { ticket, task }
}
