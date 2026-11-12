/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * What the dashboard's quick-create widgets (create lead, start request)
 * share: the manifest's `title` prop, the object-type registry they post to,
 * the picked client, and the submit and success state.
 *
 * The host keeps its own form, options and submit.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-23
 */
export default {
	props: {
		/**
		 * Widget heading, supplied by the manifest.
		 *
		 * Declared but not rendered here: the widget chrome draws the heading.
		 * Declaring it is what stops Vue's attribute fallthrough painting
		 * `title="…"` onto the component's root element, which would hover a
		 * browser tooltip over the whole widget.
		 */
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			config: null,
			selectedClient: null,
			submitted: false,
			submitting: false,
			success: false,
			successLink: '',
		}
	},

	methods: {
		/**
		 * @param {object} client The client the user picked.
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-23
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-54
		 */
		onClientSelected(client) {
			this.selectedClient = client
		},
	},
}
