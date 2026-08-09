<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  Per-agent PERFORMANCE in-body section, hosted on the declarative
  type:dashboard "Agent performance" page via a kind:'section' bodyWidget
  (pipelinq-dashboards-declarative). The page carries no headline KPI stat
  widgets — it is a SORTABLE per-agent leaderboard (contacts / avg time / FCR)
  plus a team-summary footer, both derived from the same agents map. It reads
  the period selection as a prop (from @workspace.period) and self-fetches
  GET /api/rapportage/agents, re-querying when the period changes. The
  ReportingController resolves the relative period server-side, so no
  client-side date math.
-->
<template>
	<section class="agent-performance">
		<NcLoadingIcon v-if="loading" :size="28" />
		<div v-else-if="sortedAgents.length === 0" class="agent-performance__empty">
			{{ t('pipelinq', 'No agent data available') }}
		</div>
		<template v-else>
			<table class="agent-performance__table">
				<thead>
					<tr>
						<th scope="col"
							class="sortable"
							:class="{ 'sort-active': sortKey === 'id' }"
							@click="setSort('id')">
							{{ t('pipelinq', 'Agent') }}
						</th>
						<th scope="col"
							class="sortable"
							:class="{ 'sort-active': sortKey === 'count' }"
							@click="setSort('count')">
							{{ t('pipelinq', 'Contacts') }}
						</th>
						<th scope="col"
							class="sortable"
							:class="{ 'sort-active': sortKey === 'avgHandlingTime' }"
							@click="setSort('avgHandlingTime')">
							{{ t('pipelinq', 'Avg Time') }}
						</th>
						<th scope="col"
							class="sortable"
							:class="{ 'sort-active': sortKey === 'fcrRate' }"
							@click="setSort('fcrRate')">
							{{ t('pipelinq', 'FCR Rate') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="agent in sortedAgents" :key="agent.id">
						<td>{{ agent.id }}</td>
						<td>{{ agent.count }}</td>
						<td>{{ agent.avgHandlingTime }}</td>
						<td>{{ agent.fcrRate }}%</td>
					</tr>
				</tbody>
			</table>

			<div class="agent-performance__summary">
				<h3>{{ t('pipelinq', 'Team Summary') }}</h3>
				<div class="agent-performance__summary-grid">
					<div class="agent-performance__summary-item">
						<span class="agent-performance__summary-value">{{ teamStats.totalContacts }}</span>
						<span class="agent-performance__summary-label">{{ t('pipelinq', 'Total contacts') }}</span>
					</div>
					<div class="agent-performance__summary-item">
						<span class="agent-performance__summary-value">{{ teamStats.avgFcr }}%</span>
						<span class="agent-performance__summary-label">{{ t('pipelinq', 'Team FCR') }}</span>
					</div>
				</div>
			</div>
		</template>
	</section>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'AgentPerformanceSection',
	components: { NcLoadingIcon },
	inject: {
		cnSectionContext: { default: null },
	},
	props: {
		/** Relative period token (today / week / month), from @workspace.period. */
		period: { type: String, default: 'today' },
	},
	data() {
		return {
			loading: false,
			sortKey: 'count',
			sortDir: 'desc',
			agents: [],
		}
	},
	computed: {
		effectivePeriod() {
			if (this.period) return this.period
			const ctx = this.cnSectionContext && this.cnSectionContext.workspace
			return (ctx && ctx.period) || 'today'
		},
		sortedAgents() {
			const list = [...this.agents]
			const dir = this.sortDir === 'desc' ? -1 : 1
			return list.sort((a, b) => {
				const aVal = a[this.sortKey] ?? 0
				const bVal = b[this.sortKey] ?? 0
				if (typeof aVal === 'string') return aVal.localeCompare(bVal) * dir
				return (aVal - bVal) * dir
			})
		},
		teamStats() {
			const total = this.agents.reduce((s, a) => s + (a.count ?? 0), 0)
			const avgFcr = this.agents.length > 0
				? Math.round(this.agents.reduce((s, a) => s + (a.fcrRate ?? 0), 0) / this.agents.length)
				: 0
			return { totalContacts: total, avgFcr }
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
		setSort(key) {
			if (this.sortKey === key) {
				this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'
			} else {
				this.sortKey = key
				this.sortDir = 'desc'
			}
		},
		async fetchData() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/rapportage/agents'), {
					params: { period: this.effectivePeriod },
				})
				const agentMap = data.agents ?? {}
				this.agents = Object.entries(agentMap)
					.filter(([, metrics]) => (metrics.count ?? 0) >= 1)
					.map(([id, metrics]) => ({
						id,
						count: metrics.count ?? 0,
						fcrRate: metrics.fcrRate ?? 0,
						avgHandlingTime: metrics.avgHandlingTime ?? '0:00',
					}))
			} catch {
				this.agents = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.agent-performance__table { width: 100%; border-collapse: collapse; }

.agent-performance__table th, .agent-performance__table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }

.agent-performance__table th { font-weight: 600; font-size: 0.85em; color: var(--color-text-lighter); }

.agent-performance__table th.sortable { cursor: pointer; user-select: none; }

.agent-performance__table th.sortable:hover { color: var(--color-text-maxcontrast); }

.agent-performance__table th.sort-active { color: var(--color-primary); }

.agent-performance__summary { margin-top: 24px; padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }

.agent-performance__summary h3 { margin: 0; font-weight: 600; }

.agent-performance__summary-grid { display: flex; gap: 32px; margin-top: 12px; }

.agent-performance__summary-item { text-align: center; }

.agent-performance__summary-value { display: block; font-size: 1.5em; font-weight: 700; }

.agent-performance__summary-label { font-size: 0.85em; color: var(--color-text-lighter); }

.agent-performance__empty { padding: 40px; text-align: center; color: var(--color-text-lighter); }
</style>
