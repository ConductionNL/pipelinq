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
 */
export async function createWithContact(objectType, form) {
	const { data } = await axios.post(base('/api/contacts-sync/create'), {
		objectType,
		object: form,
	})
	return data.object
}
