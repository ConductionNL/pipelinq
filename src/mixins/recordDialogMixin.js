// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The form dialog of an admin record section: open a record or an empty
 * form, and on close re-render the list so a save or delete shows up.
 *
 * The admin page is its own webpack entry with no vue-router, so a section's
 * list emits instead of routing to a detail page and the form opens in a
 * dialog. The host binds `dialogOpen`, `editingId` and `reloadKey`.
 *
 * @spec openspec/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
 */
export default {
	data() {
		return {
			dialogOpen: false,
			editingId: '',
			// Bumped on close so the list refetches and shows the saved row.
			reloadKey: 0,
		}
	},

	methods: {
		/**
		 * Open an existing record in the dialog.
		 *
		 * @param {string} id The record uuid.
		 * @spec openspec/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		openRecord(id) {
			this.editingId = id
			this.dialogOpen = true
		},

		/**
		 * Open an empty form.
		 *
		 * @spec openspec/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		openNew() {
			this.editingId = ''
			this.dialogOpen = true
		},

		/**
		 * Close the dialog and re-render the list so a save or delete shows up.
		 *
		 * @spec openspec/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		closeDialog() {
			this.dialogOpen = false
			this.editingId = ''
			this.reloadKey++
		},
	},
}
