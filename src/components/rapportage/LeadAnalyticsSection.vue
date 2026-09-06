<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Lead-management analytics in-body section, hosted on the declarative
  type:dashboard "Lead analytics" page via a kind:'section' bodyWidget
  (pipelinq-dashboards-declarative).

  The four bespoke chart/table widgets (pipeline funnel bar chart, source
  performance table, lead-aging donut, win/loss pie + KPI block) cannot be
  expressed as endpoint stat widgets in the dashboard grid: the page fetches
  GET /api/rapportage/pipeline-stats ONCE and distributes the four slices to
  presentational child widgets, and the interactivity lives INSIDE the widgets
  (the funnel's OR-sourced pipeline selector filters in memory; the win/loss
  date-range selector re-fetches the parent with dateFrom/dateTo). So instead
  of a page-level period pageFilter, the whole surface lives in this single
  section which preserves the legacy view's one-fetch + in-widget filtering
  behaviour verbatim.
-->
<template>
	<section class="lead-analytics">
		<NcLoadingIcon v-if="loading" :size="32" />
		<div v-else class="lead-analytics__grid">
			<div class="lead-analytics__cell lead-analytics__cell--wide">
				<h3>{{ t('pipelinq', 'Pipeline funnel') }}</h3>
				<PipelineFunnelWidget :data="stats.stageValues || []" />
			</div>
			<div class="lead-analytics__cell lead-analytics__cell--wide">
				<h3>{{ t('pipelinq', 'Source performance') }}</h3>
				<SourcePerformanceWidget :data="stats.sourcePerformance || []" />
			</div>
			<div class="lead-analytics__cell">
				<h3>{{ t('pipelinq', 'Lead aging') }}</h3>
				<LeadAgingWidget :data="stats.agingBuckets || []" />
			</div>
			<div class="lead-analytics__cell">
				<h3>{{ t('pipelinq', 'Win/loss') }}</h3>
				<WinLossWidget
					:data="stats.winLoss || {}"
					@rangeChange="onRangeChange" />
			</div>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'
import LeadAgingWidget from '../../views/rapportage/LeadAgingWidget.vue'
import PipelineFunnelWidget from '../../views/rapportage/PipelineFunnelWidget.vue'
import SourcePerformanceWidget from '../../views/rapportage/SourcePerformanceWidget.vue'
import WinLossWidget from '../../views/rapportage/WinLossWidget.vue'

export default {
	name: 'LeadAnalyticsSection',
	components: {
		NcLoadingIcon,
		PipelineFunnelWidget,
		SourcePerformanceWidget,
		LeadAgingWidget,
		WinLossWidget,
	},

	data() {
		return {
			loading: false,
			stats: {},
			dateRange: null,
		}
	},

	mounted() {
		this.loadStats()
	},

	methods: {
		/**
		 * Fetch analytics data from the rapportage pipeline-stats endpoint
		 * (single fetch shared across the four child widgets), matching the
		 * legacy RapportageView behaviour.
		 *
		 * @spec openspec/specs/contactmomenten-rapportage/spec.md#requirement-kpi-dashboard
		 */
		async loadStats() {
			this.loading = true
			try {
				const params = {}
				if (this.dateRange) {
					if (this.dateRange.from) params.dateFrom = this.dateRange.from
					if (this.dateRange.to) params.dateTo = this.dateRange.to
				}
				const response = await axios.get(
					generateUrl('/apps/pipelinq/api/rapportage/pipeline-stats'),
					{ params },
				)
				this.stats = response?.data || {}
			} catch {
				showError(
					t(
						'pipelinq',
						'Failed to load lead analytics. Please try again.',
					),
				)
				this.stats = {}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Re-fetch with a new date range when the win/loss widget changes.
		 *
		 * @param {{from:string,to:string}|null} range The selected range.
		 */
		onRangeChange(range) {
			this.dateRange = range
			this.loadStats()
		},
	},
}
</script>

<style scoped>
.lead-analytics__grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 16px;
}

.lead-analytics__cell {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
}

.lead-analytics__cell h3 {
	margin: 0 0 12px;
	font-weight: 600;
}
@media (max-width: 1024px) {
	.lead-analytics__grid {
		grid-template-columns: 1fr;
	}
}
</style>
