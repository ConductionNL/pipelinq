// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The calls behind the client lookups of the new request dialog (board
 * PqNieuwVerzoek), kept apart from the form so the form stays free of
 * transport.
 *
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { createWithContact } from './contactSyncApi.js'

/**
 * Whether the session user may look up in the BRP. Any failure reads as no:
 * the pill is then not offered, and the server checks again on every lookup.
 *
 * @param {object} [http] An axios-like client.
 * @return {Promise<boolean>} True only when the server says so.
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
export async function fetchBrpAccess(http = axios) {
	try {
		const { data } = await http.get(generateUrl('/apps/pipelinq/api/brp/access'))
		return data?.allowed === true
	} catch {
		return false
	}
}

/**
 * Create a client from the fields a lookup made, through the contact-first
 * create every client takes.
 *
 * @param {object} fields The client fields.
 * @param {(type: string, form: object) => Promise<object>} [create] The create call.
 * @return {Promise<object|null>} The created client with its id, or null.
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
export async function createLookedUpClient(fields, create = createWithContact) {
	const created = await create('client', fields)
	const id = created?.id ?? created?.['@self']?.id
	return id ? { ...fields, ...created, id } : null
}

/**
 * Tell the handler the found client could not be used.
 *
 * @param {string} message The message.
 * @return {void}
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
export function lookupFailed(message) {
	showError(message)
}
