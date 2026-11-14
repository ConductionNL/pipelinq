/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * What the compact dashboard list widgets (deals overview, my leads, recent
 * activities) share: the manifest's `title` prop, the loading flag and list
 * columns, and the fetch on mount.
 *
 * The host defines `fetchData()`, which runs its own requests through
 * `loadWidget`.
 */

import { initializeStores } from '../../store/store.js'
import { LIST_COLUMNS } from './listTable.js'

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
			loading: false,
			columns: LIST_COLUMNS,
		}
	},

	async mounted() {
		await this.fetchData()
	},

	methods: {
		/**
		 * Run the widget's requests against the object-type registry, with the
		 * loading flag on; a failure is logged under the widget's name.
		 *
		 * @param {string} widgetName The widget, for the log line.
		 * @param {(config: object) => Promise<void>} load The requests.
		 * @return {Promise<void>}
		 */
		async loadWidget(widgetName, load) {
			this.loading = true
			try {
				const { objectStore } = await initializeStores()
				await load(objectStore.objectTypeRegistry)
			} catch (err) {
				console.error(widgetName + ' fetch error:', err)
			} finally {
				this.loading = false
			}
		},
	},
}
