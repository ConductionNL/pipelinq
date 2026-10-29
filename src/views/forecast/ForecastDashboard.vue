<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="forecast-dashboard">
		<CnPageHeader
			:title="t('pipelinq', 'Forecast')"
			:description="subtitle">
			<template #extra>
				<div class="forecast-dashboard__header-actions">
					<NcButton
						class="forecast-dashboard__download"
						data-testid="forecast-download"
						@click="exportCsv">
						<template #icon>
							<Download :size="20" />
						</template>
						{{ t('pipelinq', 'Download') }}
					</NcButton>
					<CnBuildiqEditButton />
				</div>
			</template>
		</CnPageHeader>
		<CnSegmentedControl
			:modelValue="level"
			:options="levelOptions"
			:ariaLabel="t('pipelinq', 'Level')"
			size="compact"
			class="forecast-dashboard__levels"
			@update:modelValue="setLevel" />
		<NcLoadingIcon v-if="loading" :size="32" />
		<template v-else>
			<div class="forecast-tiles">
				<CnDetailCard
					v-for="tile in tiles"
					:key="tile.id"
					class="forecast-tile">
					<span class="forecast-tile__label">{{ tile.label }}</span>
					<strong
						class="forecast-tile__value"
						:class="{ 'forecast-tile__value--gap': tile.warn }"
						>{{ tile.value }}</strong
					>
					<span v-if="tile.caption" class="forecast-tile__caption">{{
						tile.caption
					}}</span>
				</CnDetailCard>
			</div>
			<CnDetailCard :title="quarterTitle" class="forecast-quarter">
				<template #actions>
					<NcButton
						class="cn-button--tonal"
						variant="secondary"
						data-testid="forecast-export-csv"
						@click="exportCsv">
						<template #icon>
							<Download :size="16" />
						</template>
						{{ t('pipelinq', 'Export CSV') }}
					</NcButton>
				</template>
				<div class="forecast-progress" :aria-label="progressLabel">
					<div class="forecast-progress__bar">
						<div
							class="forecast-progress__seg forecast-progress__seg--closed"
							:style="{ width: pct(summary.closedWon) + '%' }" />
						<div
							class="forecast-progress__seg forecast-progress__seg--commit"
							:style="{ width: pct(summary.commit) + '%' }" />
					</div>
					<span class="forecast-progress__label">{{ progressLabel }}</span>
				</div>
				<NcNoteCard v-if="atRisk" type="warning">
					{{ atRiskMessage }}
				</NcNoteCard>
				<template v-if="rows.length">
					<CnDataTable
						class="forecast-table"
						:columns="quarterColumns"
						:rows="quarterRows"
						rowKey="id"
						rowTitle="text"
						filterable
						localHeaders>
						<template #column-name="{ row }">
							<span data-testid="forecast-owner">{{ row.name }}</span>
						</template>
						<template #column-commit="{ row }">
							{{ formatMoney(row.commit) }}
							<span
								v-if="hasOverride(row.source)"
								class="forecast-table__was"
								data-testid="forecast-was">
								{{ t('pipelinq', 'was {amount}', { amount: formatMoney(row.source.original_commit) }) }}
							</span>
						</template>
						<template #column-best_case="{ row }">
							{{ formatMoney(row.best_case) }}
						</template>
						<template #column-pipeline="{ row }">
							{{ formatMoney(row.pipeline) }}
						</template>
						<template #column-closed_won="{ row }">
							{{ formatMoney(row.closed_won) }}
						</template>
						<template #column-quota="{ row }">
							{{ row.quota === null ? '—' : formatMoney(row.quota) }}
						</template>
						<template #column-override="{ row }">
							<span
								v-if="hasOverride(row.source)"
								class="forecast-table__badge"
								data-testid="forecast-overridden"
								:title="overrideTitle(row.source)">
								{{ t('pipelinq', 'Overridden') }}
							</span>
							<NcButton
								v-else
								variant="secondary"
								@click="openOverride(row.source)">
								<template #icon>
									<Pencil :size="20" />
								</template>
								{{ t('pipelinq', 'Override') }}
							</NcButton>
						</template>
					</CnDataTable>
					<p
						v-for="row in overriddenRows"
						:key="'note-' + row.owner_id"
						class="forecast-table__note"
						data-testid="forecast-override-note">
						{{ t('pipelinq', '{name}: the seller gave {original}, {reason}', { name: ownerName(row), original: formatMoney(row.original_commit), reason: row.commit_override_reason }) }}
					</p>
				</template>
				<NcEmptyContent
					v-else
					:name="t('pipelinq', 'No snapshots yet')"
					:description="
						t(
							'pipelinq',
							'Forecast snapshots are generated every Monday. Check back after the next run.',
						)
					" />
			</CnDetailCard>
			<h2 class="forecast-dashboard__section">
				{{ t('pipelinq', 'Trend and accuracy of the forecast') }}
			</h2>
			<ForecastTrend embedded :level="childLevel" />
		</template>
		<ForecastOverrideModal
			v-if="overrideTarget"
			:periodId="periodId"
			:ownerId="overrideTarget.owner_id"
			:level="childLevel"
			category="commit"
			:currentAmount="overrideTarget.commit"
			@close="overrideTarget = null"
			@saved="onOverrideSaved" />
	</div>
