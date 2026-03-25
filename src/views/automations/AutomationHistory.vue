<template>
	<div class="automation-history">
		<div class="history-header">
			<NcButton type="tertiary" @click="$router.push({ name: 'Automations' })">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
			</NcButton>
			<h2>{{ t('pipelinq', 'Execution History') }}</h2>
		</div>

		<NcLoadingIcon v-if="loading" />

		<NcEmptyContent
			v-else-if="logs.length === 0"
			:name="t('pipelinq', 'No executions yet')"
			:description="t('pipelinq', 'This automation has not been triggered yet.')">
			<template #icon>
				<History :size="64" />
			</template>
		</NcEmptyContent>

		<table v-else class="history-table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Triggered at') }}</th>
					<th>{{ t('pipelinq', 'Status') }}</th>
					<th>{{ t('pipelinq', 'Trigger entity') }}</th>
					<th>{{ t('pipelinq', 'Actions') }}</th>
					<th>{{ t('pipelinq', 'Error') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="log in logs" :key="log.id" :class="{ 'log-failed': log.status === 'failure' }">
					<td>{{ formatDate(log.triggeredAt) }}</td>
					<td>
						<span :class="'status-badge status-' + log.status">
							{{ statusLabel(log.status) }}
						</span>
					</td>
					<td>{{ log.triggerEntity || '-' }}</td>
					<td>{{ (log.actionsExecuted || []).length }}</td>
					<td class="error-cell">
						{{ log.error || '-' }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import { useObjectStore } from '../../store/store.js'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import History from 'vue-material-design-icons/History.vue'

export default {
	name: 'AutomationHistory',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		ArrowLeft,
		History,
	},
	props: {
		automationId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			logs: [],
		}
	},
	mounted() {
		this.fetchHistory()
	},
	methods: {
		async fetchHistory() {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObjects('automationLog', {
					filters: { automation: this.automationId },
					orderBy: { triggeredAt: 'desc' },
				})
				this.logs = result?.results || []
			} catch (e) {
				console.error('Failed to load automation history', e)
			} finally {
				this.loading = false
			}
		},
		formatDate(dateStr) {
			return new Date(dateStr).toLocaleString('nl-NL')
		},
		statusLabel(status) {
			const labels = {
				success: this.t('pipelinq', 'Success'),
				failure: this.t('pipelinq', 'Failed'),
				partial: this.t('pipelinq', 'Partial'),
			}
			return labels[status] || status
		},
	},
}
</script>

<style scoped>
.automation-history {
	padding: 20px;
	max-width: 1200px;
}

.history-header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 20px;
}

.history-table {
	width: 100%;
	border-collapse: collapse;
}

.history-table th,
.history-table td {
	padding: 8px 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.history-table th {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.log-failed {
	background-color: var(--color-error-hover);
}

.status-badge {
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 0.85em;
}

.status-success {
	background-color: var(--color-success);
	color: white;
}

.status-failure {
	background-color: var(--color-error);
	color: white;
}

.status-partial {
	background-color: var(--color-warning);
	color: white;
}

.error-cell {
	max-width: 300px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}
</style>
