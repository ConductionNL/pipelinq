// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Display labels for the stored lead `source` values.
 *
 * The lead schema keeps `source` as a free string. The values the app itself
 * writes are: the default lead source tags (lib/Repair/InitializeSettings.php
 * DEFAULT_LEAD_SOURCES), the options of the create-lead widget, the
 * `landing-page` value of the example data, and the `unknown` bucket the
 * analytics service (RapportageService::getSourcePerformance) puts a lead
 * without a source in. Those get a translated label here. Anything else is a
 * name an admin typed as a lead source tag and is shown as typed.
 *
 * The stored values never change: this only maps them for display.
 *
 * Tender and event get their own English keys ("Public tender", "Event or
 * fair") because "Tender" and "Event" already carry other meanings in the
 * app's translations (a payment tender, a calendar event).
 *
 * @spec openspec/specs/lead-management/spec.md
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * @return {Record<string, string>} Stored source value to its label.
 */
function labels() {
	return {
		website: t('pipelinq', 'Website'),
		email: t('pipelinq', 'Email'),
		phone: t('pipelinq', 'Phone'),
		referral: t('pipelinq', 'Referral'),
		partner: t('pipelinq', 'Partner'),
		campaign: t('pipelinq', 'Campaign'),
		social_media: t('pipelinq', 'Social media'),
		event: t('pipelinq', 'Event or fair'),
		tender: t('pipelinq', 'Public tender'),
		other: t('pipelinq', 'Other'),
		'cold-call': t('pipelinq', 'Cold call'),
		advertisement: t('pipelinq', 'Advertisement'),
		'landing-page': t('pipelinq', 'Landing page'),
		unknown: t('pipelinq', 'No source'),
	}
}

/**
 * The stored source values the app knows a label for, in the order the
 * create-lead widget and the defaults list them.
 */
export const KNOWN_LEAD_SOURCES = Object.freeze(Object.keys(labels()))

/**
 * The display label of a stored lead source value.
 *
 * @param {string|null|undefined} value The stored `source` value.
 * @return {string} The translated label, or the value itself when the app
 *   does not know it (an admin-made source tag).
 */
export function leadSourceLabel(value) {
	if (value === null || value === undefined) {
		return ''
	}
	const key = String(value)
	const map = labels()
	return Object.hasOwn(map, key) ? map[key] : key
}
