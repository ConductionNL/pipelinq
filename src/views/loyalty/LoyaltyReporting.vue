<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="loyalty-reporting">
		<div class="loyalty-reporting__header">
			<h2>{{ t('pipelinq', 'Loyalty programme reporting') }}</h2>
			<div class="loyalty-reporting__controls">
				<NcSelect
					v-model="selectedProgramme"
					:inputLabel="t('pipelinq', 'Programme')"
					:options="programmeOptions"
					label="label"
					:clearable="false"
					@update:modelValue="loadKpis" />
				<NcSelect
					v-model="selectedPeriod"
					:inputLabel="t('pipelinq', 'Period')"
					:options="periodOptions"
					label="label"
					:clearable="false"
					@update:modelValue="loadKpis" />
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else-if="kpis">
			<div class="loyalty-kpis">
				<div class="loyalty-kpi">
					<span class="loyalty-kpi__label">{{
						t('pipelinq', 'Active accounts')
					}}</span>
					<span class="loyalty-kpi__value">{{
						formatNumber(kpis.activeAccounts)
					}}</span>
				</div>
				<div class="loyalty-kpi">
					<span class="loyalty-kpi__label">{{
						t('pipelinq', 'Points issued')
					}}</span>
					<span class="loyalty-kpi__value">{{
						formatNumber(kpis.pointsIssued)
					}}</span>
				</div>
				<div class="loyalty-kpi">
					<span class="loyalty-kpi__label">{{
						t('pipelinq', 'Points redeemed')
					}}</span>
					<span class="loyalty-kpi__value">{{
						formatNumber(kpis.pointsRedeemed)
					}}</span>
				</div>
				<div class="loyalty-kpi">
					<span class="loyalty-kpi__label">{{
						t('pipelinq', 'Points expired')
					}}</span>
					<span class="loyalty-kpi__value">{{
						formatNumber(kpis.pointsExpired)
					}}</span>
				</div>
				<div class="loyalty-kpi">
					<span class="loyalty-kpi__label">{{
						t('pipelinq', 'Breakage %')
					}}</span>
					<span class="loyalty-kpi__value"
						>{{ kpis.breakagePercent }}%</span
					>
				</div>
				<div class="loyalty-kpi">
					<span class="loyalty-kpi__label">{{
						t('pipelinq', 'Redemption rate %')
					}}</span>
					<span class="loyalty-kpi__value"
						>{{ kpis.redemptionRate }}%</span
					>
				</div>
				<div class="loyalty-kpi">
					<span class="loyalty-kpi__label">{{
						t('pipelinq', 'Outstanding points')
					}}</span>
					<span class="loyalty-kpi__value">{{
						formatNumber(kpis.outstandingPoints)
					}}</span>
				</div>
				<div class="loyalty-kpi">
					<span class="loyalty-kpi__label">{{
						t('pipelinq', 'Estimated liability')
					}}</span>
					<span class="loyalty-kpi__value">{{
						formatMoney(kpis.estimatedLiability)
					}}</span>
				</div>
			</div>

			<h3>{{ t('pipelinq', 'Tier distribution') }}</h3>
			<table v-if="tierRows.length" class="loyalty-reporting__table">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Tier') }}</th>
						<th scope="col">{{ t('pipelinq', 'Accounts') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in tierRows" :key="row.tierId">
						<td>{{ row.tierId }}</td>
						<td>{{ formatNumber(row.count) }}</td>
					</tr>
				</tbody>
			</table>
			<p v-else>
				{{ t('pipelinq', 'No tier data yet.') }}
			</p>

			<NcButton variant="secondary" @click="exportCsv">
				{{ t('pipelinq', 'Export CSV') }}
			</NcButton>
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

export default {
	name: 'LoyaltyReporting',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect },
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
		programmeOptions() {
			return this.programmes.map((p) => ({
				id: p.id,
				label: p.name || p.brand || p.id,
			}))
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
		formatNumber(n) {
			return new Intl.NumberFormat('nl-NL').format(Number(n || 0))
		},

		formatMoney(amount) {
			return new Intl.NumberFormat('nl-NL', {
				style: 'currency',
				currency: 'EUR',
			}).format(Number(amount || 0))
		},

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
			} catch (error) {
				showError(this.t('pipelinq', 'Failed to load programmes'))
			}
		},

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
			} catch (error) {
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

<style scoped>
.loyalty-reporting {
	padding: 1rem;
}

.loyalty-reporting__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 1rem;
	gap: 1rem;
	flex-wrap: wrap;
}

.loyalty-reporting__controls {
	display: flex;
	gap: 0.5rem;
}

.loyalty-kpis {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 1rem;
	margin-bottom: 1.5rem;
}

.loyalty-kpi {
	display: flex;
	flex-direction: column;
	padding: 0.75rem 1rem;
	background: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.loyalty-kpi__label {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.loyalty-kpi__value {
	font-size: 1.4rem;
	font-weight: bold;
}

.loyalty-reporting__table {
	width: 100%;
	margin-bottom: 1rem;
}

.loyalty-reporting__table th,
.loyalty-reporting__table td {
	text-align: left;
	padding: 0.5rem;
}
</style>
