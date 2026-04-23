<template>
	<div class="reporting-dashboard">
		<!-- Header -->
		<div class="dashboard__header">
			<h2>{{ t('pipelinq', 'Contact Moments Dashboard') }}</h2>
			<div class="dashboard__controls">
				<button class="dashboard__refresh-btn" @click="refreshData" :disabled="loading">
					{{ t('pipelinq', 'Refresh') }}
				</button>
				<span v-if="lastUpdated" class="dashboard__timestamp">
					{{ t('pipelinq', 'Last updated: {time}', { time: lastUpdated }) }}
				</span>
			</div>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="dashboard__loading">
			<NcLoadingIcon />
		</div>

		<!-- Error state -->
		<div v-else-if="error" class="dashboard__error">
			<p>{{ error }}</p>
			<NcButton @click="refreshData">{{ t('pipelinq', 'Retry') }}</NcButton>
		</div>

		<!-- Empty state -->
		<div v-else-if="!kpiData || Object.keys(kpiData).length === 0" class="dashboard__empty">
			<p>{{ t('pipelinq', 'No contact moments registered today') }}</p>
		</div>

		<!-- KPI Widgets -->
		<div v-else class="dashboard__grid">
			<!-- Total Contacts -->
			<div class="kpi-widget" :class="{ 'kpi-widget--highlight': kpiData.totalContacts > 0 }">
				<h3>{{ t('pipelinq', 'Total Contacts') }}</h3>
				<div class="kpi-value">{{ kpiData.totalContacts }}</div>
				<div v-if="kpiData.trend" :class="['kpi-trend', 'kpi-trend--' + kpiData.trendDirection]">
					{{ kpiData.trendDirection === 'up' ? '↑' : '↓' }} {{ Math.abs(kpiData.trend) }}%
				</div>
			</div>

			<!-- Average Handling Time -->
			<div class="kpi-widget">
				<h3>{{ t('pipelinq', 'Avg. Handling Time') }}</h3>
				<div class="kpi-value">{{ kpiData.avgHandlingTime }}</div>
			</div>

			<!-- FCR Rate -->
			<div class="kpi-widget" :class="{ 'kpi-widget--status': kpiData.fcrRate }">
				<h3>{{ t('pipelinq', 'First Call Resolution') }}</h3>
				<div class="kpi-value">{{ kpiData.fcrRate.toFixed(1) }}%</div>
			</div>

			<!-- Queue Length -->
			<div class="kpi-widget">
				<h3>{{ t('pipelinq', 'Queue Length') }}</h3>
				<div class="kpi-value">{{ kpiData.queueLength }}</div>
			</div>

			<!-- Channel Breakdown -->
			<div class="kpi-widget kpi-widget--full">
				<h3>{{ t('pipelinq', 'Contacts by Channel') }}</h3>
				<div class="channel-breakdown">
					<div v-for="(count, channel) in kpiData.byChannel" :key="channel" class="channel-item">
						<span class="channel-name">{{ channel }}</span>
						<span class="channel-count">{{ count }}</span>
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from '@nextcloud/axios'
import { NcLoadingIcon, NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

const { t } = useI18n()

const loading = ref(false)
const error = ref(null)
const kpiData = ref(null)
const lastUpdated = ref(null)
const refreshInterval = ref(null)

/**
 * Fetch KPI data from the API.
 *
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-12
 */
const fetchKpiData = async () => {
	loading.value = true
	error.value = null

	try {
		const response = await axios.get(
			generateUrl('/apps/pipelinq/api/rapportage/kpi/daily'),
		)
		kpiData.value = response.data
		lastUpdated.value = new Date().toLocaleTimeString()
	} catch (err) {
		console.error('Failed to load KPI data:', err)
		error.value = t('pipelinq', 'Failed to load KPI dashboard data')
	} finally {
		loading.value = false
	}
}

/**
 * Refresh the data and reschedule auto-refresh.
 *
 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-12
 */
const refreshData = () => {
	fetchKpiData()
	// Clear existing interval
	if (refreshInterval.value) {
		clearInterval(refreshInterval.value)
	}
	// Schedule next refresh (60 seconds)
	refreshInterval.value = setInterval(fetchKpiData, 60000)
}

onMounted(() => {
	fetchKpiData()
	// Auto-refresh every 60 seconds
	refreshInterval.value = setInterval(fetchKpiData, 60000)
})

// Cleanup on unmount
onUnmounted(() => {
	if (refreshInterval.value) {
		clearInterval(refreshInterval.value)
	}
})
</script>

<style scoped>
.reporting-dashboard {
	padding: 20px;
	background: var(--color-main-background);
}

.dashboard__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 30px;
	padding-bottom: 15px;
	border-bottom: 1px solid var(--color-border);
}

.dashboard__header h2 {
	margin: 0;
	font-size: 1.5em;
}

.dashboard__controls {
	display: flex;
	align-items: center;
	gap: 15px;
}

.dashboard__refresh-btn {
	padding: 8px 16px;
	background: var(--color-primary);
	color: white;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	font-size: 0.9em;
}

.dashboard__refresh-btn:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.dashboard__timestamp {
	font-size: 0.85em;
	color: var(--color-text-secondary);
}

.dashboard__loading,
.dashboard__error,
.dashboard__empty {
	padding: 40px 20px;
	text-align: center;
	color: var(--color-text-secondary);
}

.dashboard__error {
	background: #fff3cd;
	border: 1px solid #ffc107;
	border-radius: 4px;
	color: #333;
}

.dashboard__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 20px;
	margin-bottom: 30px;
}

.kpi-widget {
	background: var(--color-surface);
	border: 1px solid var(--color-border);
	border-radius: 8px;
	padding: 20px;
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
	transition: all 0.3s ease;
}

.kpi-widget:hover {
	box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.kpi-widget h3 {
	margin: 0 0 15px 0;
	font-size: 0.95em;
	color: var(--color-text-secondary);
	font-weight: 500;
	text-transform: uppercase;
}

.kpi-widget--full {
	grid-column: 1 / -1;
}

.kpi-value {
	font-size: 2.5em;
	font-weight: bold;
	color: var(--color-primary);
	margin-bottom: 10px;
}

.kpi-trend {
	font-size: 0.9em;
	font-weight: 500;
}

.kpi-trend--up {
	color: #4caf50;
}

.kpi-trend--down {
	color: #f44336;
}

.kpi-widget--highlight {
	border-color: var(--color-primary);
	background: linear-gradient(135deg, var(--color-surface) 0%, rgba(0, 120, 215, 0.05) 100%);
}

.channel-breakdown {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
	gap: 12px;
}

.channel-item {
	background: var(--color-background-hover);
	padding: 12px;
	border-radius: 4px;
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.channel-name {
	font-weight: 500;
	font-size: 0.9em;
	text-transform: capitalize;
}

.channel-count {
	font-weight: bold;
	font-size: 1.2em;
	color: var(--color-primary);
	margin-left: 8px;
}
</style>
