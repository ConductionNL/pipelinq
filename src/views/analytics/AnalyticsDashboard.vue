<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/klantbeeld-360/tasks.md#task-2.1 -->
<template>
	<CnDashboardPage
		:title="t('pipelinq', 'Analytics')"
		:subtitle="t('pipelinq', 'Cross-module KPI overview')"
		:loading="loading"
		:refreshing="loading"
		@refresh="fetchSummary">
		<template #header-actions>
			<NcSelect
				v-model="selectedPeriod"
				:input-label="t('pipelinq', 'Period')"
				:options="periodOptions"
				label="label"
				track-by="value"
				:reduce="opt => opt.value"
				:clearable="false"
				@input="fetchSummary" />
		</template>

		<NcNoteCard v-if="error" type="error" class="analytics-dashboard__error">
			{{ error }}
		</NcNoteCard>

		<CnKpiGrid>
			<CnStatsBlock
				:title="t('pipelinq', 'Open Pipeline Value')"
				:count="openPipelineValueLabel"
				:count-label="t('pipelinq', 'active leads')"
				:icon="ChartLine"
				variant="primary"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Open Requests')"
				:count="summary.openRequests"
				:count-label="t('pipelinq', 'open')"
				:icon="FileDocument"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Contactmomenten')"
				:count="summary.contactmomentenCount"
				:count-label="periodLabel"
				:icon="MessageText"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Active Leads')"
				:count="summary.activeLeads"
				:count-label="t('pipelinq', 'opportunities')"
				:icon="TrendingUp"
				horizontal />
		</CnKpiGrid>
	</CnDashboardPage>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcSelect, NcNoteCard } from '@nextcloud/vue'
import {
	CnDashboardPage,
	CnKpiGrid,
	CnStatsBlock,
} from '@conduction/nextcloud-vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import MessageText from 'vue-material-design-icons/MessageText.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'

/**
 * Cross-module analytics dashboard (Klantbeeld 360 Feature 3).
 *
 * Renders four KPI cards (Open Pipeline Value, Open Requests,
 * Contactmomenten, Active Leads) plus a time-period filter. Data is
 * fetched from `GET /api/analytics/summary?period={period}` so large
 * installations are not forced to fetch full collections client-side.
 *
 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-020
 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-021
 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-022
 */
export default {
	name: 'AnalyticsDashboard',
	components: {
		NcSelect,
		NcNoteCard,
		CnDashboardPage,
		CnKpiGrid,
		CnStatsBlock,
	},
	data() {
		return {
			ChartLine,
			FileDocument,
			MessageText,
			TrendingUp,
			loading: false,
			error: null,
			selectedPeriod: 'month',
			summary: {
				openPipelineValue: 0,
				openRequests: 0,
				contactmomentenCount: 0,
				activeLeads: 0,
				period: 'month',
			},
		}
	},
	computed: {
		/**
		 * Period filter options. Labels use t() so they translate.
		 *
		 * @return {Array<{value: string, label: string}>}
		 */
		periodOptions() {
			return [
				{ value: 'week', label: this.t('pipelinq', 'This week') },
				{ value: 'month', label: this.t('pipelinq', 'This month') },
				{ value: 'quarter', label: this.t('pipelinq', 'This quarter') },
			]
		},
		/**
		 * Human-readable label for the active period (KPI count sub-label).
		 *
		 * @return {string}
		 */
		periodLabel() {
			const match = this.periodOptions.find(o => o.value === this.selectedPeriod)
			return match ? match.label : this.t('pipelinq', 'This month')
		},
		/**
		 * Formatted EUR value for the Open Pipeline Value KPI.
		 *
		 * @return {string}
		 */
		openPipelineValueLabel() {
			return this.formatCurrency(this.summary.openPipelineValue)
		},
	},
	mounted() {
		// Per REQ-KB360-022, re-fetch on every mount (incl. nav return).
		this.fetchSummary()
	},
	methods: {
		/**
		 * Fetch the cross-module KPI summary from the AnalyticsController.
		 *
		 * Every await is wrapped in try/catch; failures surface a user-facing
		 * NcNoteCard and the previous summary is preserved (zero defaults on
		 * first load).
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-022
		 */
		async fetchSummary() {
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/pipelinq/api/analytics/summary')
				const response = await axios.get(url, { params: { period: this.selectedPeriod } })
				const data = response.data || {}
				this.summary = {
					openPipelineValue: Number(data.openPipelineValue) || 0,
					openRequests: Number(data.openRequests) || 0,
					contactmomentenCount: Number(data.contactmomentenCount) || 0,
					activeLeads: Number(data.activeLeads) || 0,
					period: data.period || this.selectedPeriod,
				}
			} catch (e) {
				this.error = this.t('pipelinq', 'Failed to load analytics summary. Please try again.')
				// eslint-disable-next-line no-console
				console.warn('[AnalyticsDashboard] summary fetch failed', e)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Format a number as Dutch EUR (e.g. `€ 42.000`).
		 *
		 * @param {number} value Raw amount.
		 * @return {string}
		 */
		formatCurrency(value) {
			const n = Number(value) || 0
			try {
				return new Intl.NumberFormat('nl-NL', {
					style: 'currency',
					currency: 'EUR',
					maximumFractionDigits: 0,
				}).format(n)
			} catch {
				return '€ ' + n
			}
		},
	},
}
</script>

<style scoped>
.analytics-dashboard__error {
	margin-bottom: var(--default-grid-baseline, 8px);
}
</style>