</template>

<script>
import {
	CnBuildiqEditButton,
	CnDataTable,
	CnDetailCard,
	CnPageHeader,
	CnSegmentedControl,
} from '@conduction/nextcloud-vue'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Download from 'vue-material-design-icons/Download.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ForecastOverrideModal from '../../modals/ForecastOverrideModal.vue'
import ForecastTrend from './ForecastTrend.vue'
import { formatEur } from '../../services/commercialFormat.js'
import { csvExportUrl, fetchSnapshots } from '../../services/forecastApi.js'
import {
	attainmentPercent,
	gapToQuota,
	isAtRisk,
	latestPerOwner,
	projectedAttainment,
} from '../../services/forecastMath.js'
import { fetchUserDisplayName } from '../../services/userDisplayName.js'

export default {
	name: 'ForecastDashboard',
	components: {
		CnBuildiqEditButton,
		CnDataTable,
		CnDetailCard,
		CnPageHeader,
		CnSegmentedControl,
		Download,
		ForecastTrend,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		Pencil,
		ForecastOverrideModal,
	},

	data() {
		return {
			loading: false,
			level: 'team',
			rows: [],
			names: {},
			overrideTarget: null,
			daysRemaining: 30,
		}
	},

	computed: {
		/**
		 * The quarter table's columns: every figure sorts and filters on a
		 * range, the owner on contains (PqForecast).
		 *
		 * @spec openspec/changes/forecast-table-heads/specs/forecast-table-heads/spec.md#requirement-the-forecast-tables-sort-and-filter-their-rows
		 * @return {Array<object>} The columns.
		 */
		quarterColumns() {
			const figure = (key, label) => ({ key, label, type: 'number', sortable: true, class: 'forecast-table__num' })
			return [
				{ key: 'name', label: t('pipelinq', 'Owner'), sortable: true },
				figure('commit', t('pipelinq', 'Commit')),
				figure('best_case', t('pipelinq', 'Best Case')),
				figure('pipeline', t('pipelinq', 'Pipeline')),
				figure('closed_won', t('pipelinq', 'Closed Won')),
				figure('quota', t('pipelinq', 'Quota')),
				{ key: 'override', label: t('pipelinq', 'Override'), sortable: false, filterable: false },
			]
		},

		/**
		 * The quarter table's rows; the table sorts and filters them from its heads.
		 *
		 * @spec openspec/changes/forecast-table-heads/specs/forecast-table-heads/spec.md#requirement-the-forecast-tables-sort-and-filter-their-rows
		 * @return {Array<object>} One row per owner.
		 */
		quarterRows() {
			return this.rows.map((row) => ({
				id: row.owner_id,
				name: this.ownerName(row),
				commit: row.commit,
				best_case: row.best_case,
				pipeline: row.pipeline,
				closed_won: row.closed_won,
				quota: row.quota,
				source: row,
			}))
		},

		levelOptions() {
			return [
				{ value: 'rep', label: t('pipelinq', 'Seller') },
				{ value: 'team', label: t('pipelinq', 'Team') },
				{ value: 'division', label: t('pipelinq', 'Division') },
				{ value: 'company', label: t('pipelinq', 'Company') },
			]
		},

		/**
		 * The quarter the forecast covers, as the card title.
		 *
		 * @return {string}
		 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
		 */
		quarterTitle() {
			const now = new Date()
			return t('pipelinq', 'Quarter {quarter} of {year}', {
				quarter: Math.floor(now.getMonth() / 3) + 1,
				year: now.getFullYear(),
			})
		},

		/**
		 * The line under the title: the quarter and the level shown.
		 *
		 * @return {string}
		 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
		 */
		subtitle() {
			const option = this.levelOptions.find((o) => o.value === this.level)
			const level = t('pipelinq', 'level {level}', { level: option ? option.label.toLowerCase() : this.level })
			const snapshot = this.snapshotDate
				? t('pipelinq', 'snapshot of {date}', { date: this.snapshotDate })
				: ''
			return [this.quarterTitle, snapshot, level].filter(Boolean).join(' · ')
		},

		/**
		 * The day the shown snapshots were taken, written out ("maandag 5 oktober").
		 *
		 * @return {string} The date, or '' without snapshots.
		 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
		 */
		snapshotDate() {
			const dates = this.rows.map((row) => String(row.as_of_date || '')).filter(Boolean).sort()
			if (dates.length === 0) {
				return ''
			}
			const date = new Date(dates[dates.length - 1] + 'T12:00:00')
			if (Number.isNaN(date.getTime())) {
				return ''
			}
			return new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' }).format(date)
		},

		/**
		 * The rows whose commit a lead overrode, for the notes under the table.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
		 */
		overriddenRows() {
			return this.rows.filter((row) => this.hasOverride(row))
		},

		/**
		 * The four KPI tiles: quota, won, committed and what is still to get.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
		 */
		tiles() {
			const gapPercent =
				this.summary.quota > 0
					? 100
						- attainmentPercent(
							this.summary.projected,
							this.summary.quota,
						)
					: 0
			return [
				{
					id: 'quota',
					label: t('pipelinq', 'Quota'),
					value: this.formatMoney(this.summary.quota),
				},
				{
					id: 'won',
					label: t('pipelinq', 'Won'),
					value: this.formatMoney(this.summary.closedWon),
				},
				{
					id: 'commit',
					label: t('pipelinq', 'Committed'),
					value: this.formatMoney(this.summary.commit),
				},
				{
					id: 'gap',
					label: t('pipelinq', 'Still to get'),
					value: this.formatMoney(this.summary.gap),
					warn: this.summary.gap > 0,
					caption:
						gapPercent > 0
							? t('pipelinq', '{percent}% below quota', {
									percent: gapPercent,
								})
							: '',
				},
			]
		},

		childLevel() {
			return (
				{ company: 'division', division: 'team', team: 'rep', rep: 'rep' }[
					this.level
				] || 'rep'
			)
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
			return {
				commit,
				bestCase,
				closedWon,
				quota,
				projected,
				gap: gapToQuota(quota, projected),
			}
		},

		atRisk() {
			return isAtRisk(
				this.summary.projected,
				this.summary.quota,
				this.daysRemaining,
			)
		},

		atRiskMessage() {
			const pct =
				100 - attainmentPercent(this.summary.projected, this.summary.quota)
			return t(
				'pipelinq',
				'This team is {gap}% below quota with {days} days to close. Action recommended.',
				{ gap: pct, days: this.daysRemaining },
			)
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

		/**
		 * The owner's display name once it is known, else the user id.
		 *
		 * @param {object} row A snapshot row.
		 * @return {string} The name.
		 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
		 */
		ownerName(row) {
			return this.names[row.owner_id] || row.owner_id
		},

		/**
		 * Look up the display names of the rows' owners.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/review-finish/specs/commercial-dashboard/spec.md
		 */
		async loadNames() {
			const ids = [...new Set(this.rows.map((row) => row.owner_id).filter(Boolean))]
			const names = await Promise.all(ids.map((id) => fetchUserDisplayName(id).catch(() => id)))
			const found = {}
			ids.forEach((id, index) => { found[id] = names[index] || id })
			this.names = found
		},

		overrideTitle(row) {
			return t('pipelinq', 'Rep submitted: {original} — {reason}', {
				original: this.formatMoney(row.original_commit),
				reason: row.commit_override_reason,
			})
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

		/**
		 * @spec exclude forecast snapshots have no owning requirement, see
		 *   ForecastSettings::load for the same gap
		 */
		async loadSnapshots() {
			this.loading = true
			try {
				const data = await fetchSnapshots({
					periodId: this.periodId,
					level: this.childLevel,
				})
				this.rows = latestPerOwner(data.snapshots || [])
				await this.loadNames()
			} catch {
				this.rows = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show another level and read its snapshots.
		 *
		 * @param {string} level The level chosen.
		 * @spec exclude forecast snapshots have no owning requirement, see
		 *   ForecastSettings::load for the same gap
		 */
		setLevel(level) {
			this.level = level
			this.loadSnapshots()
		},

		openOverride(row) {
			this.overrideTarget = row
		},

		onOverrideSaved() {
			this.overrideTarget = null
			this.loadSnapshots()
		},

		exportCsv() {
			window.location.href = csvExportUrl({
				periodId: this.periodId,
				level: this.childLevel,
			})
		},
	},
}
</script>

<style scoped>
.forecast-dashboard {
	padding: 24px 28px;
}

.forecast-tiles {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px;
	margin: 20px 0;
}

.forecast-tile__label {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.forecast-tile__value {
	display: block;
	font-size: 28px;
	font-weight: 700;
	margin-top: 4px;
}

.forecast-tile__value--gap {
	color: var(--color-text-error, var(--color-error));
}

.forecast-tile__caption {
	display: block;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin-top: 4px;
}

.forecast-dashboard__section {
	font-size: 20px;
	font-weight: 700;
	margin: 32px 0 16px;
}

.forecast-dashboard__levels {
	margin-top: 8px;
}

/* PqForecast: Downloaden at the boards' 40px beside the buildiq square. */
.forecast-dashboard__header-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: 10px;
}

.forecast-dashboard__download {
	min-height: var(--cn-board-control-height, 40px);
}

.forecast-progress {
	margin: 16px 0 24px;
}

.forecast-progress__bar {
	display: flex;
	height: 20px;
	border-radius: 10px;
	overflow: hidden;
	background: var(--color-background-dark);
}

.forecast-progress__seg--closed {
	background: var(--color-success);
}

.forecast-progress__seg--commit {
	background: var(--color-primary-element);
	opacity: 0.7;
	background-image: repeating-linear-gradient(
		45deg,
		transparent,
		transparent 4px,
		rgba(255, 255, 255, 0.25) 4px,
		rgba(255, 255, 255, 0.25) 8px
	);
}

.forecast-progress__label {
	display: block;
	margin-top: 6px;
	color: var(--color-text-maxcontrast);
}

.forecast-table {
	margin-top: 12px;
}

.forecast-table :deep(.forecast-table__num) {
	text-align: end;
	font-variant-numeric: tabular-nums;
}

/* An overridden commit keeps the seller's figure beside it, small and muted,
   and the row says so with a pill where the Override button stood (PqForecast). */
.forecast-table__was {
	margin-inline-start: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.forecast-table__badge {
	display: inline-flex;
	align-items: center;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 999px);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text, var(--color-primary-element));
	font-size: 12px;
	font-weight: 600;
	cursor: help;
}

.forecast-table__note {
	margin: 12px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}
</style>
