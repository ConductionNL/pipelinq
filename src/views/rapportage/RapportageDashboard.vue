<template>
	<div class="rapportage-dashboard">
		<div class="rapportage-dashboard__header">
			<h2>{{ t('pipelinq', 'Reporting Dashboard') }}</h2>
			<div class="rapportage-dashboard__actions">
				<NcButton type="secondary" @click="exportCsv">
					{{ t('pipelinq', 'Export CSV') }}
				</NcButton>
				<span class="last-updated">
					{{ t('pipelinq', 'Last updated') }}: {{ lastUpdated }}
				</span>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" />

		<template v-else>
			<!-- KPI Cards -->
			<div class="kpi-grid">
				<div class="kpi-card">
					<div class="kpi-card__value">
						{{ kpis.totalContacts }}
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Contacts today') }}
					</div>
					<div v-if="kpis.totalContactsTrend" class="kpi-card__trend" :class="trendClass(kpis.totalContactsTrend)">
						{{ kpis.totalContactsTrend > 0 ? '+' : '' }}{{ kpis.totalContactsTrend }}%
					</div>
				</div>

				<div class="kpi-card" :class="{ 'kpi-card--warning': kpis.fcrRate < kpis.fcrTarget }">
					<div class="kpi-card__value">
						{{ kpis.fcrRate }}%
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'First-call resolution') }}
					</div>
					<div class="kpi-card__target">
						{{ t('pipelinq', 'Target') }}: {{ kpis.fcrTarget }}%
					</div>
				</div>

				<div class="kpi-card">
					<div class="kpi-card__value">
						{{ kpis.avgHandlingTime }}
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Avg handling time') }}
					</div>
				</div>

				<div class="kpi-card" :class="slaStatusClass">
					<div class="kpi-card__value">
						{{ kpis.slaCompliance }}%
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'SLA compliance') }}
					</div>
					<div class="kpi-card__target">
						{{ t('pipelinq', 'Target') }}: {{ kpis.slaTarget }}%
					</div>
				</div>

				<div class="kpi-card">
					<div class="kpi-card__value">
						{{ kpis.activeAgents }}
					</div>
					<div class="kpi-card__label">
						{{ t('pipelinq', 'Active agents') }}
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
				<NcButton type="secondary" @click="$router.push({ name: 'ChannelAnalytics' })">
					{{ t('pipelinq', 'Channel Analytics') }}
				</NcButton>
				<NcButton type="secondary" @click="$router.push({ name: 'AgentPerformance' })">
					{{ t('pipelinq', 'Agent Performance') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'RapportageDashboard',
	components: { NcButton, NcLoadingIcon },
	data() {
		return {
			loading: false,
			lastUpdated: new Date().toLocaleTimeString('nl-NL'),
			refreshInterval: null,
			kpis: {
				totalContacts: 0,
				totalContactsTrend: 0,
				fcrRate: 0,
				fcrTarget: 80,
				avgHandlingTime: '0:00',
				slaCompliance: 0,
				slaTarget: 90,
				activeAgents: 0,
			},
			channelData: [],
		}
	},
	computed: {
		slaStatusClass() {
			if (this.kpis.slaCompliance >= this.kpis.slaTarget) return 'kpi-card--success'
			if (this.kpis.slaCompliance >= this.kpis.slaTarget - 5) return 'kpi-card--warning'
			return 'kpi-card--danger'
		},
	},
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
		async fetchData() {
			this.loading = this.kpis.totalContacts === 0
			try {
				// Fetch from reporting API
				this.lastUpdated = new Date().toLocaleTimeString('nl-NL')
			} finally {
				this.loading = false
			}
		},
		trendClass(trend) {
			return trend > 0 ? 'trend--up' : 'trend--down'
		},
		exportCsv() {
			window.location.href = generateUrl('/apps/pipelinq/api/rapportage/export')
		},
	},
}
</script>

<style scoped>
.rapportage-dashboard { padding: 20px; max-width: 1200px; margin: 0 auto; }

.rapportage-dashboard__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }

.rapportage-dashboard__actions { display: flex; gap: 12px; align-items: center; }

.last-updated { font-size: 0.85em; color: var(--color-text-lighter); }

.kpi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }

.kpi-card { border: 1px solid var(--color-border); border-radius: var(--border-radius-large); padding: 20px; text-align: center; }

.kpi-card--success { border-color: var(--color-success); }

.kpi-card--warning { border-color: var(--color-warning); }

.kpi-card--danger { border-color: #e53e3e; }

.kpi-card__value { font-size: 2em; font-weight: 700; }

.kpi-card__label { font-size: 0.85em; color: var(--color-text-lighter); margin-top: 4px; }

.kpi-card__target { font-size: 0.75em; color: var(--color-text-lighter); margin-top: 4px; }

.kpi-card__trend { font-size: 0.85em; margin-top: 4px; }

.trend--up { color: var(--color-success); }

.trend--down { color: #e53e3e; }

.channel-section { margin-bottom: 24px; }

.channel-bars { display: flex; flex-direction: column; gap: 8px; }

.channel-bar { display: flex; align-items: center; gap: 12px; }

.channel-bar__label { width: 100px; font-weight: 600; font-size: 0.9em; }

.channel-bar__track { flex: 1; height: 24px; background: var(--color-background-dark); border-radius: 12px; overflow: hidden; }

.channel-bar__fill { height: 100%; border-radius: 12px; transition: width 0.3s; }

.channel-bar__count { width: 120px; text-align: right; font-size: 0.85em; color: var(--color-text-lighter); }

.empty-message { padding: 20px; text-align: center; color: var(--color-text-lighter); }

.rapportage-links { display: flex; gap: 8px; }
</style>
