// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Object store plugin: after an edit of a client's or contact person's name,
 * email or phone, write the change back to the linked Nextcloud Contact
 * (pipelinq-audit-admin-forms-pos).
 *
 * The Nextcloud Contact is the authority for those fields. The edit dialogs
 * may change them, so the change has to reach the contact as well, or the
 * next sync from Contacts puts the old value back. Best-effort, like every
 * write-back: the record is already saved when this runs.
 *
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-client-edit-asks-for-name-and-email
 */

import { writeBack } from '../../services/contactSyncApi.js'

const PARTY_TYPES = ['client', 'contact']
const IDENTITY_FIELDS = ['name', 'email', 'phone']

/**
 * Whether a save is an edit of a party's identity fields.
 *
 * @param {string} type The object type.
 * @param {object} data The saved payload.
 * @return {boolean} True when the contact needs the change too.
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-client-edit-asks-for-name-and-email
 */
export function needsWriteBack(type, data) {
	if (!PARTY_TYPES.includes(type) || !data || !data.id) {
		return false
	}
	return IDENTITY_FIELDS.some((field) => field in data)
}

/**
 * The plugin.
 *
 * @param {(type: string, id: string) => Promise<string|null>} [write] The write-back call (injectable for tests).
 * @return {{name: string, setup: (store: object) => void}} The plugin.
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-client-edit-asks-for-name-and-email
 */
export function contactWriteBackPlugin(write = writeBack) {
	return {
		name: 'contactWriteBack',
		setup(store) {
			// Detached: the first store use may sit inside a component, and the
			// subscription must outlive that component.
			store.$onAction(({ name, args, after }) => {
				if (name !== 'saveObject' || !needsWriteBack(args[0], args[1])) {
					return
				}
				after((saved) => {
					if (saved) {
						write(args[0], String(args[1].id))
					}
				})
			}, true)
		},
	}
}
