<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/sla-engine-and-escalation/tasks.md#12.2 -->
<template>
	<CnDashboardPage
		:title="t('pipelinq', 'SLA attainment')"
		:subtitle="t('pipelinq', 'Service Level Agreement performance per policy / tier / target')"
		:loading="loading"
		:refreshing="loading"
		@refresh="fetchAttainment">
		<template #header-actions>
			<NcSelect
				v-model="selectedBucket"
				:input-label="t('pipelinq', 'Period')"
				:options="bucketOptions"
				label="label"
				track-by="value"
				:reduce="opt => opt.value"
				:clearable="false"
				@input="fetchAttainment" />
			<NcSelect
				v-model="selectedGroupBy"
				:input-label="t('pipelinq', 'Group by')"
				:options="groupOptions"
				label="label"
				track-by="value"
				:reduce="opt => opt.value"
				:clearable="false"
				@input="fetchAttainment" />
		</template>

		<NcNoteCard v-if="error" type="error" class="sla-dashboard__error">
			{{ error }}
		</NcNoteCard>

		<CnKpiGrid>
			<CnStatsBlock
				:title="t('pipelinq', 'Overall attainment')"
				:count="attainmentPct"
				:count-label="t('pipelinq', 'of all targets')"
				:icon="CheckCircle"
				variant="primary"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Total')"
				:count="payload.total"
				:count-label="t('pipelinq', 'targets in period')"
				:icon="ChartLine"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Met')"
				:count="payload.met"
				:count-label="t('pipelinq', 'on-time')"
				:icon="CheckCircle"
				horizontal />
			<CnStatsBlock
				:title="t('pipelinq', 'Breached')"
				:count="payload.breached"
				:count-label="t('pipelinq', 'over-deadline')"
				:icon="AlertOctagon"
				horizontal />
		</CnKpiGrid>

		<section v-if="byGroup.length > 0" class="sla-dashboard__group">
			<h3>{{ t('pipelinq', 'Breakdown by {group}', { group: groupLabel }) }}</h3>
			<table class="sla-dashboard__table">
				<thead>
					<tr>
						<th>{{ groupLabel }}</th>
						<th class="num">
							{{ t('pipelinq', 'Total') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'Met') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'Breached') }}
						</th>
						<th class="num">
							{{ t('pipelinq', 'Attainment') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in byGroup" :key="row.groupKey">
						<td>{{ row.groupName }}</td>
						<td class="num">
							{{ row.total }}
						</td>
						<td class="num">
							{{ row.met }}
						</td>
						<td class="num">
							{{ row.breached }}
						</td>
						<td class="num">
							{{ percent(row.attainment) }}
						</td>
					</tr>
				</tbody>
			</table>
		</section>
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
import AlertOctagon from 'vue-material-design-icons/AlertOctagonOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircleOutline.vue'

/**
 * SLA attainment dashboard (sla-engine-and-escalation Feature 12).
 *
 * Renders an overall attainment KPI, totals/met/breached counts and a
 * sortable breakdown table grouped by policy / customer-tier /
 * assignee-team / target-kind. Data is fetched from
 * `GET /api/sla/attainment?bucket=...&groupBy=...`.
 *
 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-006
 */
export default {
	name: 'SlaAttainmentDashboard',
	components: {
		NcSelect,
		NcNoteCard,
		CnDashboardPage,
		CnKpiGrid,
		CnStatsBlock,
	},
	data() {
		return {
			AlertOctagon,
			ChartLine,
			CheckCircle,
			loading: false,
			error: null,
			selectedBucket: 'month',
			selectedGroupBy: 'policy',
			payload: {
				attainment: 0,
				total: 0,
				met: 0,
				breached: 0,
				inFlightBreached: 0,
				closedBreached: 0,
				details: { byTarget: {}, byGroup: [] },
			},
		}
	},
	computed: {
		bucketOptions() {
			return [
				{ value: 'day', label: this.t('pipelinq', 'Day') },
				{ value: 'week', label: this.t('pipelinq', 'Week') },
				{ value: 'month', label: this.t('pipelinq', 'Month') },
				{ value: 'quarter', label: this.t('pipelinq', 'Quarter') },
			]
		},
		groupOptions() {
			return [
				{ value: 'policy', label: this.t('pipelinq', 'Policy') },
				{ value: 'tier', label: this.t('pipelinq', 'Customer tier') },
				{ value: 'team', label: this.t('pipelinq', 'Team') },
				{ value: 'target', label: this.t('pipelinq', 'Target kind') },
				{ value: 'customer', label: this.t('pipelinq', 'Customer') },
			]
		},
		groupLabel() {
			const match = this.groupOptions.find(o => o.value === this.selectedGroupBy)
			return match ? match.label : this.t('pipelinq', 'Group')
		},
		byGroup() {
			return (this.payload.details && this.payload.details.byGroup) || []
		},
		attainmentPct() {
			return this.percent(this.payload.attainment)
		},
	},
	mounted() {
		this.fetchAttainment()
	},
	methods: {
		/**
		 * Fetch attainment payload from the SlaAttainmentController.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-006
		 */
		async fetchAttainment() {
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/pipelinq/api/sla/attainment')
				const params = { bucket: this.selectedBucket, groupBy: this.selectedGroupBy }
				const now = new Date()
				if (this.selectedBucket === 'day') {
					params.date = now.toISOString().substring(0, 10)
				} else if (this.selectedBucket === 'month') {
					params.month = now.toISOString().substring(0, 7)
				} else if (this.selectedBucket === 'week') {
					params.week = this.toIsoWeek(now)
				} else if (this.selectedBucket === 'quarter') {
					const q = Math.floor(now.getUTCMonth() / 3) + 1
					params.quarter = `${now.getUTCFullYear()}-Q${q}`
				}
				const response = await axios.get(url, { params })
				this.payload = response.data || this.payload
			} catch (e) {
				this.error = this.t('pipelinq', 'Failed to load SLA attainment. Please try again.')
				// eslint-disable-next-line no-console
				console.warn('[SlaAttainmentDashboard] fetch failed', e)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Format a decimal ratio as a percentage string (e.g. 0.923 → '92.3%').
		 *
		 * @param {number} value Decimal ratio (0..1).
		 * @return {string}
		 */
		percent(value) {
			const n = Number(value) || 0
			return `${(n * 100).toFixed(1)}%`
		},
		/**
		 * Build an ISO 8601 week label (YYYY-Wnn).
		 *
		 * @param {Date} date Reference date.
		 * @return {string}
		 */
		toIsoWeek(date) {
			const d = new Date(Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate()))
			const dayNum = d.getUTCDay() || 7
			d.setUTCDate(d.getUTCDate() + 4 - dayNum)
			const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1))
			const weekNo = Math.ceil((((d - yearStart) / 86400000) + 1) / 7)
			return `${d.getUTCFullYear()}-W${String(weekNo).padStart(2, '0')}`
		},
	},
}
</script>

<style lang="scss" scoped>
.sla-dashboard__group {
	margin-top: 24px;

	h3 {
		margin: 0 0 12px;
		font-weight: 600;
	}
}

.sla-dashboard__table {
	width: 100%;
	border-collapse: collapse;

	th,
	td {
		padding: 8px 12px;
		border-bottom: 1px solid var(--color-border);
		text-align: left;

		&.num {
			text-align: right;
		}
	}

	th {
		font-weight: 600;
		background: var(--color-background-hover);
	}
}
</style>
