<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="forecast-dashboard">
		<div class="forecast-dashboard__header">
			<h2>{{ t('pipelinq', 'Forecast') }}</h2>
			<div class="forecast-dashboard__controls">
				<NcSelect
					v-model="selectedLevel"
					:input-label="t('pipelinq', 'Level')"
					:options="levelOptions"
					label="label"
					:clearable="false"
					@update:model-value="loadSnapshots" />
				<NcButton variant="secondary" @click="exportCsv">
					{{ t('pipelinq', 'Export CSV') }}
				</NcButton>
			</div>
		</div>

		<NcNoteCard v-if="atRisk" type="warning" class="forecast-dashboard__atrisk">
			{{ atRiskMessage }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<div class="forecast-summary">
				<div class="forecast-summary__metric">
					<span class="forecast-summary__label">{{ t('pipelinq', 'Quota') }}</span>
					<span class="forecast-summary__value">{{ formatMoney(summary.quota) }}</span>
				</div>
				<div class="forecast-summary__metric">
					<span class="forecast-summary__label">{{ t('pipelinq', 'Closed Won') }}</span>
					<span class="forecast-summary__value">{{ formatMoney(summary.closedWon) }}</span>
				</div>
				<div class="forecast-summary__metric">
					<span class="forecast-summary__label">{{ t('pipelinq', 'Committed') }}</span>
					<span class="forecast-summary__value">{{ formatMoney(summary.commit) }}</span>
				</div>
				<div class="forecast-summary__metric">
					<span class="forecast-summary__label">{{ t('pipelinq', 'Gap to close') }}</span>
					<span class="forecast-summary__value">{{ formatMoney(summary.gap) }}</span>
				</div>
			</div>

			<div class="forecast-progress" :aria-label="progressLabel">
				<div class="forecast-progress__bar">
					<div class="forecast-progress__seg forecast-progress__seg--closed"
						:style="{ width: pct(summary.closedWon) + '%' }" />
					<div class="forecast-progress__seg forecast-progress__seg--commit"
						:style="{ width: pct(summary.commit) + '%' }" />
				</div>
				<span class="forecast-progress__label">{{ progressLabel }}</span>
			</div>

			<table v-if="rows.length" class="forecast-table">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Owner') }}</th>
						<th scope="col">{{ t('pipelinq', 'Commit') }}</th>
						<th scope="col">{{ t('pipelinq', 'Best Case') }}</th>
						<th scope="col">{{ t('pipelinq', 'Pipeline') }}</th>
						<th scope="col">{{ t('pipelinq', 'Closed Won') }}</th>
						<th scope="col">{{ t('pipelinq', 'Quota') }}</th>
						<th scope="col" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="row.owner_id">
						<td>{{ row.owner_id }}</td>
						<td>
							<span :class="{ 'forecast-table__overridden': hasOverride(row) }">
								{{ formatMoney(row.commit) }}
							</span>
							<span v-if="hasOverride(row)"
								class="forecast-table__badge"
								:title="overrideTitle(row)">
								▼ {{ t('pipelinq', 'override') }}
							</span>
						</td>
						<td>{{ formatMoney(row.best_case) }}</td>
						<td>{{ formatMoney(row.pipeline) }}</td>
						<td>{{ formatMoney(row.closed_won) }}</td>
						<td>{{ row.quota === null ? '—' : formatMoney(row.quota) }}</td>
						<td>
							<NcButton variant="tertiary" @click="openOverride(row)">
								{{ t('pipelinq', 'Override') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<NcEmptyContent v-else
				:name="t('pipelinq', 'No snapshots yet')"
				:description="t('pipelinq', 'Forecast snapshots are generated every Monday. Check back after the next run.')" />
		</template>

		<ForecastOverrideModal
			v-if="overrideTarget"
			:period-id="periodId"
			:owner-id="overrideTarget.owner_id"
			:level="childLevel"
			:category="'commit'"
			:current-amount="overrideTarget.commit"
			@close="overrideTarget = null"
			@saved="onOverrideSaved" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import ForecastOverrideModal from '../../modals/ForecastOverrideModal.vue'
import { fetchSnapshots, csvExportUrl } from '../../services/forecastApi.js'
import { projectedAttainment, gapToQuota, isAtRisk, attainmentPercent } from '../../services/forecastMath.js'

export default {
	name: 'ForecastDashboard',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, ForecastOverrideModal },
	data() {
		return {
			loading: false,
			selectedLevel: { id: 'team', label: t('pipelinq', 'Team') },
			rows: [],
			overrideTarget: null,
			daysRemaining: 30,
		}
	},
	computed: {
		levelOptions() {
			return [
				{ id: 'company', label: t('pipelinq', 'Company') },
				{ id: 'division', label: t('pipelinq', 'Division') },
				{ id: 'team', label: t('pipelinq', 'Team') },
				{ id: 'rep', label: t('pipelinq', 'Rep') },
			]
		},
		level() {
			return this.selectedLevel?.id || 'team'
		},
		childLevel() {
			return { company: 'division', division: 'team', team: 'rep', rep: 'rep' }[this.level] || 'rep'
		},
		periodId() {
			const now = new Date()
			const quarter = Math.floor(now.getMonth() / 3) + 1
			return 'Q' + quarter + '-' + now.getFullYear()
		},
		summary() {
			const commit = this.sum('commit')
			const bestCase = this.sum('best_case')
			const closedWon = this.sum('closed_won')
			const quota = this.sum('quota')
			const projected = projectedAttainment(closedWon, commit, bestCase)
			return { commit, bestCase, closedWon, quota, projected, gap: gapToQuota(quota, projected) }
		},
		atRisk() {
			return isAtRisk(this.summary.projected, this.summary.quota, this.daysRemaining)
		},
		atRiskMessage() {
			const pct = 100 - attainmentPercent(this.summary.projected, this.summary.quota)
			return t('pipelinq', 'This team is {gap}% below quota with {days} days to close. Action recommended.',
				{ gap: pct, days: this.daysRemaining })
		},
		progressLabel() {
			const onTrack = this.summary.closedWon + this.summary.commit
			return t('pipelinq', '{value} of {quota} on track ({percent}%)', {
				value: this.formatMoney(onTrack),
				quota: this.formatMoney(this.summary.quota),
				percent: attainmentPercent(onTrack, this.summary.quota),
			})
		},
	},
	mounted() {
		this.loadSnapshots()
	},
	methods: {
		sum(key) {
			return this.rows.reduce((acc, row) => acc + Number(row[key] || 0), 0)
		},
		pct(value) {
			return Math.min(100, attainmentPercent(value, this.summary.quota))
		},
		hasOverride(row) {
			return !!row.commit_override_reason
		},
		overrideTitle(row) {
			return t('pipelinq', 'Rep submitted: {original} — {reason}', {
				original: this.formatMoney(row.original_commit),
				reason: row.commit_override_reason,
			})
		},
		formatMoney(value) {
			const num = Number(value || 0)
			return '€' + num.toLocaleString('nl-NL', { maximumFractionDigits: 0 })
		},
		async loadSnapshots() {
			this.loading = true
			try {
				const data = await fetchSnapshots({ periodId: this.periodId, level: this.childLevel })
				this.rows = data.snapshots || []
			} catch (error) {
				this.rows = []
			} finally {
				this.loading = false
			}
		},
		openOverride(row) {
			this.overrideTarget = row
		},
		onOverrideSaved() {
			this.overrideTarget = null
			this.loadSnapshots()
		},
		exportCsv() {
			window.location.href = csvExportUrl({ periodId: this.periodId, level: this.childLevel })
		},
	},
}
</script>

<style scoped>
.forecast-dashboard { padding: 20px; max-width: 1200px; margin: 0 auto; }

.forecast-dashboard__header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }

.forecast-dashboard__controls { display: flex; gap: 12px; align-items: flex-end; }

.forecast-dashboard__atrisk { margin: 16px 0; }

.forecast-summary { display: flex; gap: 24px; flex-wrap: wrap; margin: 20px 0; }

.forecast-summary__metric { display: flex; flex-direction: column; }

.forecast-summary__label { color: var(--color-text-maxcontrast); font-size: 0.85em; }

.forecast-summary__value { font-size: 1.4em; font-weight: 600; }

.forecast-progress { margin: 16px 0 24px; }

.forecast-progress__bar { display: flex; height: 20px; border-radius: 10px; overflow: hidden; background: var(--color-background-dark); }

.forecast-progress__seg--closed { background: var(--color-success); }

.forecast-progress__seg--commit { background: var(--color-primary-element); opacity: 0.7; background-image: repeating-linear-gradient(45deg, transparent, transparent 4px, rgba(255,255,255,0.25) 4px, rgba(255,255,255,0.25) 8px); }

.forecast-progress__label { display: block; margin-top: 6px; color: var(--color-text-maxcontrast); }

.forecast-table { width: 100%; border-collapse: collapse; margin-top: 12px; }

.forecast-table th, .forecast-table td { text-align: left; padding: 8px 12px; border-bottom: 1px solid var(--color-border); }

.forecast-table__overridden { color: var(--color-error); font-weight: 600; }

.forecast-table__badge { margin-left: 6px; font-size: 0.8em; color: var(--color-error); cursor: help; }
</style>
