// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared refresh behaviour for manifest-driven dashboard widgets.
//
// A widget using this mixin defines an (async) `load()` method holding its
// fetch logic. The mixin calls `load()` on mount and again whenever the
// dashboard refresh signal bumps (the header "Refresh" action), so the
// widget refetches in place without relying on a remount.

import { getDashboardRefreshSignal } from '../../../services/dashboardData.js'

export default {
	computed: {
		/** Reactive token; bumps when the dashboard is refreshed. */
		dashboardRefreshToken() {
			return getDashboardRefreshSignal().token
		},
	},
	watch: {
		dashboardRefreshToken() {
			if (typeof this.load === 'function') {
				this.load()
			}
		},
	},
	mounted() {
		if (typeof this.load === 'function') {
			this.load()
		}
	},
}
