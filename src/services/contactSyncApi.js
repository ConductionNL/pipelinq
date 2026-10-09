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
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
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
 * Contact vCard (contacts-sync spec, write-back requirement). A failure never
 * blocks the caller's own save flow, since the Pipelinq object is already
 * persisted by the time this runs. It is no longer silent either: the user is
 * told that Nextcloud Contacts still has the old details (round4, item 1).
 *
 * @param {string} objectType The object type ('client' or 'contact').
 * @param {string} objectId The saved object's id.
 * @param {(message: string) => void} [notify] Shows the failure (injectable for tests).
 * @return {Promise<string|null>} The contacts UID on success, or null.
 * @spec openspec/changes/round4-contact-write-back/specs/contacts-sync/spec.md#requirement-a-failed-write-back-is-shown-to-the-user
 */
export async function writeBack(objectType, objectId, notify = showError) {
	let uid
	try {
		const { data } = await axios.post(base('/api/contacts-sync/write-back'), {
			objectType,
			objectId,
		})
		uid = data?.contactsUid || null
	} catch {
		uid = null
	}
	if (uid === null) {
		notify(
			t(
				'pipelinq',
				'Your changes are saved here, but not in Nextcloud Contacts. The contact there still has the old details.',
			),
		)
	}
	return uid
}
