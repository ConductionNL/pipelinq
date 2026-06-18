/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Contact-FIRST client create helper (client-contact unification).
 *
 * The `client` schema marks `contactsUid` — the foreign key to the authoritative
 * Nextcloud addressbook contact — REQUIRED, but no create form can supply it, so
 * a raw `objectStore.saveObject('client', form)` is rejected 400 by OpenRegister
 * ("The required property (contactsUid) is missing"). This helper posts the
 * create-form fields to the backend, which provisions (resolves or creates) the
 * NC contact, then saves the client with `contactsUid` + the denormalised
 * identity mirror. The Nextcloud Contact stays authoritative; `contactsUid` is
 * never minted locally.
 *
 * @spec openspec/changes/pipelinq-unify-client-contact/specs/unify-client-contact/spec.md#REQ-PUCC-003
 */

/**
 * Create a client (or contact) through the contact-aware backend endpoint.
 *
 * @param {Function} generateUrl  The @nextcloud/router generateUrl function.
 * @param {object}   formData     The create-form fields (name/type/email/phone/...).
 * @param {string}   [objectType] The object type ('client' or 'contact'). Defaults to 'client'.
 * @return {Promise<object>} The created object on success.
 * @throws {Error} With a user-facing message on failure.
 */
export async function createClientWithContact(generateUrl, formData, objectType = 'client') {
	let response
	try {
		response = await fetch(generateUrl('/apps/pipelinq/api/contacts-sync/create'), {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
				'OCS-APIREQUEST': 'true',
			},
			body: JSON.stringify({ objectType, object: formData }),
		})
	} catch (e) {
		throw new Error(t('pipelinq', 'Failed to create client. Please try again.'))
	}

	let body = {}
	try {
		body = await response.json()
	} catch {
		body = {}
	}

	if (!response.ok || !body.object) {
		throw new Error(body.error || t('pipelinq', 'Failed to create client.'))
	}

	return body.object
}
