<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="loyalty-reporting">
		<header class="loyalty-reporting__header">
			<div>
				<h2>{{ t('pipelinq', 'Loyalty programme reporting') }}</h2>
				<p class="loyalty-reporting__subtitle">
					{{ t('pipelinq', 'Points, tiers and what the outstanding points are worth') }}
				</p>
			</div>
			<NcButton variant="secondary" :disabled="!kpis" @click="exportCsv">
				<template #icon>
					<Download :size="18" />
				</template>
				{{ t('pipelinq', 'Download') }}
			</NcButton>
		</header>

		<div class="loyalty-reporting__controls">
			<NcSelect
				v-model="selectedProgramme"
				class="loyalty-reporting__programme"
				:inputLabel="t('pipelinq', 'Programme')"
				:options="programmeOptions"
				label="label"
				:clearable="false"
				@update:modelValue="loadKpis" />
			<div class="loyalty-reporting__period">
				<span class="loyalty-reporting__period-label">{{ t('pipelinq', 'Period') }}</span>
				<div class="loyalty-reporting__segments" role="group" :aria-label="t('pipelinq', 'Period')">
					<button
						v-for="option in periodOptions"
						:key="option.id"
						type="button"
						class="loyalty-reporting__segment"
						:class="{ 'loyalty-reporting__segment--active': selectedPeriod && selectedPeriod.id === option.id }"
						:aria-pressed="selectedPeriod && selectedPeriod.id === option.id ? 'true' : 'false'"
						@click="pickPeriod(option)">
						{{ t('pipelinq', option.label) }}
					</button>
				</div>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else-if="kpis">
			<div class="loyalty-kpis">
				<div v-for="tile in kpiTiles" :key="tile.key" class="loyalty-kpi">
					<span class="loyalty-kpi__label">{{ tile.label }}</span>
					<span class="loyalty-kpi__value">{{ tile.value }}</span>
				</div>
			</div>

			<div class="loyalty-reporting__columns">
				<section class="loyalty-reporting__card">
					<header class="loyalty-reporting__card-head">
						<h3>{{ t('pipelinq', 'Tier distribution') }}</h3>
						<NcButton variant="secondary" @click="exportCsv">
							<template #icon>
								<Download :size="16" />
							</template>
							{{ t('pipelinq', 'Export CSV') }}
						</NcButton>
					</header>
					<template v-if="tierRows.length">
						<ul class="loyalty-reporting__bars">
							<li v-for="row in tierRows" :key="row.tierId">
								<span>{{ row.tierId }}</span>
								<span class="loyalty-reporting__bar">
									<span :style="{ width: tierShare(row) + '%' }" />
								</span>
								<span class="loyalty-reporting__bar-count">{{ row.count }}</span>
							</li>
						</ul>
						<table class="loyalty-reporting__table">
							<thead>
								<tr>
									<th scope="col">{{ t('pipelinq', 'Tier') }}</th>
									<th scope="col" class="loyalty-reporting__num">{{ t('pipelinq', 'Accounts') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in tierRows" :key="row.tierId">
									<td>{{ row.tierId }}</td>
									<td class="loyalty-reporting__num">{{ formatNumber(row.count) }}</td>
								</tr>
							</tbody>
						</table>
					</template>
					<p v-else>
						{{ t('pipelinq', 'No tier data yet.') }}
					</p>
				</section>
				<section class="loyalty-reporting__card">
					<LoyaltyAccountCreation :embedded="true" />
				</section>
			</div>
		</template>

		<NcEmptyContent
			v-else
			:title="t('pipelinq', 'Select a programme to view KPIs')"
			:description="
				t(
					'pipelinq',
					'Pick an active programme above to load its reporting dashboard.',
				)
			" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import Download from 'vue-material-design-icons/Download.vue'
import LoyaltyAccountCreation from './LoyaltyAccountCreation.vue'

export default {
	name: 'LoyaltyReporting',
	components: { Download, LoyaltyAccountCreation, NcButton, NcEmptyContent, NcLoadingIcon, NcSelect },
	data() {
		return {
			loading: false,
			programmes: [],
			selectedProgramme: null,
			selectedPeriod: null,
			periodOptions: [
				{ id: '30d', label: 'Last 30 days', days: 30 },
				{ id: '90d', label: 'Last 90 days', days: 90 },
				{ id: '365d', label: 'Last 12 months', days: 365 },
			],

			kpis: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008
		 */
		programmeOptions() {
			return this.programmes.map((p) => ({
				id: p.id,
				label: p.name || p.brand || p.id,
			}))
		},

		/**
		 * The eight headline tiles in board order.
		 *
		 * @return {Array<object>} key, label and formatted value per tile.
		 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008
		 */
		kpiTiles() {
			const k = this.kpis || {}
			return [
				{ key: 'active', label: this.t('pipelinq', 'Active accounts'), value: this.formatNumber(k.activeAccounts) },
				{ key: 'issued', label: this.t('pipelinq', 'Points issued'), value: this.formatNumber(k.pointsIssued) },
				{ key: 'redeemed', label: this.t('pipelinq', 'Points redeemed'), value: this.formatNumber(k.pointsRedeemed) },
				{ key: 'expired', label: this.t('pipelinq', 'Points expired'), value: this.formatNumber(k.pointsExpired) },
				{ key: 'breakage', label: this.t('pipelinq', 'Breakage %'), value: `${k.breakagePercent ?? 0}%` },
				{ key: 'redemption', label: this.t('pipelinq', 'Redemption rate %'), value: `${k.redemptionRate ?? 0}%` },
				{ key: 'outstanding', label: this.t('pipelinq', 'Outstanding points'), value: this.formatNumber(k.outstandingPoints) },
				{ key: 'liability', label: this.t('pipelinq', 'Estimated liability'), value: this.formatMoney(k.estimatedLiability) },
			]
		},

		tierRows() {
			if (!this.kpis || !this.kpis.tierDistribution) {
				return []
			}
			return Object.keys(this.kpis.tierDistribution).map((tierId) => ({
				tierId,
				count: this.kpis.tierDistribution[tierId],
			}))
		},
	},

	mounted() {
		this.selectedPeriod = this.periodOptions[1]
		this.loadProgrammes()
	},

	methods: {
		/**
		 * Switch the period and read the tiles again.
		 *
		 * @param {object} option The picked period.
		 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008
		 */
		pickPeriod(option) {
			this.selectedPeriod = option
			this.loadKpis()
		},

		/**
		 * A tier's share of the largest tier, as the width of its bar.
		 *
		 * @param {object} row A tier row.
		 * @return {number} A percentage.
		 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008
		 */
		tierShare(row) {
			const max = Math.max(...this.tierRows.map((r) => Number(r.count) || 0), 0)
			return max ? Math.round(((Number(row.count) || 0) / max) * 100) : 0
		},

		formatNumber(n) {
			return new Intl.NumberFormat('nl-NL').format(Number(n || 0))
		},

		formatMoney(amount) {
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
			}).format(Number(amount || 0))
		},

		/**
		 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-008
		 */
		async loadProgrammes() {
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/pipelinq/loyaltyProgramme?_limit=200',
				)
				const response = await axios.get(url)
				const list =
					(response.data && (response.data.results || response.data)) || []
				this.programmes = list.map((p) => ({
					id: p['@self']?.id || p.id || p.programmeId,
					name: p.name,
					brand: p.brand,
				}))
				if (this.programmes.length && !this.selectedProgramme) {
					this.selectedProgramme = this.programmeOptions[0]
					this.loadKpis()
				}
			} catch {
				showError(this.t('pipelinq', 'Failed to load programmes'))
			}
		},

		/**
		 * @spec exclude the loyalty-program change is archived and no spec inherited
		 *   loyalty; there is no loyalty spec at all
		 */
		async loadKpis() {
			if (!this.selectedProgramme) {
				return
			}
			this.loading = true
			try {
				const period = this.selectedPeriod || this.periodOptions[1]
				const to = new Date().toISOString()
				const from = new Date(
					Date.now() - period.days * 86400000,
				).toISOString()
				const url = generateUrl(
					`/apps/pipelinq/api/loyalty/reporting/${this.selectedProgramme.id}/kpis?from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`,
				)
				const response = await axios.get(url)
				this.kpis = response.data
			} catch {
				showError(this.t('pipelinq', 'Failed to load KPIs'))
			} finally {
				this.loading = false
			}
		},

		exportCsv() {
			if (!this.kpis) {
				return
			}
			const lines = [
				'metric,value',
				`activeAccounts,${this.kpis.activeAccounts}`,
				`pointsIssued,${this.kpis.pointsIssued}`,
				`pointsRedeemed,${this.kpis.pointsRedeemed}`,
				`pointsExpired,${this.kpis.pointsExpired}`,
				`breakagePercent,${this.kpis.breakagePercent}`,
				`redemptionRate,${this.kpis.redemptionRate}`,
				`outstandingPoints,${this.kpis.outstandingPoints}`,
				`estimatedLiability,${this.kpis.estimatedLiability}`,
				`pointValue,${this.kpis.pointValue}`,
			]
			const blob = new Blob([lines.join('\n')], { type: 'text/csv' })
			const url = URL.createObjectURL(blob)
			const a = document.createElement('a')
			a.href = url
			a.download = `loyalty-kpis-${this.selectedProgramme.id}.csv`
			a.click()
			URL.revokeObjectURL(url)
		},
	},
}
</script>

