/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The props a POS detail-page widget takes from the page host: the record
 * the page shows, its id, and the manifest widget title.
 *
 * The widget still declares `inheritAttrs: false` itself, for the other keys
 * the host spreads (register / schema / store, the widget content).
 *
 * @spec openspec/specs/pos/spec.md
 */
export default {
	props: {
		/** The record, from the detail page. */
		objectData: {
			type: Object,
			default: null,
		},

		/** The record id, from the detail page. */
		objectId: {
			type: String,
			default: '',
		},

		/** The manifest widget title. */
		title: {
			type: String,
			default: '',
		},
	},
}
