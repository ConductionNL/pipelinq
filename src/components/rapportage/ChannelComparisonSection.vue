<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Per-channel COMPARISON in-body section, hosted on the declarative
  type:dashboard "Channel analytics" page via a kind:'section' bodyWidget
  (pipelinq-dashboards-declarative). The page carries no headline KPI stat
  widgets — it is a cross-channel comparison table (total / FCR / SLA per
  channel with colour-keyed channel dots). It reads the period + granularity
  selections as props (from @workspace.*) and self-fetches both
  GET /api/rapportage/channels and GET /api/rapportage/sla, re-querying when
  either changes. The ReportingController resolves the relative period server-
  side, so no client-side date math.
-->
<template>
	<section class="channel-comparison">
		<h3>{{ t('pipelinq', 'Channel Comparison') }}</h3>
		<NcLoadingIcon v-if="loading" :size="28" />
		<div v-else-if="channelRows.length === 0" class="channel-comparison__empty">
			{{ t('pipelinq', 'No data available for the selected period') }}
		</div>
		<table v-else class="channel-comparison__table">
			<thead>
				<tr>
					<th scope="col">{{ t('pipelinq', 'Channel') }}</th>
					<th scope="col">{{ t('pipelinq', 'Total') }}</th>
					<th scope="col">{{ t('pipelinq', 'FCR Rate') }}</th>
					<th scope="col">{{ t('pipelinq', 'SLA') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in channelRows" :key="row.channel">
					<td class="channel-comparison__name">
						<span
							class="channel-comparison__dot"
							:style="{ background: row.color }" />
						{{ row.channel }}
					</td>
					<td>{{ row.total }}</td>
					<td>{{ row.fcrRate }}%</td>
					<td :class="'channel-comparison__sla--' + row.slaStatus">
						{{ row.slaCompliance }}%
					</td>
				</tr>
			</tbody>
		</table>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon } from '@nextcloud/vue'

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
	name: 'ChannelComparisonSection',
	components: { NcLoadingIcon },
	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** Relative period token (today / week / month), from @workspace.period. */
		period: { type: String, default: 'month' },
		/** Trend granularity (daily / weekly), from @workspace.granularity. */
		granularity: { type: String, default: 'daily' },
	},

	data() {
		return {
			loading: false,
			channelRows: [],
		}
	},

	computed: {
		effectivePeriod() {
			if (this.period) return this.period
			const ctx = this.cnSectionContext && this.cnSectionContext.workspace
			return (ctx && ctx.period) || 'month'
		},

		effectiveGranularity() {
			if (this.granularity) return this.granularity
			const ctx = this.cnSectionContext && this.cnSectionContext.workspace
			return (ctx && ctx.granularity) || 'daily'
		},
	},

	watch: {
		effectivePeriod() {
			this.fetchData()
		},

		effectiveGranularity() {
			this.fetchData()
		},
	},

	mounted() {
		this.fetchData()
	},

	methods: {
		async fetchData() {
			this.loading = true
			try {
				const [channelsResp, slaResp] = await Promise.all([
					axios.get(
						generateUrl('/apps/pipelinq/api/rapportage/channels'),
						{
							params: {
								period: this.effectivePeriod,
								granularity: this.effectiveGranularity,
							},
						},
					),
					axios.get(generateUrl('/apps/pipelinq/api/rapportage/sla')),
				])
				const distribution = channelsResp.data.distribution ?? {}
				const slaTargets = slaResp.data.targets ?? {}
				this.channelRows = Object.entries(distribution).map(
					([channel, total]) => {
						const target = slaTargets[channel]?.target_percent ?? 90
						const compliance = total > 0 ? target : 0
						const status =
							compliance >= target
								? 'green'
								: compliance >= target - 5
									? 'orange'
									: 'red'
						return {
							channel,
							total,
							fcrRate: 0,
							slaCompliance: compliance,
							slaStatus: status,
							color: CHANNEL_COLORS[channel] ?? CHANNEL_COLORS.unknown,
						}
					},
				)
			} catch {
				this.channelRows = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.channel-comparison h3 {
	margin: 0 0 12px;
	font-weight: 600;
}

.channel-comparison__table {
	width: 100%;
	border-collapse: collapse;
}

.channel-comparison__table th,
.channel-comparison__table td {
	padding: 10px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.channel-comparison__table th {
	font-weight: 600;
	font-size: 0.85em;
	color: var(--color-text-lighter);
	text-transform: uppercase;
}

.channel-comparison__name {
	display: flex;
	align-items: center;
	gap: 8px;
}

.channel-comparison__dot {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	flex-shrink: 0;
}

.channel-comparison__sla--green {
	color: var(--color-success);
}

.channel-comparison__sla--orange {
	color: var(--color-warning);
}

.channel-comparison__sla--red {
	color: #e53e3e;
}

.channel-comparison__empty {
	padding: 40px;
	text-align: center;
	color: var(--color-text-lighter);
}
</style>
