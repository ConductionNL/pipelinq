<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - Cross-module analytics dashboard for Klantbeeld 360.
  -
  - Renders four cross-module KPI cards (open pipeline value, open requests,
  - contactmomenten in the selected period, active leads) via CnDashboardPage +
  - CnStatsBlock. Data is read from the server-side aggregation endpoint
  - GET /api/analytics/summary so large installations never fetch full
  - collections client-side.
  -
  - @spec openspec/changes/klantbeeld-360/tasks.md#task-2.1
  -->
<template>
	<CnDashboardPage :title="t('pipelinq', 'Analytics')">
		<template #header-actions>
			<NcSelect
				v-model="selectedPeriod"
				class="analytics-dashboard__period"
				:options="periodOptions"
				:clearable="false"
				:searchable="false"
				label="label"
				:input-label="t('pipelinq', 'Time period')"
				:aria-label-combobox="t('pipelinq', 'Time period')"
				@input="onPeriodChange" />
		</template>

		<template #empty>
			<div class="analytics-dashboard">
				<NcLoadingIcon v-if="loading" :size="44" class="analytics-dashboard__loading" />

				<div v-else-if="error" class="analytics-dashboard__error">
					<AlertCircleOutline :size="20" />
					<span>{{ error }}</span>
					<NcButton type="secondary" @click="fetchSummary">
						{{ t('pipelinq', 'Retry') }}
					</NcButton>
				</div>

				<CnKpiGrid v-else class="analytics-dashboard__grid">
					<CnStatsBlock
						:title="t('pipelinq', 'Open Pipeline Value')"
						:count="formatCurrency(summary.openPipelineValue)"
						:count-label="t('pipelinq', 'across all pipelines')"
						:icon="CashMultiple"
						variant="primary"
						show-zero-count
						horizontal />
					<CnStatsBlock
						:title="t('pipelinq', 'Open Requests')"
						:count="summary.openRequests"
						:count-label="t('pipelinq', 'requests')"
						:icon="FileDocumentOutline"
						show-zero-count
						horizontal />
					<CnStatsBlock
						:title="t('pipelinq', 'Contactmomenten')"
						:count="summary.contactmomentenCount"
						:count-label="periodLabel"
						:icon="CommentTextOutline"
						show-zero-count
						horizontal />
					<CnStatsBlock
						:title="t('pipelinq', 'Active Leads')"
						:count="summary.activeLeads"
						:count-label="t('pipelinq', 'leads')"
						:icon="TrendingUp"
						show-zero-count
						horizontal />
				</CnKpiGrid>
			</div>
		</template>
	</CnDashboardPage>
</template>

<script>
import { CnDashboardPage, CnKpiGrid, CnStatsBlock, NcSelect, NcButton, NcLoadingIcon } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import CommentTextOutline from 'vue-material-design-icons/CommentTextOutline.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'

export default {
	name: 'AnalyticsDashboard',
	components: {
		CnDashboardPage,
		CnKpiGrid,
		CnStatsBlock,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		AlertCircleOutline,
	},
	data() {
		return {
			CashMultiple,
			FileDocumentOutline,
			CommentTextOutline,
			TrendingUp,
			loading: true,
			error: null,
			selectedPeriod: { id: 'month', label: t('pipelinq', 'This month') },
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
		 * Period options for the header filter.
		 *
		 * @return {Array<{id: string, label: string}>} The selectable periods.
		 */
		periodOptions() {
			return [
				{ id: 'week', label: t('pipelinq', 'This week') },
				{ id: 'month', label: t('pipelinq', 'This month') },
				{ id: 'quarter', label: t('pipelinq', 'This quarter') },
			]
		},
		/**
		 * Label shown beneath the contactmomenten KPI for the active period.
		 *
		 * @return {string} The localized period label.
		 */
		periodLabel() {
			return this.selectedPeriod?.label || t('pipelinq', 'This month')
		},
	},
	/**
	 * Re-fetch summary data on every mount (REQ-KB360-022 freshness).
	 */
	mounted() {
		this.fetchSummary()
	},
	methods: {
		/**
		 * Fetch the cross-module KPI summary for the selected period.
		 *
		 * @return {Promise<void>}
		 */
		async fetchSummary() {
			this.loading = true
			this.error = null
			try {
				const period = this.selectedPeriod?.id || 'month'
				const { data } = await axios.get(
					generateUrl('/apps/pipelinq/api/analytics/summary'),
					{ params: { period } },
				)
				this.summary = data
			} catch (err) {
				this.error = t('pipelinq', 'Could not load analytics. Please try again.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Re-fetch when the period filter changes.
		 *
		 * @return {Promise<void>}
		 */
		async onPeriodChange() {
			await this.fetchSummary()
		},
		/**
		 * Format a number as EUR currency in Dutch locale.
		 *
		 * @param {number} value - The numeric value.
		 * @return {string} The formatted currency string.
		 */
		formatCurrency(value) {
			const amount = Number(value) || 0
			return '€ ' + new Intl.NumberFormat('nl-NL').format(Math.round(amount))
		},
	},
}
</script>

<style scoped>
.analytics-dashboard {
	padding: 0 20px 20px;
}

.analytics-dashboard__period {
	min-width: 180px;
}

.analytics-dashboard__loading {
	display: flex;
	justify-content: center;
	padding: 40px 0;
}

.analytics-dashboard__error {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 20px;
	color: var(--color-error);
}

.analytics-dashboard__grid {
	margin-top: 8px;
}
</style>