<style scoped lang="scss">
.loyalty-reporting {
	padding: 24px 28px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.loyalty-reporting__header {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	gap: 1rem;

	h2 {
		margin: 0 0 6px;
		font-size: 28px;
	}
}

.loyalty-reporting__subtitle {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.loyalty-reporting__controls {
	display: flex;
	align-items: flex-end;
	gap: 16px;
	flex-wrap: wrap;
}

.loyalty-reporting__programme {
	flex: 1 1 400px;
}

.loyalty-reporting__period {
	display: flex;
	flex-direction: column;
	gap: 4px;
	flex: 1 1 400px;
}

.loyalty-reporting__period-label {
	font-weight: 600;
}

.loyalty-reporting__segments {
	display: flex;
	gap: 4px;
	padding: 4px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius-large);
}

.loyalty-reporting__segment {
	border: none;
	background: transparent;
	padding: 6px 12px;
	border-radius: var(--border-radius);
	font-weight: 600;
	cursor: pointer;
}

.loyalty-reporting__segment--active {
	background: var(--color-main-background);
}

.loyalty-kpis {
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 16px;
}

.loyalty-kpi,
.loyalty-reporting__card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 18px 20px;
}

.loyalty-kpi {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.loyalty-kpi__label {
	color: var(--color-text-maxcontrast);
}

.loyalty-kpi__value {
	font-size: 26px;
	font-weight: bold;
}

.loyalty-reporting__columns {
	display: grid;
	grid-template-columns: 2fr 1fr;
	gap: 20px;
	align-items: start;
}

.loyalty-reporting__card-head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;

	h3 {
		margin: 0;
	}
}

.loyalty-reporting__bars {
	list-style: none;
	margin: 0 0 12px;
	padding: 0;

	li {
		display: grid;
		grid-template-columns: 160px 1fr 60px;
		align-items: center;
		gap: 8px;
		padding: 3px 0;
	}
}

.loyalty-reporting__bar {
	height: 8px;
	background: var(--color-background-dark);
	border-radius: 999px;
	overflow: hidden;

	span {
		display: block;
		height: 100%;
		background: var(--color-primary-element);
	}
}

.loyalty-reporting__bar-count,
.loyalty-reporting__num {
	text-align: end;
}

.loyalty-reporting__table {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	border-collapse: separate;
	border-spacing: 0;
}

.loyalty-reporting__table th,
.loyalty-reporting__table td {
	text-align: start;
	padding: 10px 18px;
	border-bottom: 1px solid var(--color-border);
}

.loyalty-reporting__table tbody tr:last-child td {
	border-bottom: none;
}

@media (max-width: 1024px) {
	.loyalty-kpis {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.loyalty-reporting__columns {
		grid-template-columns: 1fr;
	}
}
</style>
