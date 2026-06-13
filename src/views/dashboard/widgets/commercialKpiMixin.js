// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared fetch + formatting behaviour for the six commercial KPI widgets
// (revenue / won value / win rate / avg deal size / weighted forecast /
// open pipeline value). Each widget renders one CnStatsBlock from the
// shared per-period commercial overview (one cached request per render
// pass), inheriting the dashboard date-range and Refresh action via the
// analyticsPeriod + dashboardRefresh mixins.

import { getCommercialOverview } from '../../../services/dashboardData.js'
import analyticsPeriodMixin from './analyticsPeriodMixin.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	mixins: [analyticsPeriodMixin, dashboardRefreshMixin],
	data() {
		return {
			overview: {
				revenue: null,
				wonValue: null,
				winRate: null,
				avgDealSize: null,
				weightedForecast: null,
				openPipelineValue: null,
				previousPeriod: {},
			},
		}
	},
	methods: {
		/**
		 * Fetch the (cached) commercial overview for the current period.
		 *
		 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
		 */
		async load() {
			try {
				this.overview = await getCommercialOverview(this.period) || {}
			} catch (err) {
				console.error(this.$options.name + ' fetch error:', err)
			}
		},
		/**
		 * Format a number as a whole-euro amount, or an em dash when null.
		 *
		 * @param {number|null} value - Amount in euros.
		 * @return {string} Formatted currency string.
		 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
		 */
		formatEur(value) {
			if (value === null || value === undefined || Number.isNaN(Number(value))) {
				return '—'
			}
			return new Intl.NumberFormat(undefined, {
				style: 'currency',
				currency: 'EUR',
				maximumFractionDigits: 0,
			}).format(Number(value))
		},
		/**
		 * Up/down trend indicator versus the previous equal period.
		 *
		 * @param {string} field - Key in `overview` to compare.
		 * @return {string} Human-readable delta string.
		 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
		 */
		trendLabel(field) {
			const current = this.overview?.[field]
			const previous = this.overview?.previousPeriod?.[field]
			if (current === null || current === undefined || previous === null || previous === undefined) {
				return this.t('pipelinq', 'vs previous period')
			}
			if (current === previous) {
				return this.t('pipelinq', 'no change')
			}
			const arrow = current > previous ? '↑' : '↓'
			return arrow + ' ' + this.t('pipelinq', 'vs previous period')
		},
	},
}
