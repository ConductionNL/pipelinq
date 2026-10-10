/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The library's object-list widget, with a create that provisions the
 * Nextcloud contact first.
 *
 * The `client` and `contact` schemas mark `contactsUid` required: the
 * addressbook card is the identity and the object mirrors it. The library's
 * CnObjectListWidget saves a new row with a plain POST to OpenRegister, so
 * "Add contact person" on a client's Contacts tab came back 400 "The required
 * property (contactsUid) is missing" (round-4 cloud check, item 1). This
 * widget keeps everything the library widget draws and only changes where a
 * create goes: through POST /api/contacts-sync/create, the same path the
 * Clients and Contacts index pages already use. Other schemas fall through
 * to the library's own create.
 *
 * @spec openspec/changes/round5-contact-create-and-task-links/specs/client-management/spec.md#requirement-a-contact-person-added-on-a-client-page-is-created
 */

import { CnObjectListWidget } from '@conduction/nextcloud-vue'
import { createWithContact } from '../../services/contactSyncApi.js'

/** Schemas whose objects need a provisioned addressbook contact. */
export const CONTACT_BACKED_SCHEMAS = ['client', 'contact']

/**
 * The create payload: the form values, with the list's scalar filter values
 * as defaults. The same merge the library widget does, so a contact person
 * added on a client page arrives linked to that client.
 *
 * @param {object} formData The confirmed form values.
 * @param {object} filter The list's resolved filter.
 * @return {object} The payload.
 * @spec openspec/changes/round5-contact-create-and-task-links/specs/client-management/spec.md#requirement-a-contact-person-added-on-a-client-page-is-created
 */
export function createPayload(formData, filter) {
	const payload = { ...(formData || {}) }
	for (const [key, value] of Object.entries(filter || {})) {
		const empty =
			payload[key] === undefined
			|| payload[key] === null
			|| payload[key] === ''
		if (value && typeof value !== 'object' && empty) {
			payload[key] = value
		}
	}
	return payload
}

/**
 * The message a failed create shows: the backend's own sentence when it sent
 * one, so the dialog names the cause instead of "Request failed with status
 * code 400".
 *
 * @param {Error} error The thrown error.
 * @return {string} The message.
 * @spec openspec/changes/round5-contact-create-and-task-links/specs/client-management/spec.md#requirement-a-contact-person-added-on-a-client-page-is-created
 */
export function createErrorMessage(error) {
	const data = error?.response?.data
	return data?.error || data?.message || error?.message || 'error'
}

export default {
	name: 'ContactAwareObjectListWidget',
	extends: CnObjectListWidget,

	methods: {
		/**
		 * Persist the create dialog. A client or contact goes through the
		 * contact-first create; anything else keeps the library's create.
		 *
		 * @param {object} formData The confirmed form values.
		 * @return {Promise<void>}
		 * @spec openspec/changes/round5-contact-create-and-task-links/specs/client-management/spec.md#requirement-a-contact-person-added-on-a-client-page-is-created
		 */
		async onCreateConfirm(formData) {
			const schema = this.content?.schema
			if (!CONTACT_BACKED_SCHEMAS.includes(schema)) {
				return CnObjectListWidget.methods.onCreateConfirm.call(
					this,
					formData,
				)
			}
			const payload = createPayload(formData, this.resolvedFilter)
			const dialog = this.$refs.createDialog
			try {
				await createWithContact(schema, payload)
			} catch (e) {
				if (dialog) {
					dialog.setResult({ error: createErrorMessage(e) })
				}
				return
			}
			if (dialog) {
				dialog.setResult({ success: true })
			}
			this.$emit('created', payload)
			this.fetchRows()
		},
	},
}
