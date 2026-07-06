/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Shared config for the compact dashboard list widgets (deals overview,
 * my leads, recent activities). Each widget self-fetches and shapes its
 * rows to `{ id, mainText, subText }`, then renders the universal
 * `<CnDataTable>` headerless with these columns — a bold name and a
 * muted, right-aligned trailing status — the fleet-wide list-widget
 * pattern (ADR-049), matching procest and scholiq.
 */

/**
 * Columns for a headerless name + trailing-status list. `mainText` and
 * `subText` are the keys produced by each widget's `items` computed; the
 * `cn-cell--*` utilities live in nextcloud-vue's table.css.
 *
 * @type {Array<{key: string, cellClass: string}>}
 */
export const LIST_COLUMNS = [
	{ key: 'mainText', cellClass: 'cn-cell--strong' },
	{ key: 'subText', cellClass: 'cn-cell--muted cn-cell--end' },
]

/**
 * Same-tab navigation used by both a row click and the "View all" footer.
 * A plain `window.location.href` resolves correctly both inside the in-app
 * router and when the widget runs standalone on the Nextcloud Dashboard
 * (where no vue-router is present).
 *
 * @param {string} url The (generateUrl-resolved) target URL.
 * @return {void}
 */
export function navigateTo(url) {
	if (url) {
		window.location.href = url
	}
}
