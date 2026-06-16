<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/klantbeeld-360/tasks.md#task-3.1 -->
<template>
	<CnDetailPage
		:title="t('pipelinq', 'Pipeline Analytics')"
		:subtitle="selectedPipeline ? selectedPipeline.title : t('pipelinq', 'Select a pipeline')"
		:back-route="{ name: 'Dashboard' }"
		:back-label="t('pipelinq', 'Back to dashboard')"
		:loading="loading"
		:sidebar="{ enabled: false }">
		<template #actions>
			<NcSelect
				v-model="selectedPipelineId"
				:input-label="t('pipelinq', 'Pipeline')"
				:placeholder="t('pipelinq', 'Select pipeline')"
				:options="pipelineOptions"
				label="label"
				track-by="value"
				:reduce="opt => opt.value"
				:clearable="false"
				class="pipeline-analytics__selector"
				@input="onPipelineChange" />
		</template>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<CnKpiGrid>
			<CnStatsBlock
				:title="t('pipelinq', 'Total Pipeline Value')"
				:count="totalValue"
				:count-label="t('pipelinq', 'open opportunities')"
				:icon="CurrencyEur"
				variant="primary"
				horizontal>
				<template #value>
					{{ totalValueLabel }}
				</template>
			</CnStatsBlock>
			<CnStatsBlock
				:title="t('pipelinq', 'Win Rate')"
				:count="winRate ?? 0"
				:count-label="t('pipelinq', 'closed deals')"
				:icon="TrophyOutline"
				horizontal>
				<template #value>
					{{ winRateLabel }}
				</template>
			</CnStatsBlock>
			<CnStatsBlock
				:title="t('pipelinq', 'Average Deal Size')"
				:count="avgDealSize ?? 0"
				:count-label="t('pipelinq', 'per active opportunity')"
				:icon="ChartBar"
				horizontal>
				<template #value>
					{{ avgDealSizeLabel }}
				</template>
			</CnStatsBlock>
			<CnStatsBlock
				:title="t('pipelinq', 'Active Opportunities')"
				:count="openLeads.length"
				:count-label="t('pipelinq', 'in active stages')"
				:icon="TrendingUp"
				horizontal />
		</CnKpiGrid>

		<CnDetailCard :title="t('pipelinq', 'Stage Funnel')">
			<NcEmptyContent
				v-if="stageData.length === 0"
				:name="t('pipelinq', 'No leads in this pipeline')"
				:description="t('pipelinq', 'Once leads are added, their stage distribution will appear here.')" />
			<CnChartWidget
				v-else
				:title="t('pipelinq', 'Leads per stage')"
				:series="chartSeries"
				:categories="chartCategories"
				type="bar"
				:horizontal="true"
				:height="320" />
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { NcSelect, NcNoteCard, NcEmptyContent } from '@nextcloud/vue'
import {
	CnDetailPage,
	CnDetailCard,
	CnKpiGrid,
	CnStatsBlock,
	CnChartWidget,
} from '@conduction/nextcloud-vue'
import CurrencyEur from 'vue-material-design-icons/CurrencyEur.vue'
import TrophyOutline from 'vue-material-design-icons/TrophyOutline.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { useSettingsStore } from '../../store/modules/settings.js'

/**
 * Per-pipeline sales analytics (Klantbeeld 360 Feature 2).
 *
 * Renders four KPI cards (Total Pipeline Value, Win Rate, Avg Deal Size,
 * Active Opportunities) and a horizontal-bar stage-funnel chart.
 * Pipeline lead counts are bounded (< 500) so client-side aggregation is
 * the right tradeoff vs a dedicated backend endpoint — KPIs update
 * instantly on pipeline change.
 *
 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-010
 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-011
 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-012
 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-013
 */
