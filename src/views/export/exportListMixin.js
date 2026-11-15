/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the export destinations and export runs lists share: the row whose
 * action is running, the tab strip over the BI export lists, and the Refresh
 * spinner around the list fetch (`refresh` comes from `useListView`).
 *
 * @spec openspec/specs/bi-export-board/spec.md#requirement-the-bi-export-lists-share-one-tab-strip
 */

import { exportPageTabs } from './exportPageTabs.js'

export default {
	data() {
		return {
			busyId: null,
			refreshing: false,
		}
	},

	computed: {
		/**
		 * The tab strip over the BI export lists (board PqBiExport).
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/bi-export-board/spec.md#requirement-the-bi-export-lists-share-one-tab-strip
		 */
		pageTabs() {
			return exportPageTabs(t)
		},
	},

	methods: {
		/**
		 * Refresh handler for the Actions-menu Refresh item. Drives the
		 * CnIndexPage `:refreshing` spinner around the underlying fetch.
		 *
		 * @spec exclude presentational refresh-button spinner wiring — no business logic
		 */
		async onRefresh() {
			this.refreshing = true
			try {
				await this.refresh()
			} finally {
				this.refreshing = false
			}
		},
	},
}
