// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The props a detail-page header action takes from CnDetailPage's `#actions`
 * slot: the object the page shows and its id.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
export default {
	props: {
		/** The object the page shows, from CnDetailPage's `#actions` slot. */
		object: {
			type: Object,
			default: null,
		},

		/** The object's id, from CnDetailPage's `#actions` slot. */
		objectId: {
			type: String,
			default: '',
		},
	},
}
