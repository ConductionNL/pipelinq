<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Contact-channel DISTRIBUTION in-body section, hosted on the declarative
  type:dashboard "Contact reporting" page via a kind:'section' bodyWidget
  (pipelinq-dashboards-declarative). The four headline KPIs (total / FCR % /
  avg handling time / SLA compliance) render as endpoint-bound stat widgets in
  the dashboard grid; this section hosts the hand-rendered per-channel bar
  chart the stat grid cannot express. It reads the period selection as a prop
  (from @workspace.period) and self-fetches GET /api/rapportage/channels,
  re-querying when the period changes. The ReportingController resolves the
  relative period (today / week / month) to a from/to window server-side, so
  the dashboard needs no client-side date math.
-->
<template>
	<section class="channel-distribution">
		<div class="channel-distribution__head">
			<h3>{{ t('pipelinq', 'Channel Distribution') }}</h3>
			<NcButton variant="secondary" @click="exportCsv">
				{{ t('pipelinq', 'Export CSV') }}
			</NcButton>
		</div>
		<NcLoadingIcon v-if="loading" :size="28" />
		<div
			v-else-if="channelData.length === 0"
			class="channel-distribution__empty">
			{{ t('pipelinq', 'No contact moments registered for this period') }}
		</div>
		<div v-else class="channel-distribution__bars">
			<div
				v-for="channel in channelData"
				:key="channel.name"
				class="channel-distribution__bar">
				<div class="channel-distribution__label">
					{{ channel.name }}
				</div>
				<div class="channel-distribution__track">
					<div
						class="channel-distribution__fill"
						:style="{
							width: channel.percentage + '%',
							background: channel.color,
						}" />
				</div>
				<div class="channel-distribution__count">
					{{ channel.count }} ({{ channel.percentage }}%)
				</div>
			</div>
		</div>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

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
	name: 'ChannelDistributionSection',
	components: { NcButton, NcLoadingIcon },
	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** Relative period token (today / week / month), from `@workspace`.period. */
		period: { type: String, default: 'today' },
	},

	data() {
		return {
			loading: false,
			channelData: [],
		}
	},

	computed: {
		effectivePeriod() {
			if (this.period) return this.period
			const ctx = this.cnSectionContext && this.cnSectionContext.workspace
			return (ctx && ctx.period) || 'today'
		},
	},

	watch: {
		effectivePeriod() {
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
				const { data } = await axios.get(
					generateUrl('/apps/pipelinq/api/rapportage/channels'),
					{
						params: { period: this.effectivePeriod },
					},
				)
				const dist = data.distribution ?? {}
				const totalContacts =
					Object.values(dist).reduce((s, c) => s + c, 0) || 1
				this.channelData = Object.entries(dist).map(([name, count]) => ({
					name,
					count,
					percentage: Math.round((count / totalContacts) * 100),
					color: CHANNEL_COLORS[name] ?? CHANNEL_COLORS.unknown,
				}))
			} catch {
				this.channelData = []
			} finally {
				this.loading = false
			}
		},

		exportCsv() {
			window.location.href = generateUrl(
				'/apps/pipelinq/api/rapportage/export',
			)
		},
	},
}
</script>

<style scoped>
.channel-distribution {
	margin-bottom: 8px;
}

.channel-distribution__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.channel-distribution h3 {
	margin: 0;
	font-weight: 600;
}

.channel-distribution__bars {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.channel-distribution__bar {
	display: flex;
	align-items: center;
	gap: 12px;
}

.channel-distribution__label {
	width: 100px;
	font-weight: 600;
	font-size: 0.9em;
}

.channel-distribution__track {
	flex: 1;
	height: 24px;
	background: var(--color-background-dark);
	border-radius: 12px;
	overflow: hidden;
}

.channel-distribution__fill {
	height: 100%;
	border-radius: 12px;
	transition: width 0.3s;
}

.channel-distribution__count {
	width: 120px;
	text-align: right;
	font-size: 0.85em;
	color: var(--color-text-lighter);
}

.channel-distribution__empty {
	padding: 20px;
	text-align: center;
	color: var(--color-text-lighter);
}

@media (prefers-reduced-motion: reduce) {
	.channel-distribution__fill {
		transition: none;
	}
}
</style>
