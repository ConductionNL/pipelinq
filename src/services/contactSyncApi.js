// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Thin frontend API client for the contact-FIRST create orchestration.
// All endpoints are documented in lib/Controller/ContactSyncController.php.
//
// The `client`/`contact` schema marks `contactsUid` REQUIRED (the authoritative
// identity is the Nextcloud addressbook contact, never minted locally). A plain
// objectStore.saveObject('client', …) therefore 400s with
// "The required property (contactsUid) is missing". This helper posts the raw
// create-form fields to the backend, which provisions (resolves or creates) the
// NC contact via ContactVcardService and saves the object with the resolved
// contactsUid + the denormalised name/email/phone mirror.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/pipelinq' + path)

/**
 * Contact-FIRST create of a client or contact.
 *
 * @param {string} objectType The object type ('client' or 'contact').
 * @param {object} form The raw create-form fields (name/type/email/phone/...).
 * @return {Promise<object>} The created object (serialised by OpenRegister).
 * @throws {Error} With the backend message on a 400/500 (e.g. Contacts disabled).
 * @spec openspec/specs/unify-client-contact/spec.md
 */
export async function createWithContact(objectType, form) {
	const { data } = await axios.post(base('/api/contacts-sync/create'), {
		objectType,
		object: form,
	})
	return data.object
}

/**
 * Write-back sync of an existing client/contact to its linked Nextcloud
 * Contact vCard (contacts-sync spec, write-back requirement). Best-effort:
 * a failure here must never block the caller's own save flow, since the
 * Pipelinq object is already persisted by the time this runs.
 *
 * @param {string} objectType The object type ('client' or 'contact').
 * @param {string} objectId The saved object's id.
 * @return {Promise<string|null>} The contacts UID on success, or null.
 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md
 */
export async function writeBack(objectType, objectId) {
	try {
		const { data } = await axios.post(base('/api/contacts-sync/write-back'), {
			objectType,
			objectId,
		})
		return data?.contactsUid || null
	} catch {
		return null
	}
}