export default {
	name: 'PipelineAnalyticsView',
	components: {
		NcSelect,
		NcNoteCard,
		NcEmptyContent,
		CnDetailPage,
		CnDetailCard,
		CnKpiGrid,
		CnStatsBlock,
		CnChartWidget,
	},
	data() {
		return {
			CurrencyEur,
			TrophyOutline,
			ChartBar,
			TrendingUp,
			loading: false,
			error: null,
			selectedPipelineId: null,
			leads: [],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		settingsStore() {
			return useSettingsStore()
		},
		pipelines() {
			return this.objectStore.collections.pipeline || []
		},
		pipelineOptions() {
			return this.pipelines.map(p => ({ value: p.id, label: p.title || p.name || p.id }))
		},
		selectedPipeline() {
			if (!this.selectedPipelineId) return null
			return this.pipelines.find(p => p.id === this.selectedPipelineId) || null
		},
		/**
		 * Open / active leads — those still being worked. The pipelinq
		 * lead schema enum is `open|won|lost` (see
		 * `lib/Settings/pipelinq_register.json -> lead.status`); the
		 * spec wording says "active" as a shorthand. We accept both
		 * values so this view works regardless of which value the
		 * schema ships with.
		 *
		 * @return {Array<object>}
		 */
		openLeads() {
			return this.leads.filter(l => l.status === 'open' || l.status === 'active')
		},
		/**
		 * Leads marked as won.
		 *
		 * @return {Array<object>}
		 */
		wonLeads() {
			return this.leads.filter(l => l.status === 'won')
		},
		/**
		 * Leads marked as lost.
		 *
		 * @return {Array<object>}
		 */
		lostLeads() {
			return this.leads.filter(l => l.status === 'lost')
		},
		/**
		 * Sum of `value` over open leads.
		 *
		 * @return {number}
		 */
		totalValue() {
			return this.openLeads.reduce((sum, l) => sum + (Number(l.value) || 0), 0)
		},
		/**
		 * Win rate as a decimal (or null when no closed deals).
		 *
		 * @return {number|null}
		 */
		winRate() {
			const closed = this.wonLeads.length + this.lostLeads.length
			if (closed === 0) return null
			return this.wonLeads.length / closed
		},
		/**
		 * Average open-deal size (null when no active leads).
		 *
		 * @return {number|null}
		 */
		avgDealSize() {
			if (this.openLeads.length === 0) return null
			return this.totalValue / this.openLeads.length
		},
		/**
		 * Aggregated lead count per stage, ordered by stageOrder ascending.
		 *
		 * @return {Array<{stage: string, count: number}>}
		 */
		stageData() {
			const buckets = new Map()
			for (const lead of this.leads) {
				const stage = String(lead.stage || this.t('pipelinq', 'Unknown'))
				const order = Number(lead.stageOrder ?? Number.MAX_SAFE_INTEGER)
				const existing = buckets.get(stage)
				if (existing) {
					existing.count += 1
				} else {
					buckets.set(stage, { stage, count: 1, order })
				}
			}
			return Array.from(buckets.values()).sort((a, b) => a.order - b.order)
		},
		chartCategories() {
			return this.stageData.map(d => d.stage)
		},
		chartSeries() {
			return [{
				name: this.t('pipelinq', 'Leads'),
				data: this.stageData.map(d => d.count),
			}]
		},
		totalValueLabel() {
			return this.formatCurrency(this.totalValue)
		},
		winRateLabel() {
			if (this.winRate === null) return '—'
			return Math.round(this.winRate * 100) + '%'
		},
		avgDealSizeLabel() {
			if (this.avgDealSize === null) return '—'
			return this.formatCurrency(this.avgDealSize)
		},
	},
	async mounted() {
		await this.bootstrap()
	},
	methods: {
		/**
		 * Ensure pipelinq object types are registered + fetch pipelines, then
		 * auto-select the default pipeline.
		 *
		 * @return {Promise<void>}
		 */
		async bootstrap() {
			this.loading = true
			this.error = null
			try {
				await this.ensureObjectTypes(['pipeline', 'lead'])
				await this.objectStore.fetchCollection('pipeline', { _limit: 100 })
				if (this.pipelines.length > 0) {
					const defaultPipeline = this.pipelines.find(p => p.isDefault) || this.pipelines[0]
					this.selectedPipelineId = defaultPipeline.id
					await this.fetchLeads()
				}
			} catch (e) {
				this.error = this.t('pipelinq', 'Failed to load pipelines. Please try again.')
				// eslint-disable-next-line no-console
				console.warn('[PipelineAnalyticsView] bootstrap failed', e)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Register pipelinq schemas on the objectStore if not already known.
		 *
		 * @param {Array<string>} slugs Schema slugs to ensure.
		 * @return {Promise<void>}
		 */
		async ensureObjectTypes(slugs) {
			const registry = this.objectStore.objectTypeRegistry || {}
			const missing = slugs.filter(s => !registry[s])
			if (missing.length === 0) return
			let config = this.settingsStore.getConfig
			if (!config) {
				config = await this.settingsStore.fetchSettings()
			}
			if (!config || !config.register) return
			for (const slug of missing) {
				const schemaId = config[slug + '_schema']
				if (schemaId) {
					this.objectStore.registerObjectType(slug, schemaId, config.register)
				}
			}
		},
		/**
		 * Handle pipeline-selector change — refresh leads.
		 *
		 * @return {Promise<void>}
		 */
		async onPipelineChange() {
			await this.fetchLeads()
		},
		/**
		 * Fetch all leads for the currently-selected pipeline.
		 *
		 * @return {Promise<void>}
		 */
		async fetchLeads() {
			if (!this.selectedPipelineId) {
				this.leads = []
				return
			}
			this.loading = true
			this.error = null
			try {
				const leads = await this.objectStore.fetchCollection('lead', {
					pipeline: this.selectedPipelineId,
					_limit: 500,
				})
				this.leads = leads || []
			} catch (e) {
				this.leads = []
				this.error = this.t('pipelinq', 'Failed to load leads for this pipeline.')
				// eslint-disable-next-line no-console
				console.warn('[PipelineAnalyticsView] fetchLeads failed', e)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Format a number as Dutch EUR.
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
.pipeline-analytics__selector {
	min-width: 220px;
}
</style>
