// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared refresh behaviour for manifest-driven dashboard widgets.
//
// A widget using this mixin defines an (async) `load()` method holding its
// fetch logic. The mixin runs it on mount and again whenever the dashboard
// refresh signal bumps (the header "Refresh" action), so the widget refetches
// in place without relying on a remount.
//
// THE MIXIN OWNS `loading` AND `error`, AND `load()` MUST THROW.
//
// Previously each widget wrapped its own fetch in `try { … } catch (err) {
// console.error(err) }` and left `count` at its initialised 0. A failed load
// therefore rendered "0 leads" / "0 overdue" — indistinguishable from a
// genuinely empty pipeline. Zero is a number a reader believes, so a dashboard
// with a dead backend looked like a dashboard reporting a quiet week.
//
// So `load()` no longer catches. It throws, this mixin records the error, and
// the widget passes `:error` to CnStatsBlock, which shows a dash and
// "Unavailable" in place of the number. A widget that swallows its own failure
// is back to reporting a confident zero, which is why the contract is stated
// here rather than left to each widget.

import { getDashboardRefreshSignal } from '../../../services/dashboardData.js'

export default {
	data() {
		return {
			/** True while `load()` is in flight. */
			loading: false,
			/** The error `load()` threw, if any. Cleared on each new attempt. */
			error: null,
		}
	},
	computed: {
		/** Reactive token; bumps when the dashboard is refreshed. */
		dashboardRefreshToken() {
			return getDashboardRefreshSignal().token
		},
	},
	watch: {
		dashboardRefreshToken() {
			this.runLoad()
		},
	},
	mounted() {
		this.runLoad()
	},
	methods: {
		/**
		 * Run the widget's `load()`, tracking loading and error state.
		 *
		 * The error is cleared BEFORE the attempt, not after it succeeds: a
		 * refresh that recovers must drop the previous failure, and a refresh
		 * that fails again must not briefly show stale-but-successful state.
		 *
		 * @return {Promise<void>}
		 */
		async runLoad() {
			if (typeof this.load !== 'function') {
				return
			}

			this.loading = true
			this.error = null
			try {
				await this.load()
			} catch (err) {
				this.error = err
				// Logged as well as rendered: the tile shows the reader that
				// something failed, the console tells a developer what.
				console.error('[pipelinq] dashboard widget load failed', err)
			} finally {
				this.loading = false
			}
		},
	},
}
