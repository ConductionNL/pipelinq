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

import { useListView } from '@conduction/nextcloud-vue'
import { inject } from 'vue'
import { useObjectStore } from '../../store/modules/object.js'
import { exportPageTabs } from './exportPageTabs.js'

/**
 * The list view of one export schema, wired to the sidebar and the object
 * store. Call it from a list page's `setup()`.
 *
 * @param {string} schema The schema slug of the list.
 * @param {object} [options] Extra `useListView` options, such as `defaultSort`.
 * @return {object} What `useListView` returns.
 * @spec openspec/specs/bi-export-board/spec.md#requirement-the-bi-export-lists-share-one-tab-strip
 */
export function exportListView(schema, options = {}) {
	const sidebarState = inject('sidebarState', null)
	const objectStore = useObjectStore()
	return useListView(schema, { sidebarState, objectStore, ...options })
}

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
