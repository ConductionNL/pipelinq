// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Mailing-list subscription state, as the interface reads it.
 *
 * Pure and dependency-free, so it can be unit-tested offline the way
 * `posTotals.js` and `complaintStatus.js` are. Three jobs:
 *
 *   • map a stored `state` value to the chip a row renders,
 *   • reduce a page of rows to the per-state counts a list header shows,
 *   • build the embed snippet the admin settings hand a marketer.
 *
 * The chip vocabulary lives here rather than in the component because the
 * same four states are rendered in two places — the list detail page and the
 * Subscriptions section on a contact — and a state that means "not reachable"
 * in one place must not look reachable in the other.
 *
 * Colours are Nextcloud CSS variables, never literals, per the app's styling
 * rule.
 */

/** Every state the `subscription` schema declares, in lifecycle order. */
export const SUBSCRIPTION_STATES = [
	'pending',
	'confirmed',
	'unsubscribed',
	'bounced',
]

const CHIPS = {
	pending: {
		label: 'Awaiting confirmation',
		color: 'var(--color-warning)',
		reachable: false,
	},
	confirmed: {
		label: 'Subscribed',
		color: 'var(--color-success)',
		reachable: true,
	},
	unsubscribed: {
		label: 'Unsubscribed',
		color: 'var(--color-text-maxcontrast)',
		reachable: false,
	},
	bounced: {
		label: 'Bounced',
		color: 'var(--color-error)',
		reachable: false,
	},
}

/**
 * The chip a subscription row renders for its state.
 *
 * An unknown state is NOT treated as reachable. A state this build has never
 * heard of can only come from a newer schema, and guessing that a stranger is
 * mailable is the one guess with a legal consequence.
 *
 * @param {string} state The stored `state` value.
 * @return {{label: string, color: string, reachable: boolean}} The chip.
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
 */
export function chipForState(state) {
	const chip = CHIPS[state]
	if (chip) {
		return { ...chip }
	}
	return {
		label: 'Unknown',
		color: 'var(--color-text-maxcontrast)',
		reachable: false,
	}
}

/**
 * Whether a subscription in this state may receive a mailing.
 *
 * @param {string} state The stored `state` value.
 * @return {boolean} True only for a confirmed membership.
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
 */
export function isReachable(state) {
	return chipForState(state).reachable
}

/**
 * Reduce rows to per-state counts plus a total.
 *
 * Every declared state is present in the result even at zero, so a header
 * built from this does not change shape as a list fills up.
 *
 * @param {Array<object>} rows Subscription payloads.
 * @return {object} `{pending, confirmed, unsubscribed, bounced, total}`.
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
 */
export function countByState(rows) {
	const counts = { total: 0 }
	for (const state of SUBSCRIPTION_STATES) {
		counts[state] = 0
	}
	if (!Array.isArray(rows)) {
		return counts
	}
	for (const row of rows) {
		counts.total += 1
		const state = row && row.state
		if (Object.hasOwn(counts, state)) {
			counts[state] += 1
		}
	}
	return counts
}

/**
 * Build the signup-form snippet a marketer pastes into a page.
 *
 * The form posts straight at the public subscribe endpoint, so it needs no
 * script and no CORS. The honeypot input is present, empty and hidden from
 * assistive tech: a person never sees it and a form-filling bot does.
 *
 * @param {string} baseUrl The instance base URL, without a trailing slash.
 * @param {string} listId The mailing list id.
 * @return {string} The snippet, or an empty string when either input is missing.
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */
export function embedSnippet(baseUrl, listId) {
	if (!baseUrl || !listId) {
		return ''
	}
	const action = `${String(baseUrl).replace(/\/+$/, '')}/index.php/apps/pipelinq/api/lists/${encodeURIComponent(listId)}/subscribe`
	return [
		`<form method="post" action="${action}">`,
		'  <label for="pipelinq-email">Email</label>',
		'  <input id="pipelinq-email" type="email" name="email" required>',
		'  <input type="text" name="website" tabindex="-1" autocomplete="off"',
		'         aria-hidden="true" style="position:absolute;left:-9999px">',
		'  <button type="submit">Subscribe</button>',
		'</form>',
	].join('\n')
}
