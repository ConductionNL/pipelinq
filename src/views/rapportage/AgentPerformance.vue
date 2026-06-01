<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!-- @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3 -->
<template>
	<div class="agent-performance">
		<div class="agent-performance__header">
			<h2>{{ t('pipelinq', 'Agent Performance') }}</h2>
			<router-link :to="{ name: 'Rapportage' }">
				{{ t('pipelinq', 'Back to Dashboard') }}
			</router-link>
		</div>

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

		<div v-if="error" class="error-message">
			{{ error }}
		</div>

		<NcLoadingIcon v-if="loading" />

		<template v-else>
			<div v-if="sortedAgents.length === 0" class="empty-state">
				<p>{{ t('pipelinq', 'No agent data available') }}</p>
			</div>

			<table v-else class="data-table">
				<thead>
					<tr>
						<th
							class="sortable"
							:class="{ 'sort-active': sortKey === 'agent' }"
							@click="setSort('agent')">
							{{ t('pipelinq', 'Agent') }}
						</th>
						<th
							class="sortable"
							:class="{ 'sort-active': sortKey === 'count' }"
							@click="setSort('count')">
							{{ t('pipelinq', 'Contacts Today') }}
						</th>
						<th
							class="sortable"
							:class="{ 'sort-active': sortKey === 'avgHandlingTime' }"
							@click="setSort('avgHandlingTime')">
							{{ t('pipelinq', 'Avg Time') }}
						</th>
						<th
							class="sortable"
							:class="{ 'sort-active': sortKey === 'fcrRate' }"
							@click="setSort('fcrRate')">
							{{ t('pipelinq', 'FCR Rate') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="agent in sortedAgents" :key="agent.id">
						<td class="agent-name">
							{{ agent.id }}
						</td>
						<td>{{ agent.count }}</td>
						<td>{{ agent.avgHandlingTime }}</td>
						<td>{{ agent.fcrRate }}%</td>
					</tr>
				</tbody>
			</table>

			<div v-if="sortedAgents.length > 0" class="team-summary">
				<h3>{{ t('pipelinq', 'Team Summary') }}</h3>
				<div class="summary-grid">
					<div class="summary-item">
						<span class="summary-value">{{ teamStats.totalContacts }}</span>
						<span class="summary-label">{{ t('pipelinq', 'Total contacts') }}</span>
					</div>
					<div class="summary-item">
						<span class="summary-value">{{ teamStats.avgFcr }}%</span>
						<span class="summary-label">{{ t('pipelinq', 'Team FCR') }}</span>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'AgentPerformance',

	components: { NcButton, NcLoadingIcon },

	data() {
		return {
			loading: false,
			error: null,
			selectedRange: 'today',
			sortKey: 'count',
			sortDir: 'desc',
			agents: [],
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

			const firstDay = new Date(now.getFullYear(), now.getMonth(), 1)
			return { from: fmt(firstDay), to: fmt(now) }
		},

		/**
		 * Agents sorted by selected column (default: contacts descending).
		 *
		 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3
		 */
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

		/**
		 * Team-wide aggregated stats.
		 */
		teamStats() {
			const total = this.agents.reduce((s, a) => s + (a.count ?? 0), 0)
			const avgFcr = this.agents.length > 0
				? Math.round(this.agents.reduce((s, a) => s + (a.fcrRate ?? 0), 0) / this.agents.length)
				: 0

			return { totalContacts: total, avgFcr }
		},
	},

	mounted() {
		this.fetchData()
	},

	methods: {
		/**
		 * @param {string} range
		 */
		selectRange(range) {
			this.selectedRange = range
			this.fetchData()
		},

		/**
		 * @param {string} key Column sort key.
		 */
		setSort(key) {
			if (this.sortKey === key) {
				this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'
			} else {
				this.sortKey = key
				this.sortDir = 'desc'
			}
		},

		/**
		 * Fetch agent performance data.
		 *
		 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-3
		 */
		async fetchData() {
			this.loading = true
			this.error = null
			const { from, to } = this.dateRange

			try {
				const resp = await fetch(generateUrl(`/apps/pipelinq/api/rapportage/agents?from=${from}&to=${to}`))

				if (!resp.ok) {
					this.agents = []
					return
				}

				const data = await resp.json()
				const agentMap = data.agents ?? {}

				// Only include agents with ≥ 1 contact in selected period.
				this.agents = Object.entries(agentMap)
					.filter(([, metrics]) => (metrics.count ?? 0) >= 1)
					.map(([id, metrics]) => ({
						id,
						count: metrics.count ?? 0,
						fcrRate: metrics.fcrRate ?? 0,
						avgHandlingTime: metrics.avgHandlingTime ?? '0:00',
					}))
			} catch {
				this.error = t('pipelinq', 'Failed to load agent data')
				this.agents = []
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.agent-performance { padding: 20px; max-width: 1000px; margin: 0 auto; }

.agent-performance__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }

.date-range-selector { display: flex; gap: 4px; margin-bottom: 20px; }

.data-table { width: 100%; border-collapse: collapse; }

.data-table th, .data-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }

.data-table th { font-weight: 600; font-size: 0.85em; color: var(--color-text-lighter); }

.data-table th.sortable { cursor: pointer; user-select: none; }

.data-table th.sortable:hover { color: var(--color-text-maxcontrast); }

.data-table th.sort-active { color: var(--color-primary); }

.team-summary { margin-top: 24px; padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }

.summary-grid { display: flex; gap: 32px; margin-top: 12px; }

.summary-item { text-align: center; }

.summary-value { display: block; font-size: 1.5em; font-weight: 700; }

.summary-label { font-size: 0.85em; color: var(--color-text-lighter); }

.empty-state { padding: 40px; text-align: center; color: var(--color-text-lighter); }

.error-message { padding: 12px 16px; background: var(--color-error-bg, #fde8e8); color: var(--color-error, #c0392b); border-radius: var(--border-radius); margin-bottom: 16px; }
</style>
