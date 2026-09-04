// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Thin client for the mailing-list endpoints the interface calls.
 *
 * Kept apart from the components so the Subscriptions section can be bound to
 * either a list or a contact without holding two different fetch shapes, and
 * so the endpoint paths live in one place rather than in every caller.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The memberships of one mailing list, with per-state counts.
 *
 * @param {string} listId The mailing list id.
 * @return {Promise<{data: Array<object>, counts: object}>} The envelope.
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
 */
export async function fetchListSubscriptions(listId) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/mailing-lists/${listId}/subscriptions`),
	)
	return data
}

/**
 * Every membership one contact holds.
 *
 * @param {string} contactId The contact id.
 * @return {Promise<Array<object>>} The subscriptions.
 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
 */
export async function fetchContactSubscriptions(contactId) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/contacts/${contactId}/subscriptions`),
	)
	return data.subscriptions || []
}

/**
 * Take a contact off one list, or off every list when no list is named.
 *
 * @param {string} contactId The contact id.
 * @param {string} listId The mailing list id, or an empty string for all.
 * @param {string} reason What the subscriber gave as the reason.
 * @return {Promise<object>} `{count}`.
 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
 */
export async function unsubscribeContact(contactId, listId = '', reason = '') {
	const { data } = await axios.post(
		generateUrl('/apps/pipelinq/api/subscriptions/unsubscribe'),
		{ contactId, listId, reason },
	)
	return data
}

/**
 * Import an existing customer onto a soft opt-in list.
 *
 * @param {object} payload The import: listId, contactId, email and the
 *                         objection evidence.
 * @return {Promise<object>} `{status}`.
 * @spec openspec/specs/marketing-lists/spec.md#requirement-soft-opt-in-records-its-ground-and-the-objection-offered
 */
export async function importSoftOptIn(payload) {
	const { data } = await axios.post(
		generateUrl('/apps/pipelinq/api/subscriptions/soft-opt-in'),
		payload,
	)
	return data
}

/**
 * Mint a preference-centre link for one contact.
 *
 * @param {string} contactId The contact id.
 * @return {Promise<string>} The absolute URL.
 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
 */
export async function fetchPreferenceLink(contactId) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/contacts/${contactId}/preference-link`),
	)
	return data.url || ''
}

/**
 * Every mailing list, for a picker.
 *
 * @return {Promise<Array<object>>} The lists.
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
 */
export async function fetchMailingLists() {
	const { data } = await axios.get(generateUrl('/apps/pipelinq/api/mailing-lists'))
	return data.data || []
}
