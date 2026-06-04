<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3 -->
<template>
	<div class="rapportage-dashboard">
		<div class="rapportage-dashboard__header">
			<h2>{{ t('pipelinq', 'Reporting Dashboard') }}</h2>
			<div class="rapportage-dashboard__actions">
				<!-- Date range selector -->
				<div class="date-range-selector">
					<NcButton
						v-for="opt in dateRangeOptions"
						:key="opt.value"
						:type="selectedRange === opt.value ? 'primary' : 'secondary'"
						size="small"
						@click="selectRange(opt.value)">
						{{ opt.label }}
					</NcButton>
				</div>

				<NcButton type="secondary" @click="exportCsv">
					{{ t('pipelinq', 'Export CSV') }}
				</NcButton>

				<span class="last-updated">
					{{ t('pipelinq', 'Last updated') }}: {{ lastUpdated }}
				</span>
			</div>
		</div>

		<div v-if="error" class="error-message">
			{{ error }}
		</div>

		<NcLoadingIcon v-if="loading" />

		<template v-else>
			<!-- KPI Cards -->
			<div class="kpi-grid">
				<div class="kpi-card">
					<div class="kpi-card__value">
						{{ kpis.total }}
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Total Contacts') }}
					</div>
				</div>

				<div class="kpi-card" :class="{ 'kpi-card--warning': kpis.fcrRate < fcrTarget }">
					<div class="kpi-card__value">
						{{ kpis.fcrRate }}%
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'FCR %') }}
					</div>
					<div class="kpi-card__target">
						{{ t('pipelinq', 'Target') }}: {{ fcrTarget }}%
					</div>
				</div>

				<div class="kpi-card">
					<div class="kpi-card__value">
						{{ kpis.avgHandlingTime }}
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Avg Handling Time') }}
					</div>
				</div>

				<div class="kpi-card" :class="slaStatusClass">
					<div class="kpi-card__value">
						{{ kpis.slaCompliance }}%
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'SLA Compliance') }}
					</div>
					<div class="kpi-card__target">
						{{ t('pipelinq', 'Target') }}: {{ slaTarget }}%
					</div>
				</div>
			</div>

			<!-- Channel Distribution -->
			<div class="channel-section">
				<h3>{{ t('pipelinq', 'Channel Distribution') }}</h3>
				<div v-if="channelData.length === 0" class="empty-message">
					{{ t('pipelinq', 'No contact moments registered today') }}
				</div>
				<div v-else class="channel-bars">
					<div
						v-for="channel in channelData"
						:key="channel.name"
						class="channel-bar">
						<div class="channel-bar__label">
							{{ channel.name }}
						</div>
						<div class="channel-bar__track">
							<div
								class="channel-bar__fill"
								:style="{ width: channel.percentage + '%', background: channel.color }" />
						</div>
						<div class="channel-bar__count">
							{{ channel.count }} ({{ channel.percentage }}%)
						</div>
					</div>
				</div>
			</div>

			<!-- Navigation to sub-views -->
			<div class="rapportage-links">
				<NcButton type="secondary" @click="$router.push({ name: 'ChannelAnalyticsView' })">
					{{ t('pipelinq', 'Channel Analytics') }}
				</NcButton>
				<NcButton type="secondary" @click="$router.push({ name: 'AgentPerformanceView' })">
					{{ t('pipelinq', 'Agent Performance') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

const CHANNEL_COLORS = {
	telefoon: '#4c84db',
	email: '#f5a623',
	balie: '#7ed321',
	chat: '#9b59b6',
	social: '#e74c3c',
	brief: '#1abc9c',
	unknown: '#95a5a6',
}

export default {
	name: 'RapportageDashboard',

	components: { NcButton, NcLoadingIcon },

	data() {
		return {
			loading: false,
			error: null,
			lastUpdated: new Date().toLocaleTimeString('nl-NL'),
			refreshInterval: null,
			selectedRange: 'today',
			kpis: {
				total: 0,
				fcrRate: 0,
				avgHandlingTime: '0:00',
				slaCompliance: 0,
			},
			fcrTarget: 80,
			slaTarget: 90,
			channelData: [],
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3
		 */
		dateRangeOptions() {
			return [
				{ value: 'today', label: t('pipelinq', 'Today') },
				{ value: 'week', label: t('pipelinq', 'This week') },
				{ value: 'month', label: t('pipelinq', 'This month') },
			]
		},

		/**
		 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3
		 */
		slaStatusClass() {
			if (this.kpis.slaCompliance >= this.slaTarget) return 'kpi-card--success'
			if (this.kpis.slaCompliance >= this.slaTarget - 5) return 'kpi-card--warning'
			return 'kpi-card--danger'
		},

		/**
		 * Compute from/to dates for the selected range.
		 *
		 * @return {{ from: string, to: string }}
		 */
		dateRange() {
			const now = new Date()
			const pad = (n) => String(n).padStart(2, '0')
			const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`

			if (this.selectedRange === 'today') {
				const today = fmt(now)
				return { from: today, to: today }
			}

			if (this.selectedRange === 'week') {
				const day = now.getDay() || 7
				const monday = new Date(now)
				monday.setDate(now.getDate() - day + 1)
				return { from: fmt(monday), to: fmt(now) }
			}

			// month
			const firstDay = new Date(now.getFullYear(), now.getMonth(), 1)
			return { from: fmt(firstDay), to: fmt(now) }
		},
	},

	/**
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3
	 */
	mounted() {
		this.fetchData()
		this.refreshInterval = setInterval(() => {
			this.fetchData()
		}, 60000)
	},

	beforeDestroy() {
		clearInterval(this.refreshInterval)
	},

	methods: {
		/**
		 * @param {string} range The selected date range key.
		 */
		selectRange(range) {
			this.selectedRange = range
			this.fetchData()
		},

		/**
		 * Fetch KPI and channel data from the reporting API.
		 *
		 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3
		 */
		async fetchData() {
			this.loading = this.kpis.total === 0
			this.error = null
			const { from, to } = this.dateRange

			try {
				const [kpisResp, channelsResp] = await Promise.all([
					fetch(generateUrl(`/apps/pipelinq/api/rapportage/kpis?from=${from}&to=${to}`)),
					fetch(generateUrl(`/apps/pipelinq/api/rapportage/channels?from=${from}&to=${to}`)),
				])

				if (kpisResp.ok) {
					const data = await kpisResp.json()
					this.kpis = {
						total: data.total ?? 0,
						fcrRate: data.fcrRate ?? 0,
						avgHandlingTime: data.avgHandlingTime ?? '0:00',
						slaCompliance: data.slaCompliance ?? 0,
					}
				}

				if (channelsResp.ok) {
					const data = await channelsResp.json()
					const dist = data.distribution ?? {}
					const totalContacts = Object.values(dist).reduce((s, c) => s + c, 0) || 1
					this.channelData = Object.entries(dist).map(([name, count]) => ({
						name,
						count,
						percentage: Math.round((count / totalContacts) * 100),
						color: CHANNEL_COLORS[name] ?? CHANNEL_COLORS.unknown,
					}))
				}

				this.lastUpdated = new Date().toLocaleTimeString('nl-NL')
			} catch {
				this.error = t('pipelinq', 'Failed to load reporting data')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger CSV download.
		 *
		 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3
		 */
		exportCsv() {
			window.location.href = generateUrl('/apps/pipelinq/api/rapportage/export')
		},
	},
}
</script>

<style scoped>
.rapportage-dashboard { padding: 20px; max-width: 1200px; margin: 0 auto; }

.rapportage-dashboard__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 8px; }

.rapportage-dashboard__actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }

.date-range-selector { display: flex; gap: 4px; }

.last-updated { font-size: 0.85em; color: var(--color-text-lighter); }

.kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }

.kpi-card { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 20px; text-align: center; }

.kpi-card--success { border-color: var(--color-success); }

.kpi-card--warning { border-color: var(--color-warning); }

.kpi-card--danger { border-color: #e53e3e; }

.kpi-card__value { font-size: 2em; font-weight: 700; }

.kpi-card__label { font-size: 0.85em; color: var(--color-text-lighter); margin-top: 4px; }

.kpi-card__target { font-size: 0.75em; color: var(--color-text-lighter); margin-top: 4px; }

.channel-section { margin-bottom: 24px; }

.channel-bars { display: flex; flex-direction: column; gap: 8px; }

.channel-bar { display: flex; align-items: center; gap: 12px; }

.channel-bar__label { width: 100px; font-weight: 600; font-size: 0.9em; }

.channel-bar__track { flex: 1; height: 24px; background: var(--color-background-dark); border-radius: 12px; overflow: hidden; }

.channel-bar__fill { height: 100%; border-radius: 12px; transition: width 0.3s; }

.channel-bar__count { width: 120px; text-align: right; font-size: 0.85em; color: var(--color-text-lighter); }

.empty-message { padding: 20px; text-align: center; color: var(--color-text-lighter); }

.rapportage-links { display: flex; gap: 8px; }

.error-message { padding: 12px 16px; background: var(--color-error-bg, #fde8e8); color: var(--color-error, #c0392b); border-radius: var(--border-radius); margin-bottom: 16px; }
</style>
