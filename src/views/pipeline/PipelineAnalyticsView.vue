<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - Per-pipeline sales analytics for Klantbeeld 360.
  -
  - Lets the user pick a pipeline and shows four KPI cards (total open value,
  - win rate, average deal size, active opportunities) plus a horizontal stage
  - funnel chart. Lead counts per pipeline are small (< 500), so KPIs are
  - aggregated client-side from the shared dashboard data helpers.
  -
  - @spec openspec/changes/klantbeeld-360/tasks.md#task-3.1
  -->
<template>
	<CnDetailPage
		:title="t('pipelinq', 'Pipeline Analytics')"
		:subtitle="t('pipelinq', 'Sales pipeline KPIs and stage funnel')"
		:loading="pipelinesLoading">
		<template #actions>
			<NcSelect
				v-if="pipelines.length"
				v-model="selectedPipeline"
				class="pipeline-analytics__select"
				:options="pipelines"
				:clearable="false"
				label="title"
				:input-label="t('pipelinq', 'Pipeline')"
				:aria-label-combobox="t('pipelinq', 'Pipeline')"
				@input="onPipelineChange" />
		</template>

		<div v-if="!pipelinesLoading && pipelines.length === 0" class="pipeline-analytics__empty">
			<p>{{ t('pipelinq', 'No pipelines available') }}</p>
		</div>

		<template v-else>
			<NcLoadingIcon v-if="leadsLoading" :size="44" class="pipeline-analytics__loading" />

			<div v-else-if="error" class="pipeline-analytics__error">
				<AlertCircleOutline :size="20" />
				<span>{{ error }}</span>
				<NcButton type="secondary" @click="loadLeads">
					{{ t('pipelinq', 'Retry') }}
				</NcButton>
			</div>

			<template v-else>
				<CnKpiGrid class="pipeline-analytics__grid">
					<CnStatsBlock
						:title="t('pipelinq', 'Total Pipeline Value')"
						:count="formatCurrency(totalValue)"
						:count-label="t('pipelinq', 'open value')"
						:icon="CashMultiple"
						variant="primary"
						show-zero-count
						horizontal />
					<CnStatsBlock
						:title="t('pipelinq', 'Win Rate')"
						:count="winRateDisplay"
						:count-label="t('pipelinq', 'closed won / lost')"
						:icon="TrophyOutline"
						show-zero-count
						horizontal />
					<CnStatsBlock
						:title="t('pipelinq', 'Average Deal Size')"
						:count="avgDealDisplay"
						:count-label="t('pipelinq', 'per active lead')"
						:icon="ChartLine"
						show-zero-count
						horizontal />
					<CnStatsBlock
						:title="t('pipelinq', 'Active Opportunities')"
						:count="openLeads.length"
						:count-label="t('pipelinq', 'active leads')"
						:icon="TrendingUp"
						show-zero-count
						horizontal />
				</CnKpiGrid>

				<CnDetailCard :title="t('pipelinq', 'Stage Funnel')">
					<div v-if="stageData.length === 0" class="pipeline-analytics__empty">
						<p>{{ t('pipelinq', 'No leads in this pipeline') }}</p>
					</div>
					<template v-else>
						<CnChartWidget
							type="bar"
							:series="chartSeries"
							:categories="stageData.map(s => s.stage)"
							:options="chartOptions"
							:height="Math.max(220, stageData.length * 48)" />
						<!-- Data-table fallback for assistive tech and narrow viewports (WCAG AA). -->
						<table class="pipeline-analytics__table">
							<caption class="hidden-visually">
								{{ t('pipelinq', 'Lead count per stage') }}
							</caption>
							<thead>
								<tr>
									<th scope="col">
										{{ t('pipelinq', 'Stage') }}
									</th>
									<th scope="col">
										{{ t('pipelinq', 'Leads') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in stageData" :key="row.stage">
									<td>{{ row.stage }}</td>
									<td>{{ row.count }}</td>
								</tr>
							</tbody>
						</table>
					</template>
				</CnDetailCard>
			</template>
		</template>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage, CnDetailCard, CnKpiGrid, CnStatsBlock, CnChartWidget, NcSelect, NcButton, NcLoadingIcon } from '@conduction/nextcloud-vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import TrophyOutline from 'vue-material-design-icons/TrophyOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import { getLeads, getPipelines } from '../../services/dashboardData.js'

export default {
	name: 'PipelineAnalyticsView',
	components: {
		CnDetailPage,
		CnDetailCard,
		CnKpiGrid,
		CnStatsBlock,
		CnChartWidget,
		NcSelect,
		NcButton,
		NcLoadingIcon,
		AlertCircleOutline,
	},
	data() {
		return {
			CashMultiple,
			TrophyOutline,
			ChartLine,
			TrendingUp,
			pipelinesLoading: true,
			leadsLoading: false,
			error: null,
			pipelines: [],
			selectedPipeline: null,
			leads: [],
		}
	},
	computed: {
		/**
		 * Leads belonging to the selected pipeline.
		 *
		 * @return {Array<object>} Leads scoped to the active pipeline.
		 */
		pipelineLeads() {
			const id = this.pipelineKey(this.selectedPipeline)
			if (!id) return []
			return this.leads.filter(l => String(l.pipeline || '') === id)
		},
		/**
		 * Active (open) leads in the pipeline.
		 *
		 * @return {Array<object>} Active leads.
		 */
		openLeads() {
			return this.pipelineLeads.filter(l => l.status === 'active')
		},
		/**
		 * Won leads in the pipeline.
		 *
		 * @return {Array<object>} Won leads.
		 */
		wonLeads() {
			return this.pipelineLeads.filter(l => l.status === 'won')
		},
		/**
		 * Lost leads in the pipeline.
		 *
		 * @return {Array<object>} Lost leads.
		 */
		lostLeads() {
			return this.pipelineLeads.filter(l => l.status === 'lost')
		},
		/**
		 * Total open-lead value.
		 *
		 * @return {number} Summed value of active leads.
		 */
		totalValue() {
			return this.openLeads.reduce((sum, l) => sum + (parseFloat(l.value) || 0), 0)
		},
		/**
		 * Win rate as a fraction, or null when no closed leads exist.
		 *
		 * @return {number|null} Win rate or null.
		 */
		winRate() {
			const closed = this.wonLeads.length + this.lostLeads.length
			if (closed === 0) return null
			return this.wonLeads.length / closed
		},
		/**
		 * Win rate formatted for display ('—' when not computable).
		 *
		 * @return {string} The display string.
		 */
		winRateDisplay() {
			if (this.winRate === null) return '—'
			return Math.round(this.winRate * 100) + '%'
		},
		/**
		 * Average deal size, or null when there are no active leads.
		 *
		 * @return {number|null} Average value or null.
		 */
		avgDealSize() {
			if (this.openLeads.length === 0) return null
			return this.totalValue / this.openLeads.length
		},
		/**
		 * Average deal size formatted for display ('—' when not computable).
		 *
		 * @return {string} The display string.
		 */
		avgDealDisplay() {
			if (this.avgDealSize === null) return '—'
			return this.formatCurrency(this.avgDealSize)
		},
		/**
		 * Lead count per stage, ordered by stageOrder ascending.
		 *
		 * @return {Array<{stage: string, count: number, order: number}>} Stage rows.
		 */
		stageData() {
			const stages = this.selectedPipeline?.stages || []
			const counts = new Map()
			const orderByName = new Map()

			stages.forEach((s, idx) => {
				const name = s.name || s.title || String(idx)
				counts.set(name, 0)
				orderByName.set(name, idx)
			})

			for (const lead of this.pipelineLeads) {
				const name = lead.stage || t('pipelinq', 'Unknown')
				if (!counts.has(name)) {
					counts.set(name, 0)
					orderByName.set(name, (lead.stageOrder ?? 999))
				}
				counts.set(name, counts.get(name) + 1)
			}

			return [...counts.entries()]
				.map(([stage, count]) => ({ stage, count, order: orderByName.get(stage) ?? 999 }))
				.sort((a, b) => a.order - b.order)
		},
		/**
		 * Chart series for the stage funnel.
		 *
		 * @return {Array<{name: string, data: Array<number>}>} The series.
		 */
		chartSeries() {
			return [{
				name: t('pipelinq', 'Leads'),
				data: this.stageData.map(s => s.count),
			}]
		},
		/**
		 * ApexCharts options forcing horizontal bars.
		 *
		 * @return {object} Chart options.
		 */
		chartOptions() {
			return {
				plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
				dataLabels: { enabled: true },
			}
		},
	},
	/**
	 * Load pipelines and auto-select the default on mount.
	 *
	 * @return {Promise<void>}
	 */
	async mounted() {
		await this.loadPipelines()
	},
	methods: {
		/**
		 * Stable identity key for a pipeline record.
		 *
		 * @param {object} pipeline - A pipeline record.
		 * @return {string} The id/uuid as a string.
		 */
		pipelineKey(pipeline) {
			if (!pipeline) return ''
			return String(pipeline.uuid || pipeline.id || '')
		},
		/**
		 * Fetch pipelines and auto-select the default (or first).
		 *
		 * @return {Promise<void>}
		 */
		async loadPipelines() {
			this.pipelinesLoading = true
			this.error = null
			try {
				const pipelines = await getPipelines()
				this.pipelines = pipelines || []
				if (this.pipelines.length) {
					this.selectedPipeline = this.pipelines.find(p => p.isDefault) || this.pipelines[0]
					await this.loadLeads()
				}
			} catch (err) {
				this.error = t('pipelinq', 'Could not load pipelines. Please try again.')
			} finally {
				this.pipelinesLoading = false
			}
		},
		/**
		 * Fetch all leads (cached) for client-side aggregation.
		 *
		 * @return {Promise<void>}
		 */
		async loadLeads() {
			this.leadsLoading = true
			this.error = null
			try {
				const leads = await getLeads()
				this.leads = leads || []
			} catch (err) {
				this.error = t('pipelinq', 'Could not load leads. Please try again.')
			} finally {
				this.leadsLoading = false
			}
		},
		/**
		 * Refresh KPIs when the selected pipeline changes.
		 *
		 * @return {Promise<void>}
		 */
		async onPipelineChange() {
			if (!this.leads.length) {
				await this.loadLeads()
			}
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
.pipeline-analytics__select {
	min-width: 220px;
}

.pipeline-analytics__grid {
	margin-bottom: 16px;
}

.pipeline-analytics__loading {
	display: flex;
	justify-content: center;
	padding: 40px 0;
}

.pipeline-analytics__error {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 20px;
	color: var(--color-error);
}

.pipeline-analytics__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
}

.pipeline-analytics__table {
	width: 100%;
	border-collapse: collapse;
	margin-top: 16px;
}

.pipeline-analytics__table th,
.pipeline-analytics__table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.hidden-visually {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	border: 0;
}
</style>
