// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Addresses of pages that became a default view on another page.
 *
 * A bookmark or a link in a mail to a retired page keeps working: the router
 * sends it to the page that now holds the list, with the view applied. Each
 * entry names the old path and the vue-router redirect target.
 *
 * @spec openspec/changes/queue-as-default-view/specs/retire-queue-concept/spec.md
 */
export const RETIRED_ROUTES = Object.freeze([
	{ path: '/queue', redirect: { name: 'Tickets', query: { savedView: 'default:queue' } } },
])

/**
 * The redirect routes for the retired addresses whose target page exists.
 *
 * A target missing from the manifest (a page held back by a profile) is left
 * out, so the address falls through to the catch-all instead of failing.
 *
 * @param {Array<{id: string}>} pages The manifest's pages.
 * @return {Array<{path: string, redirect: object}>} vue-router redirect routes.
 */
export function retiredRedirects(pages) {
	const ids = new Set((Array.isArray(pages) ? pages : []).map((page) => page && page.id))
	return RETIRED_ROUTES
		.filter((route) => ids.has(route.redirect.name))
		.map((route) => ({ path: route.path, redirect: { name: route.redirect.name, query: { ...route.redirect.query } } }))
}
