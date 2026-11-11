// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the admin sections that edit their rows in place share: the row being
 * edited and its form copy, closing that form, and the row the delete dialog
 * asks about.
 *
 * The section opens the form itself (`startEdit`), because what a row's form
 * copy holds differs per section.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-23
 */
export default {
	data() {
		return {
			// The row the delete dialog asks about.
			deleting: null,
			editingId: null,
			editForm: {},
		}
	},

	methods: {
		/**
		 * Close the row's form and drop its copy.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-3
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-23
		 */
		cancelEdit() {
			this.editingId = null
			this.editForm = {}
		},

		/**
		 * Ask before deleting a row.
		 *
		 * @param {object} row The row to delete.
		 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-a-delete-on-the-admin-page-asks-in-a-dialog
		 */
		askDelete(row) {
			this.deleting = row
		},
	},
}
