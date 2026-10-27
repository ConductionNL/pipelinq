<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="forecast-trend" :class="{ 'forecast-trend--embedded': embedded }">
		<div v-if="!embedded" class="forecast-trend__header">
			<h2>{{ t('pipelinq', 'Forecast trend & accuracy') }}</h2>
			<NcTextField
				v-model="ownerId"
				:label="t('pipelinq', 'Rep')"
				:placeholder="t('pipelinq', 'Rep user id')"
				@update:modelValue="loadTrend" />
		</div>
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else>
			<CnDetailCard
				:title="t('pipelinq', 'Commit / best-case / pipeline over time')"
				class="forecast-trend__chart">
				<template v-if="series.length >= 1">
					<svg
						class="forecast-trend__svg"
						viewBox="0 0 600 200"
						preserveAspectRatio="none"
						role="img"
						:aria-label="t('pipelinq', 'Forecast trend chart')">
						<polyline
							class="line line--commit"
							:points="line('commit')" />
						<polyline
							class="line line--bestcase"
							:points="line('best_case')" />
						<polyline
							class="line line--pipeline"
							:points="line('pipeline')" />
					</svg>
					<div class="forecast-trend__legend">
						<span class="legend legend--commit">{{
							t('pipelinq', 'Commit')
						}}</span>
						<span class="legend legend--bestcase">{{
							t('pipelinq', 'Best Case')
						}}</span>
						<span class="legend legend--pipeline">{{
							t('pipelinq', 'Pipeline')
						}}</span>
					</div>
				</template>
				<NcEmptyContent
					v-else
					:name="t('pipelinq', 'Not enough snapshots')"
					:description="
						t(
							'pipelinq',
							'At least one weekly snapshot is needed to show a trend.',
						)
					" />
			</CnDetailCard>
			<div class="forecast-trend__pair">
				<CnDetailCard
					:title="t('pipelinq', 'Deal movements (week over week)')"
					class="forecast-trend__delta">
					<p>
						{{ t('pipelinq', 'Moved into commit') }}:
						{{ delta?.intoCommit || t('pipelinq', 'none') }}
					</p>
					<p>
						{{ t('pipelinq', 'Moved out of commit') }}:
						{{ delta?.outOfCommit || t('pipelinq', 'none') }}
					</p>
				</CnDetailCard>
				<CnDetailCard
					:title="t('pipelinq', 'Forecast accuracy')"
					class="forecast-trend__accuracy">
					<table v-if="accuracyRows.length" class="accuracy-table">
						<thead>
							<tr>
								<th scope="col">{{ t('pipelinq', 'Rep') }}</th>
								<th scope="col">
									{{ t('pipelinq', 'Week 1 commit') }}
								</th>
								<th scope="col">
									{{ t('pipelinq', 'Actual closed won') }}
								</th>
								<th scope="col">{{ t('pipelinq', 'Accuracy') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in accuracyRows" :key="row.owner_id">
								<td>{{ row.name || row.owner_id }}</td>
								<td>{{ formatMoney(row.weekOneCommit) }}</td>
								<td>{{ formatMoney(row.actual) }}</td>
								<td :class="'accuracy--' + bandFor(row.score)">
									{{ Math.round(row.score * 100) }}%
								</td>
							</tr>
						</tbody>
					</table>
					<NcEmptyContent
						v-else
						:name="t('pipelinq', 'No closed periods yet')"
						:description="
							t(
								'pipelinq',
								'Accuracy is computed once a fiscal period closes.',
							)
						" />
				</CnDetailCard>
			</div>
		</template>
	</div>
</template>

<script>
import { CnDetailCard } from '@conduction/nextcloud-vue'
import { NcEmptyContent, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { formatEur } from '../../services/commercialFormat.js'
import { fetchSnapshots } from '../../services/forecastApi.js'
import { accuracyBand, accuracyRows, previousPeriodId, sumSnapshotsByDate } from '../../services/forecastMath.js'
import { fetchUserDisplayName } from '../../services/userDisplayName.js'

export default {
	name: 'ForecastTrend',
	components: { CnDetailCard, NcEmptyContent, NcLoadingIcon, NcTextField },

	props: {
		/** Drawn inside the forecast page: no own heading, no owner field. */
		embedded: { type: Boolean, default: false },

		/** With `embedded`: the level whose snapshots make up the trend. */
		level: { type: String, default: 'rep' },
	},

	data() {
		return {
			loading: false,
			ownerId: '',
			series: [],
			delta: null,
			accuracyRows: [],
		}
	},

	computed: {
		periodId() {
			const now = new Date()
			const quarter = Math.floor(now.getMonth() / 3) + 1
			return 'Q' + quarter + '-' + now.getFullYear()
		},

		maxValue() {
			let max = 1
			for (const point of this.series) {
				max = Math.max(max, point.commit, point.best_case, point.pipeline)
			}
			return max
		},
	},

	watch: {
		/**
		 * Read the trend again when the page shows another level.
		 *
		 * @spec exclude forecast snapshots have no owning requirement, see
		 *   ForecastSettings::load for the same gap
		 */
		level() {
			if (this.embedded) {
				this.loadTrend()
			}
		},
	},

	/**
	 * Inside the forecast page the trend loads at once for its level.
	 *
	 * @spec exclude forecast snapshots have no owning requirement, see
	 *   ForecastSettings::load for the same gap
	 */
	mounted() {
		if (this.embedded) {
			this.loadTrend()
		}
	},

	methods: {
		/**
		 * @spec exclude forecast snapshots have no owning requirement, see
		 *   ForecastSettings::load for the same gap
		 */
		async loadTrend() {
			if (!this.embedded && !this.ownerId) {
				this.series = []
				return
			}
			this.loading = true
			try {
				const data = await fetchSnapshots({
					periodId: this.periodId,
					level: this.embedded ? this.level : 'rep',
					ownerId: this.embedded ? null : this.ownerId,
					limit: 100,
				})
				this.series = sumSnapshotsByDate(data.snapshots || [])
				this.buildDelta()
				await this.loadAccuracy()
			} catch {
				this.series = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * The accuracy table: the quarter before the current one is closed, so
		 * each seller's first commit there is set against what it closed won.
		 * A failed read leaves the table empty.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/forecast-accuracy-rows/specs/forecasting/spec.md#requirement-the-forecast-shows-last-quarters-accuracy-per-seller
		 */
		async loadAccuracy() {
			const periodId = previousPeriodId(this.periodId)
			if (periodId === '') {
				this.accuracyRows = []
				return
			}
			try {
				const data = await fetchSnapshots({
					periodId,
					level: 'rep',
					ownerId: this.embedded ? null : this.ownerId,
					limit: 200,
				})
				const rows = accuracyRows(data.snapshots || [])
				const names = await Promise.all(rows.map((row) => fetchUserDisplayName(row.owner_id).catch(() => row.owner_id)))
				this.accuracyRows = rows.map((row, index) => ({ ...row, name: names[index] }))
			} catch {
				this.accuracyRows = []
			}
		},

		buildDelta() {
			if (this.series.length < 2) {
				this.delta = null
				return
			}
			const prev = this.series[this.series.length - 2]
			const curr = this.series[this.series.length - 1]
			this.delta = {
				intoCommit:
					curr.commit > prev.commit
						? this.formatMoney(curr.commit - prev.commit)
						: '',

				outOfCommit:
					curr.commit < prev.commit
						? this.formatMoney(prev.commit - curr.commit)
						: '',
			}
		},

		line(key) {
			if (!this.series.length) {
				return ''
			}
			const step = this.series.length > 1 ? 600 / (this.series.length - 1) : 0
			return this.series
				.map((point, index) => {
					const x = this.series.length > 1 ? index * step : 300
					const y = 200 - (point[key] / this.maxValue) * 190 - 5
					return x.toFixed(1) + ',' + y.toFixed(1)
				})
				.join(' ')
		},

		bandFor(score) {
			return accuracyBand(score)
		},

		/**
		 * Forecast amounts are in the reporting currency.
		 *
		 * @param {number} value The amount.
		 * @return {string} The amount, formatted.
		 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
		 */
		formatMoney(value) {
			return formatEur(Number(value || 0))
		},
	},
}
</script>

<style scoped>
.forecast-trend.forecast-trend--embedded {
	padding: 0;
	max-width: none;
}

.forecast-trend__pair {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(min(100%, 360px), 1fr));
	gap: 16px;
	margin-top: 16px;
}

.forecast-trend {
	padding: 20px;
	max-width: 1000px;
	margin: 0 auto;
}

.forecast-trend__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-end;
	gap: 16px;
	flex-wrap: wrap;
}

.forecast-trend__svg {
	width: 100%;
	height: 200px;
	background: var(--color-background-hover);
	border-radius: 8px;
}

.line {
	fill: none;
	stroke-width: 2;
}

.line--commit {
	stroke: var(--color-primary-element);
}

.line--bestcase {
	stroke: #f59e0b;
}

.line--pipeline {
	stroke: var(--color-text-maxcontrast);
}

.forecast-trend__legend {
	display: flex;
	gap: 16px;
	margin-top: 8px;
}

.legend::before {
	content: '■ ';
}

.legend--commit::before {
	color: var(--color-primary-element);
}

.legend--bestcase::before {
	color: #f59e0b;
}

.legend--pipeline::before {
	color: var(--color-text-maxcontrast);
}

.accuracy-table {
	width: 100%;
	border-collapse: collapse;
}

.accuracy-table th,
.accuracy-table td {
	text-align: start;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.accuracy--green {
	color: var(--color-success);
	font-weight: 600;
}

.accuracy--amber {
	color: #f59e0b;
	font-weight: 600;
}

.accuracy--red {
	color: var(--color-error);
	font-weight: 600;
}
</style>
