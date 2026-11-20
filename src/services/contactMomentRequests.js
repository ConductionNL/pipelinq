// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The requests a contact moment can be filed on: the open requests of the
 * chosen client. A closed request takes no new contact moments, and another
 * client's request is not this conversation's.
 *
 * @spec openspec/changes/specify-capability-rows/specs/contactmomenten/spec.md#requirement-a-contact-moment-is-filed-on-an-open-request
 */

import { isTerminalStatus } from './requestStatus.js'

/**
 * The id a reference names, whether it is stored as an id or as an object.
 *
 * @param {string|object|null|undefined} reference The reference.
 * @return {string} The id, or ''.
 */
function idOf(reference) {
	if (reference && typeof reference === 'object') {
		return String(reference.id || reference['@self']?.id || '')
	}
	return reference ? String(reference) : ''
}

/**
 * The picker options for the Request field.
 *
 * A request without a status is new, so open. Without a chosen client every
 * open request is offered. The request already picked stays in the list, so
 * the field never shows an id it cannot name.
 *
 * @param {Array<object>} requests The request-type tickets.
 * @param {string|object|null} client The chosen client.
 * @param {string|null} picked The request already picked.
 * @return {Array<{value: string, label: string}>} The options.
 * @spec openspec/changes/specify-capability-rows/specs/contactmomenten/spec.md#requirement-a-contact-moment-is-filed-on-an-open-request
 */
export function openRequestOptions(requests, client = null, picked = null) {
	const clientId = idOf(client)
	return (Array.isArray(requests) ? requests : [])
		.filter((request) => request && request.id)
		.filter((request) => {
			if (picked && request.id === picked) {
				return true
			}
			if (isTerminalStatus(request.status || 'new')) {
				return false
			}
			return clientId === '' || idOf(request.client) === clientId
		})
		.map((request) => ({ value: request.id, label: request.title || request.id }))
}
