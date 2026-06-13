// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shared fetch + formatting behaviour for the four analytics KPI
// widgets (lead conversion / avg resolution / contact volume /
// satisfaction). Each widget renders one CnStatsBlock from the shared
// per-period analytics overview (one cached request per render pass).

import { getAnalyticsOverview } from '../../../services/dashboardData.js'
import analyticsPeriodMixin from './analyticsPeriodMixin.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	mixins: [analyticsPeriodMixin, dashboardRefreshMixin],
	data() {
		return {
			overview: {
				leadConversionRate: null,
				avgRequestResolutionTime: null,
				contactMomentVolume: 0,
				customerSatisfactionScore: null,
				previousPeriod: {},
			},
		}
	},
	methods: {
		/**
		 * Fetch the (cached) cross-module overview for the current period.
		 *
		 * @spec openspec/changes/decompose-unified-analytics/specs/dashboard/spec.md#REQ-DASH-010
		 */
		async load() {
			try {
				this.overview = await getAnalyticsOverview(this.period) || {}
			} catch (err) {
				console.error(this.$options.name + ' fetch error:', err)
			}
		},
		/**
		 * Up/down trend indicator versus the previous equal period.
		 *
		 * @param {string} field - Key in `overview` to compare.
		 * @return {string} Human-readable delta string.
		 * @spec openspec/changes/decompose-unified-analytics/specs/dashboard/spec.md#REQ-DASH-010
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
