// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * Labels for stored enum values that pipelinq's own forms and pages show.
 *
 * The schema-driven pages read `x-enum-labels` from the register
 * (register.d/99-zz-readable-values.json). A few hand-written forms and the
 * service page print values themselves, and printed the stored code
 * (`person`, `normal`, `free`, `staff`). These maps hold the same English
 * labels as the register, and a test keeps the two equal.
 *
 * @spec openspec/changes/round4-readable-values-and-tour-titles/specs/client-management/spec.md
 */

/** Client type (client.type). */
export const CLIENT_TYPE_LABELS = {
	person: 'Person',
	organization: 'Organisation',
}

/** Lead priority (lead.priority). */
export const LEAD_PRIORITY_LABELS = {
	low: 'Low',
	normal: 'Normal',
	high: 'High',
	urgent: 'Urgent',
}

/** Service cancellation policy (appointmentService.cancellationPolicy). */
export const CANCELLATION_POLICY_LABELS = {
	free: 'Free',
	'charge-deposit': 'Charge deposit',
	'always-charge': 'Always charge',
}

/** Resource type (appointmentResource.type, a service step's resourceType). */
export const RESOURCE_TYPE_LABELS = {
	staff: 'Staff',
	room: 'Room',
	equipment: 'Equipment',
}

/**
 * CTI platform (ctiAdapterConfig.platform, ctiEventLog.platform). The schema
 * has no `x-enum-labels`; the vendor names stay as they are.
 */
export const CTI_PLATFORM_LABELS = {
	callvoip: 'CallVoip',
	ringcentral: 'RingCentral',
	asterisk: 'Asterisk',
	other: 'Other',
}

/** CTI webhook event type (ctiEventLog.event_type). */
export const CTI_EVENT_TYPE_LABELS = {
	ringing: 'Ringing',
	answered: 'Answered',
	ended: 'Ended',
	abandoned: 'Abandoned',
	transferred: 'Transferred',
	presence: 'Presence',
	recording: 'Recording',
}

/**
 * The translated label for a stored value.
 *
 * @param {object} labels One of the maps above.
 * @param {string} value The stored value.
 * @param {(text: string) => string} translate Translates an English source string.
 * @return {string} The label, or the value itself when it has none.
 * @spec openspec/changes/round4-readable-values-and-tour-titles/specs/client-management/spec.md
 */
export function enumLabel(labels, value, translate = (text) => text) {
	if (value === null || value === undefined || value === '') {
		return ''
	}
	return Object.hasOwn(labels, value) ? translate(labels[value]) : String(value)
}

/**
 * NcSelect options `{ value, label }` for every value of a map.
 *
 * @param {object} labels One of the maps above.
 * @param {(text: string) => string} translate Translates an English source string.
 * @return {Array<{value: string, label: string}>} The options, in map order.
 * @spec openspec/changes/round4-readable-values-and-tour-titles/specs/client-management/spec.md
 */
export function enumOptions(labels, translate = (text) => text) {
	return Object.keys(labels).map((value) => ({
		value,
		label: translate(labels[value]),
	}))
}

/** What a competitor watch reads (competitorWatch.kind, watchEvent.kind). */
export const WATCH_KIND_LABELS = {
	rss: 'Feed',
	sitemap: 'Sitemap',
	page: 'Page fragment',
	fediverse: 'Public timeline',
	search: 'Saved search',
}

/** How often a competitor watch runs (competitorWatch.schedule). */
export const WATCH_SCHEDULE_LABELS = {
	hourly: 'Every hour',
	daily: 'Every day',
	weekly: 'Every week',
}

/** The channel a campaign touchpoint or blast went out on (touchpoint.channel, blast.channel). */
export const CAMPAIGN_CHANNEL_LABELS = {
	email: 'Newsletter',
	sms: 'SMS',
	social: 'Social media',
	mastodon: 'Mastodon',
	bluesky: 'Bluesky',
	linkedin: 'LinkedIn',
	website: 'Website',
	direct: 'Direct',
}
