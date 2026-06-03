<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="sla-attainment">
		<div class="sla-attainment__header">
			<h2>{{ t('pipelinq', 'SLA attainment') }}</h2>
		</div>

		<div class="sla-attainment__controls">
			<div class="control-group">
				<NcButton
					v-for="opt in bucketOptions"
					:key="opt.value"
					:type="bucket === opt.value ? 'primary' : 'secondary'"
					@click="selectBucket(opt.value)">
					{{ opt.label }}
				</NcButton>
			</div>
			<div class="control-group">
				<NcButton
					v-for="opt in groupOptions"
					:key="opt.value"
					:type="groupBy === opt.value ? 'primary' : 'secondary'"
					@click="selectGroup(opt.value)">
					{{ opt.label }}
				</NcButton>
			</div>
			<NcButton :disabled="loading || rows.length === 0" @click="exportCsv">
				{{ t('pipelinq', 'Export CSV') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<div class="kpi-card">
				<span class="kpi-card__value">{{ attainmentPct }}%</span>
				<span class="kpi-card__label">{{ t('pipelinq', 'Attainment') }}</span>
				<span class="kpi-card__sub">
					{{ report.met }} / {{ report.total }} {{ t('pipelinq', 'SLA met') }}
				</span>
			</div>

			<div class="breach-summary">
				<span>{{ t('pipelinq', 'Breached') }}: {{ report.breached }}</span>
				<span>{{ t('pipelinq', 'In flight') }}: {{ report.inFlightBreached }}</span>
				<span>{{ t('pipelinq', 'Closed') }}: {{ report.closedBreached }}</span>
			</div>

			<table class="data-table">
				<thead>
					<tr>
						<th>{{ groupColumnLabel }}</th>
						<th>{{ t('pipelinq', 'Total') }}</th>
						<th>{{ t('pipelinq', 'SLA met') }}</th>
						<th>{{ t('pipelinq', 'Breached') }}</th>
						<th>{{ t('pipelinq', 'Attainment') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.groupKey">
						<td>{{ row.groupName }}</td>
						<td>{{ row.total }}</td>
						<td>{{ row.met }}</td>
						<td>{{ row.breached }}</td>
						<td>{{ Math.round((row.attainment || 0) * 100) }}%</td>
					</tr>
				</tbody>
			</table>

			<div v-if="rows.length === 0" class="empty-state">
				<p>{{ t('pipelinq', 'No data available for the selected period') }}</p>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'SlaAttainment',
	components: { NcButton, NcLoadingIcon },
	data() {
		return {
			loading: false,
			bucket: 'quarter',
			groupBy: 'policy',
			report: { attainment: 0, total: 0, met: 0, breached: 0, inFlightBreached: 0, closedBreached: 0, details: { byGroup: [] } },
			bucketOptions: [
				{ value: 'day', label: t('pipelinq', 'Day') },
				{ value: 'week', label: t('pipelinq', 'Week') },
				{ value: 'month', label: t('pipelinq', 'Month') },
				{ value: 'quarter', label: t('pipelinq', 'Quarter') },
			],
			groupOptions: [
				{ value: 'policy', label: t('pipelinq', 'Policy') },
				{ value: 'customer', label: t('pipelinq', 'Customer') },
				{ value: 'tier', label: t('pipelinq', 'Customer tier') },
				{ value: 'team', label: t('pipelinq', 'Team') },
			],
		}
	},
	computed: {
		attainmentPct() {
			return Math.round((this.report.attainment || 0) * 100)
		},
		rows() {
			return (this.report.details && this.report.details.byGroup) || []
		},
		groupColumnLabel() {
			const found = this.groupOptions.find((o) => o.value === this.groupBy)
			return found ? found.label : t('pipelinq', 'Group')
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		selectBucket(value) {
			this.bucket = value
			this.fetchData()
		},
		selectGroup(value) {
			this.groupBy = value
			this.fetchData()
		},
		/**
		 * Fetch the attainment report from the server-authoritative endpoint.
		 *
		 * @spec openspec/changes/sla-engine-and-escalation/specs/sla-engine-and-escalation/spec.md#REQ-006
		 */
		async fetchData() {
			this.loading = true
			try {
				const params = { bucket: this.bucket, groupBy: this.groupBy }
				const now = new Date()
				if (this.bucket === 'quarter') {
					params.quarter = `${now.getFullYear()}-Q${Math.floor(now.getMonth() / 3) + 1}`
				} else if (this.bucket === 'month') {
					params.month = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
				} else if (this.bucket === 'day') {
					params.date = now.toISOString().slice(0, 10)
				}
				const response = await axios.get(generateUrl('/apps/pipelinq/api/sla/attainment'), { params })
				this.report = response.data
			} catch (error) {
				this.report = { attainment: 0, total: 0, met: 0, breached: 0, inFlightBreached: 0, closedBreached: 0, details: { byGroup: [] } }
			} finally {
				this.loading = false
			}
		},
		exportCsv() {
			const header = ['group', 'total', 'met', 'breached', 'attainment']
			const lines = this.rows.map((r) => [r.groupName, r.total, r.met, r.breached, r.attainment].join(','))
			const csv = [header.join(','), ...lines].join('\n')
			const blob = new Blob([csv], { type: 'text/csv' })
			const url = URL.createObjectURL(blob)
			const link = document.createElement('a')
			link.href = url
			link.download = `sla-attainment-${this.bucket}.csv`
			link.click()
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped>
.sla-attainment { padding: 20px; max-width: 1000px; margin: 0 auto; }

.sla-attainment__header { margin-bottom: 16px; }

.sla-attainment__controls { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; margin-bottom: 20px; }

.control-group { display: flex; gap: 4px; }

.kpi-card { display: flex; flex-direction: column; align-items: flex-start; padding: 20px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); margin-bottom: 16px; }

.kpi-card__value { font-size: 2.4em; font-weight: 700; color: var(--color-primary-element); }

.kpi-card__label { font-size: 0.9em; color: var(--color-text-lighter); text-transform: uppercase; }

.kpi-card__sub { margin-top: 4px; color: var(--color-text-lighter); }

.breach-summary { display: flex; gap: 24px; margin-bottom: 16px; color: var(--color-text-lighter); }

.data-table { width: 100%; border-collapse: collapse; }

.data-table th, .data-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }

.data-table th { font-weight: 600; font-size: 0.85em; color: var(--color-text-lighter); text-transform: uppercase; }

.empty-state { padding: 40px; text-align: center; color: var(--color-text-lighter); }
</style>
