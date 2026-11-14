// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The busy figures and the matching-resource line of the appointment
 * services and resources (services-and-resources-at-a-glance). The numbers
 * come from the server (BookingFiguresService, EligibilityService); this
 * module only turns them into what the pages show.
 *
 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-each-service-shows-the-resources-that-match-it-req-srg-002
 */

import axios from '@nextcloud/axios'
import * as l10n from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { reactive } from 'vue'
import { PENDING_LABEL } from './nameFormatters.js'

// Read through the namespace when called, not bound at import: a module
// that imports this one (App.vue, through its formatters) then loads with an
// l10n stub that only provides `translate`.
const t = (app, text, vars) => l10n.translate(app, text, vars)
const n = (app, one, many, count) => l10n.translatePlural(app, one, many, count)

/** What a required resource type reads as in the match line. */
const RESOURCE_TYPE_PHRASES = {
	staff: () => t('pipelinq', 'a staff member'),
	room: () => t('pipelinq', 'a room'),
	equipment: () => t('pipelinq', 'equipment'),
}

/**
 * The resource page's week figure from the server's answer.
 *
 * @param {?object} figures `{ bookingsThisWeek, bookedShare }`; anything else reads as nothing.
 * @return {{count: number, percent: (number|null)}} `percent` is null without bookable time.
 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-a-resource-page-shows-how-much-of-its-bookable-time-is-booked-req-srg-004
 */
export function weekLoadOf(figures) {
	const count = Number(figures?.bookingsThisWeek)
	const share = figures?.bookedShare
	return {
		count: Number.isFinite(count) ? count : 0,
		percent: typeof share === 'number' && Number.isFinite(share) ? share : null,
	}
}

/**
 * What a service asks for: "Skill civil affairs, a room". Empty when it asks
 * for nothing.
 *
 * @param {?object} service The service, with `requiredSkills` and `requiredResourceTypes`.
 * @return {string} The requirement.
 */
export function requirementText(service) {
	const skills = stringsOf(service?.requiredSkills)
	const parts = []
	if (skills.length === 1) {
		parts.push(t('pipelinq', 'Skill {skill}', { skill: skills[0] }))
	} else if (skills.length > 1) {
		parts.push(t('pipelinq', 'Skills {skills}', { skills: skills.join(', ') }))
	}
	for (const type of stringsOf(service?.requiredResourceTypes)) {
		parts.push(RESOURCE_TYPE_PHRASES[type] ? RESOURCE_TYPE_PHRASES[type]() : type)
	}
	return parts.join(', ')
}

/**
 * The services list's match line: what the service asks for and how many
 * active, bookable resources meet it, "Skill civil affairs, a room · 4
 * resources match", or "No resource matches".
 *
 * @param {?object} service The service.
 * @param {number} count The number of matching resources.
 * @return {string} The line.
 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-each-service-shows-the-resources-that-match-it-req-srg-002
 */
export function matchLine(service, count) {
	const matches = count > 0
		? n('pipelinq', '%n resource matches', '%n resources match', count)
		: t('pipelinq', 'No resource matches')
	const requirement = requirementText(service)
	return requirement === '' ? matches : `${requirement} · ${matches}`
}

/**
 * A table cell formatter for the services list's match line. The counts of
 * every service are read in one request when the first cell asks, and again
 * once they are older than `maxAgeMs`; until they are in, a cell shows the
 * pending mark.
 *
 * @param {object} [options] Options.
 * @param {() => Promise<Record<string, number>>} [options.fetchCounts] Reads the count per service id.
 * @param {number} [options.maxAgeMs] How long a read stays fresh.
 * @param {() => number} [options.clock] The current time in ms.
 * @return {(value: unknown, row?: object) => string} The formatter.
 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-each-service-shows-the-resources-that-match-it-req-srg-002
 */
export function createMatchLineFormatter({ fetchCounts = fetchMatchCounts, maxAgeMs = 60000, clock = Date.now } = {}) {
	const state = reactive({ counts: null, readAt: 0, reading: false })

	const read = () => {
		state.reading = true
		fetchCounts()
			.then((counts) => {
				state.counts = counts && typeof counts === 'object' ? counts : {}
			})
			.catch(() => {
				state.counts = state.counts || {}
			})
			.finally(() => {
				state.readAt = clock()
				state.reading = false
			})
	}

	return (_value, row) => {
		if (!state.reading && (state.counts === null || clock() - state.readAt > maxAgeMs)) {
			read()
		}
		if (state.counts === null) {
			return PENDING_LABEL
		}
		const id = rowId(row)
		return matchLine(row, Number(state.counts[id]) || 0)
	}
}

/**
 * The matching-resource count of every service, from the server.
 *
 * @return {Promise<Record<string, number>>} The count per service id.
 */
export async function fetchMatchCounts() {
	const { data } = await axios.get(generateUrl('/apps/pipelinq/api/appointment-services/eligible-resources'))
	return data?.counts || {}
}

/**
 * @param {?object} row A list row.
 * @return {string} Its id: `id`, `uuid` or `@self.id`.
 */
function rowId(row) {
	return String(row?.id ?? row?.uuid ?? row?.['@self']?.id ?? '')
}

/**
 * @param {unknown} value A list of strings, or anything else.
 * @return {string[]} Its non-empty trimmed strings.
 */
function stringsOf(value) {
	if (!Array.isArray(value)) {
		return []
	}
	return value.filter((entry) => typeof entry === 'string' && entry.trim() !== '').map((entry) => entry.trim())
}
