<template>
	<div class="agent-performance">
		<div class="agent-performance__header">
			<h2>{{ t('pipelinq', 'Agent Performance') }}</h2>
			<router-link :to="{ name: 'Rapportage' }">
				{{ t('pipelinq', 'Back to Dashboard') }}
			</router-link>
		</div>

		<NcLoadingIcon v-if="loading" />

		<template v-else>
			<div v-if="agents.length === 0" class="empty-state">
				<p>{{ t('pipelinq', 'No agent data available') }}</p>
			</div>

			<table v-else class="data-table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Agent') }}</th>
						<th>{{ t('pipelinq', 'Contacts Today') }}</th>
						<th>{{ t('pipelinq', 'Avg Time') }}</th>
						<th>{{ t('pipelinq', 'FCR Rate') }}</th>
						<th>{{ t('pipelinq', 'Per Hour') }}</th>
						<th>{{ t('pipelinq', 'Status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="agent in agents"
						:key="agent.uid"
						:class="{ 'agent-row--highlight': agent.isAboveAverage || agent.isBelowAverage }">
						<td class="agent-name">
							{{ agent.displayName }}
						</td>
						<td>{{ agent.contactsToday }}</td>
						<td>{{ agent.avgHandlingTime }}</td>
						<td>{{ agent.fcrRate }}%</td>
						<td>{{ agent.contactsPerHour }}</td>
						<td>
							<span class="agent-status" :class="'agent-status--' + agent.status">
								{{ agent.status }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<div class="team-summary">
				<h3>{{ t('pipelinq', 'Team Summary') }}</h3>
				<div class="summary-grid">
					<div class="summary-item">
						<span class="summary-value">{{ teamStats.totalContacts }}</span>
						<span class="summary-label">{{ t('pipelinq', 'Total contacts') }}</span>
					</div>
					<div class="summary-item">
						<span class="summary-value">{{ teamStats.avgHandlingTime }}</span>
						<span class="summary-label">{{ t('pipelinq', 'Team avg time') }}</span>
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
import { NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'AgentPerformance',
	components: { NcLoadingIcon },
	data() {
		return {
			loading: false,
			agents: [],
			teamStats: { totalContacts: 0, avgHandlingTime: '0:00', avgFcr: 0 },
		}
	},
	mounted() { this.fetchData() },
	methods: {
		async fetchData() {
			this.loading = true
			try { this.agents = [] } finally { this.loading = false }
		},
	},
}
</script>

<style scoped>
.agent-performance { padding: 20px; max-width: 1000px; margin: 0 auto; }
.agent-performance__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
.data-table th { font-weight: 600; font-size: 0.85em; color: var(--color-text-lighter); }
.agent-row--highlight { background: var(--color-background-hover); }
.agent-status { padding: 2px 8px; border-radius: var(--border-radius); font-size: 0.75em; }
.agent-status--beschikbaar { background: var(--color-success); color: #fff; }
.agent-status--in_gesprek { background: var(--color-warning); color: #000; }
.agent-status--pauze { background: var(--color-background-dark); }
.team-summary { margin-top: 24px; padding: 16px; border: 1px solid var(--color-border); border-radius: var(--border-radius-large); }
.summary-grid { display: flex; gap: 32px; margin-top: 12px; }
.summary-item { text-align: center; }
.summary-value { display: block; font-size: 1.5em; font-weight: 700; }
.summary-label { font-size: 0.85em; color: var(--color-text-lighter); }
.empty-state { padding: 40px; text-align: center; color: var(--color-text-lighter); }
</style>
