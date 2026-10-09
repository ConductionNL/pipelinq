// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Refresh the page's widgets when a line item is created.
 *
 * The deal page counts its line items in a stats-block widget, which fetches
 * once and then only on the page's Refresh action. Adding a line through the
 * Deal line items list left the count at 0 until a reload
 * (round3-review-points, review point 4). Every create through the library
 * (object-list inline create, the object store) announces itself on the
 * window; this listener answers a line item's announcement with the same
 * page refresh the Refresh action sends.
 *
 * @spec openspec/changes/round3-review-points/specs/lead-product-link/spec.md
 */

import { emit } from '@nextcloud/event-bus'

/** The window event the library dispatches after a create (walkthroughSignals.OBJECT_CREATED_EVENT). */
export const OBJECT_CREATED_EVENT = 'cn-walkthrough:object-created'

/** The event-bus channel the page-level Refresh action broadcasts on. */
export const PAGE_REFRESH_CHANNEL = 'cn:page:refresh'

/** Schemas whose new objects change a count on the page that shows them. */
export const REFRESH_ON_CREATE = ['leadProduct']

/**
 * Listen for creates and refresh the page after a line item.
 *
 * @param {Window} target The window to listen on.
 * @param {(channel: string, payload: object) => void} [send] The event-bus emit (injectable for tests).
 * @return {() => void} Removes the listener.
 * @spec openspec/changes/round3-review-points/specs/lead-product-link/spec.md
 */
export function installPageRefreshOnCreate(target, send = emit) {
	const onCreated = (event) => {
		const schema = event?.detail?.schema
		if (REFRESH_ON_CREATE.includes(schema)) {
			send(PAGE_REFRESH_CHANNEL, {})
		}
	}
	target.addEventListener(OBJECT_CREATED_EVENT, onCreated)
	return () => target.removeEventListener(OBJECT_CREATED_EVENT, onCreated)
}
