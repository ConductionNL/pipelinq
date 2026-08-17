// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared period behaviour for the analytics dashboard widgets.
//
// Injects the dashboard-level `cnDashboardDateRange` ref provided by
// CnDashboardPage (the date-range header) and exposes it as a reactive
// `period` (week | month | quarter | year) for the analytics API. When
// the user changes the range, every widget using this mixin re-runs its
// `load()` — complementing dashboardRefreshMixin, which does the same
// for the header Refresh action.

import { ref } from 'vue'
import { rangeToPeriod } from '../../../services/analyticsPeriod.js'

export default {
	inject: {
		cnDashboardDateRange: { default: () => ref(null) },
	},
	computed: {
		/**
		 * Analytics API period selector derived from the dashboard
		 * date-range header.
		 *
		 * @return {string} week | month | quarter | year.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		period() {
			// Vue 2.7 hands options-API injects either as the raw ref or
			// already unwrapped (CnChartWidget re-reads it in setup() for
			// the same reason) — support both shapes.
			const injected = this.cnDashboardDateRange
			const range =
				injected && typeof injected === 'object' && 'value' in injected
					? injected.value
					: injected
			return rangeToPeriod(range || null)
		},
	},
	watch: {
		period() {
			if (typeof this.load === 'function') {
				this.load()
			}
		},
	},
}
