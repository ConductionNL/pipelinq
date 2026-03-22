<template>
	<div class="channel-analytics">
		<div class="channel-analytics__header">
			<h2>{{ t('pipelinq', 'Channel Analytics') }}</h2>
			<router-link :to="{ name: 'Rapportage' }">
				{{ t('pipelinq', 'Back to Dashboard') }}
			</router-link>
		</div>

		<div class="channel-analytics__controls">
			<div class="granularity-buttons">
				<NcButton
					v-for="opt in granularityOptions"
					:key="opt.value"
					:type="granularity === opt.value ? 'primary' : 'secondary'"
					@click="granularity = opt.value">
					{{ opt.label }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" />

		<template v-else>
			<!-- Channel Comparison Table -->
			<div class="comparison-table">
				<h3>{{ t('pipelinq', 'Channel Comparison') }}</h3>
				<table class="data-table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Channel') }}</th>
							<th>{{ t('pipelinq', 'Total') }}</th>
							<th>{{ t('pipelinq', 'Avg Time') }}</th>
							<th>{{ t('pipelinq', 'FCR Rate') }}</th>
							<th>{{ t('pipelinq', 'SLA') }}</th>
							<th>{{ t('pipelinq', 'vs Last Month') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in channelComparison" :key="row.channel">
							<td class="channel-name">
								<span class="channel-dot" :style="{ background: row.color }" />
								{{ row.channel }}
							</td>
							<td>{{ row.total }}</td>
							<td>{{ row.avgTime }}</td>
							<td>{{ row.fcrRate }}%</td>
							<td :class="'sla--' + row.slaStatus">{{ row.slaCompliance }}%</td>
							<td :class="row.trend > 0 ? 'trend--up' : 'trend--down'">
								{{ row.trend > 0 ? '+' : '' }}{{ row.trend }}%
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div v-if="channelComparison.length === 0" class="empty-state">
				<p>{{ t('pipelinq', 'No data available for the selected period') }}</p>
			</div>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'ChannelAnalytics',
	components: { NcButton, NcLoadingIcon },
	data() {
		return {
			loading: false,
			granularity: 'daily',
			granularityOptions: [
				{ value: 'daily', label: t('pipelinq', 'Daily') },
				{ value: 'weekly', label: t('pipelinq', 'Weekly') },
				{ value: 'monthly', label: t('pipelinq', 'Monthly') },
			],
			channelComparison: [],
		}
	},
	mounted() { this.fetchData() },
	methods: {
		async fetchData() {
			this.loading = true
			try { this.channelComparison = [] } finally { this.loading = false }
		},
	},
}
</script>

<style scoped>
.channel-analytics { padding: 20px; max-width: 1000px; margin: 0 auto; }
.channel-analytics__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.channel-analytics__controls { margin-bottom: 20px; }
.granularity-buttons { display: flex; gap: 4px; }
.data-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
.data-table th, .data-table td { padding: 10px 12px; text-align: left; border-bottom: 1px solid var(--color-border); }
.data-table th { font-weight: 600; font-size: 0.85em; color: var(--color-text-lighter); text-transform: uppercase; }
.channel-name { display: flex; align-items: center; gap: 8px; }
.channel-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
.sla--green { color: var(--color-success); }
.sla--orange { color: var(--color-warning); }
.sla--red { color: #e53e3e; }
.trend--up { color: var(--color-success); }
.trend--down { color: #e53e3e; }
.empty-state { padding: 40px; text-align: center; color: var(--color-text-lighter); }
</style>
