<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="forecast-trend">
		<div class="forecast-trend__header">
			<h2>{{ t('pipelinq', 'Forecast trend & accuracy') }}</h2>
			<NcTextField
				v-model="ownerId"
				:label="t('pipelinq', 'Rep')"
				:placeholder="t('pipelinq', 'Rep user id')"
				@update:modelValue="loadTrend" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<section v-if="series.length >= 1" class="forecast-trend__chart">
				<h3>
					{{ t('pipelinq', 'Commit / best-case / pipeline over time') }}
				</h3>
				<svg
					class="forecast-trend__svg"
					viewBox="0 0 600 200"
					preserveAspectRatio="none"
					role="img"
					:aria-label="t('pipelinq', 'Forecast trend chart')">
					<polyline class="line line--commit" :points="line('commit')" />
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
			</section>

			<NcEmptyContent
				v-else
				:name="t('pipelinq', 'Not enough snapshots')"
				:description="
					t(
						'pipelinq',
						'At least one weekly snapshot is needed to show a trend.',
					)
				" />

			<section v-if="delta" class="forecast-trend__delta">
				<h3>{{ t('pipelinq', 'Deal movements (week over week)') }}</h3>
				<p>
					{{ t('pipelinq', 'Moved into commit') }}:
					{{ delta.intoCommit || t('pipelinq', 'none') }}
				</p>
				<p>
					{{ t('pipelinq', 'Moved out of commit') }}:
					{{ delta.outOfCommit || t('pipelinq', 'none') }}
				</p>
			</section>

			<section class="forecast-trend__accuracy">
				<h3>{{ t('pipelinq', 'Forecast accuracy') }}</h3>
				<table v-if="accuracyRows.length" class="accuracy-table">
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Rep') }}</th>
							<th scope="col">{{ t('pipelinq', 'Week 1 commit') }}</th>
							<th scope="col">
								{{ t('pipelinq', 'Actual closed won') }}
							</th>
							<th scope="col">{{ t('pipelinq', 'Accuracy') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in accuracyRows" :key="row.owner_id">
							<td>{{ row.owner_id }}</td>
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
			</section>
		</template>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { fetchSnapshots } from '../../services/forecastApi.js'
import { accuracyBand } from '../../services/forecastMath.js'

export default {
	name: 'ForecastTrend',
	components: { NcEmptyContent, NcLoadingIcon, NcTextField },
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

	methods: {
		async loadTrend() {
			if (!this.ownerId) {
				this.series = []
				return
			}
			this.loading = true
			try {
				const data = await fetchSnapshots({
					periodId: this.periodId,
					level: 'rep',
					ownerId: this.ownerId,
					limit: 100,
				})
				this.series = (data.snapshots || []).map((s) => ({
					date: s.as_of_date,
					commit: Number(s.commit || 0),
					best_case: Number(s.best_case || 0),
					pipeline: Number(s.pipeline || 0),
				}))
				this.buildDelta()
			} catch (error) {
				this.series = []
			} finally {
				this.loading = false
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

		formatMoney(value) {
			return (
				'€'
				+ Number(value || 0).toLocaleString('nl-NL', {
					maximumFractionDigits: 0,
				})
			)
		},
	},
}
</script>

<style scoped>
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

.forecast-trend__delta,
.forecast-trend__accuracy {
	margin-top: 24px;
}

.accuracy-table {
	width: 100%;
	border-collapse: collapse;
}

.accuracy-table th,
.accuracy-table td {
	text-align: left;
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
