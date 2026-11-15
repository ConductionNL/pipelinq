/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * What the marketing form dialogs a manifest page opens share (campaign,
 * journey, segment, template): the props the host passes, no attribute
 * fallthrough, and a fresh form each time the dialog opens.
 *
 * The host defines `prepareOnOpen()`: read the record, or reset the form.
 */
export default {
	inheritAttrs: false,

	props: {
		/** Whether the host page has the dialog open. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The record being edited, or null to create one. */
		item: {
			type: Object,
			default: null,
		},

		/** Closes the dialog. */
		close: {
			type: Function,
			default: () => {},
		},

		/** Re-reads the index page's list; the detail page does not pass it. */
		refresh: {
			type: Function,
			default: null,
		},
	},

	watch: {
		show: {
			immediate: true,
			/**
			 * Prepare the form each time the dialog opens.
			 *
			 * @param {boolean} open Whether the dialog is open.
			 */
			handler(open) {
				if (open) {
					this.prepareOnOpen()
				}
			},
		},
	},
}
