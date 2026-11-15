// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the create dialogs (client, lead, request) share: the form's
 * validity and the saving flag, and opening the created record.
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
 */
export default {
	data() {
		return {
			valid: false,
			saving: false,
		}
	},

	methods: {
		/**
		 * Navigate to the created record and close.
		 *
		 * A registry modal is mounted by CnAppRoot, which forwards only
		 * `close` — there is no parent to route on the dialog's behalf the way
		 * the old bespoke header-actions component did.
		 *
		 * @param {string} route The detail route name.
		 * @param {string} id The created object's id.
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		goToDetail(route, id) {
			this.$emit('close')
			if (id && this.$router) {
				this.$router.push({ name: route, params: { id } }).catch(() => {})
			}
		},
	},
}
