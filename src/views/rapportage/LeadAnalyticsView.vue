<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<template>
	<div class="lead-analytics">
		<div class="lead-analytics__header">
			<h2>{{ t('pipelinq', 'Pipeline analytics') }}</h2>
			<NcSelect
				:input-label="t('pipelinq', 'Pipeline')"
				:value="selectedPipeline"
				:options="pipelineOptions"
				:reduce="reducePipeline"
				label="label"
				class="lead-analytics__pipeline"
				@input="onPipelineChange" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" class="lead-analytics__loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-else class="lead-analytics__grid">
			<CnDetailCard :title="t('pipelinq', 'Pipeline funnel')">
				<PipelineFunnelWidget :stage-values="stats.stageValues" />
			</CnDetailCard>

			<CnDetailCard :title="t('pipelinq', 'Source performance')">
				<SourcePerformanceWidget :source-performance="stats.sourcePerformance" />
			</CnDetailCard>

			<CnDetailCard :title="t('pipelinq', 'Lead aging')">
				<LeadAgingWidget :aging-buckets="stats.agingBuckets" />
			</CnDetailCard>

			<CnDetailCard :title="t('pipelinq', 'Win / loss')">
				<WinLossWidget
					:win-loss="stats.winLoss"
					:range="dateRange"
					@range-change="onRangeChange" />
			</CnDetailCard>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { CnDetailCard } from '@conduction/nextcloud-vue'
import PipelineFunnelWidget from './PipelineFunnelWidget.vue'
import SourcePerformanceWidget from './SourcePerformanceWidget.vue'
import LeadAgingWidget from './LeadAgingWidget.vue'
import WinLossWidget from './WinLossWidget.vue'
import { useObjectStore } from '../../store/modules/object.js'

const EMPTY_STATS = {
	stageValues: [],
	sourcePerformance: [],
	agingBuckets: [],
	winLoss: {},
}

export default {
	name: 'LeadAnalyticsView',
	components: {
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		CnDetailCard,
		PipelineFunnelWidget,
		SourcePerformanceWidget,
		LeadAgingWidget,
		WinLossWidget,
	},
	data() {
		return {
			loading: true,
			error: '',
			stats: { ...EMPTY_STATS },
			pipelines: [],
			selectedPipeline: '',
			dateRange: 'all',
		}
	},
	computed: {
		/**
		 * @return {object} The shared object store.
		 */
		objectStore() {
			return useObjectStore()
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.2
		 * @return {Array<object>} Pipeline filter options (all + each pipeline).
		 */
		pipelineOptions() {
			return [
				{ id: '', label: t('pipelinq', 'All pipelines') },
				...this.pipelines.map(p => ({ id: String(p.id), label: p.title || p.name || p.id })),
			]
		},
	},
	/**
	 * @spec openspec/changes/lead-management/tasks.md#7.1
	 */
	async mounted() {
		await this.loadPipelines()
		await this.fetchStats()
	},
	methods: {
		/**
		 * Reduce a pipeline option to its id for NcSelect.
		 *
		 * @param {object} opt The option object.
		 * @return {string} The option id.
		 */
		reducePipeline(opt) {
			return opt.id
		},
		/**
		 * Load pipelines for the filter dropdown.
		 *
		 * @spec openspec/changes/lead-management/tasks.md#7.2
		 * @return {Promise<void>}
		 */
		async loadPipelines() {
			try {
				const items = await this.objectStore.fetchCollection('pipeline', { _limit: 100 })
				this.pipelines = Array.isArray(items) ? items : []
			} catch (e) {
				this.pipelines = []
			}
		},
		/**
		 * Fetch aggregated analytics from the backend.
		 *
		 * @spec openspec/changes/lead-management/tasks.md#7.1
		 * @return {Promise<void>}
		 */
		async fetchStats() {
			this.loading = true
			this.error = ''
			try {
				const params = {}
				if (this.selectedPipeline) {
					params.pipeline = this.selectedPipeline
				}
				const range = this.rangeToDates(this.dateRange)
				if (range.dateFrom) {
					params.dateFrom = range.dateFrom
				}
				const response = await axios.get(
					generateUrl('/apps/pipelinq/api/rapportage/pipeline-stats'),
					{ params },
				)
				this.stats = { ...EMPTY_STATS, ...(response.data || {}) }
			} catch (e) {
				this.stats = { ...EMPTY_STATS }
				this.error = t('pipelinq', 'Failed to load analytics. Please try again.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Convert a range id into an ISO 8601 from-date.
		 *
		 * @spec openspec/changes/lead-management/tasks.md#7.5
		 * @param {string} id The range id (30d/90d/12m/all).
		 * @return {{dateFrom: string|null}} The computed from-date.
		 */
		rangeToDates(id) {
			const now = new Date()
			const from = new Date(now)
			if (id === '30d') {
				from.setDate(now.getDate() - 30)
			} else if (id === '90d') {
				from.setDate(now.getDate() - 90)
			} else if (id === '12m') {
				from.setMonth(now.getMonth() - 12)
			} else {
				return { dateFrom: null }
			}
			return { dateFrom: from.toISOString().slice(0, 10) }
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.2
		 * @param {string} id The selected pipeline id.
		 * @return {void}
		 */
		onPipelineChange(id) {
			this.selectedPipeline = id || ''
			this.fetchStats()
		},
		/**
		 * @spec openspec/changes/lead-management/tasks.md#7.5
		 * @param {string} id The selected range id.
		 * @return {void}
		 */
		onRangeChange(id) {
			this.dateRange = id
			this.fetchStats()
		},
	},
}
</script>

<style scoped>
.lead-analytics {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.lead-analytics__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
}

.lead-analytics__pipeline {
	min-width: 220px;
}

.lead-analytics__loading {
	margin: 48px auto;
}

.lead-analytics__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
	gap: 16px;
}
</style>
