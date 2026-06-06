<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Lead-management analytics dashboard.

  Uses CnDashboardPage with four widget slots. Data is fetched once on
  mount from GET /api/rapportage/pipeline-stats (non-admin accessible)
  and passed to widgets via props.

  @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
-->
<template>
	<CnDashboardPage
		:title="t('pipelinq', 'Lead analytics')"
		:loading="loading"
		:widgets="widgets">
		<template #header-actions>
			<NcButton type="secondary" :disabled="loading" @click="loadStats">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
					<Refresh v-else :size="20" />
				</template>
				{{ t('pipelinq', 'Refresh') }}
			</NcButton>
		</template>

		<template #widget-funnel>
			<PipelineFunnelWidget :data="stats.stageValues || []" />
		</template>
		<template #widget-sources>
			<SourcePerformanceWidget :data="stats.sourcePerformance || []" />
		</template>
		<template #widget-aging>
			<LeadAgingWidget :data="stats.agingBuckets || []" />
		</template>
		<template #widget-winloss>
			<WinLossWidget
				:data="stats.winLoss || {}"
				@range-change="onRangeChange" />
		</template>
	</CnDashboardPage>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { CnDashboardPage } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import PipelineFunnelWidget from './PipelineFunnelWidget.vue'
import SourcePerformanceWidget from './SourcePerformanceWidget.vue'
import LeadAgingWidget from './LeadAgingWidget.vue'
import WinLossWidget from './WinLossWidget.vue'

export default {
	name: 'RapportageView',
	components: {
		CnDashboardPage,
		NcButton,
		NcLoadingIcon,
		Refresh,
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
	computed: {
		/**
		 * Widget descriptor list consumed by CnDashboardPage. Slot names
		 * mirror the template's named slots (widget-funnel, widget-sources,
		 * widget-aging, widget-winloss).
		 *
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
		 */
		widgets() {
			return [
				{ id: 'funnel',   title: t('pipelinq', 'Pipeline funnel'),    span: 2 },
				{ id: 'sources',  title: t('pipelinq', 'Source performance'), span: 2 },
				{ id: 'aging',    title: t('pipelinq', 'Lead aging'),         span: 1 },
				{ id: 'winloss',  title: t('pipelinq', 'Win/loss'),           span: 1 },
			]
		},
	},
	mounted() {
		this.loadStats()
	},
	methods: {
		/**
		 * Fetch analytics data from the rapportage pipeline-stats endpoint.
		 * Wraps the axios call in try/catch with a user-facing error toast
		 * per REQ-LM-006 acceptance criteria.
		 *
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-006
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
			} catch (e) {
				showError(t('pipelinq', 'Failed to load lead analytics. Please try again.'))
				this.stats = {}
			} finally {
				this.loading = false
			}
		},
		/**
		 * Re-fetch with a new date range when the win/loss widget changes.
		 *
		 * @param {{from:string,to:string}|null} range The selected range.
		 * @spec openspec/changes/lead-management/specs/lead-management/spec.md#REQ-LM-008
		 */
		onRangeChange(range) {
			this.dateRange = range
			this.loadStats()
		},
	},
}
</script>
