<template>
	<div class="status-widget-content">
		<div v-if="!loaded" class="chart-empty">
			{{ t('pipelinq', 'Loading…') }}
		</div>
		<div v-else-if="rows.length === 0" class="chart-empty">
			{{ t('pipelinq', 'No requests yet') }}
		</div>
		<div v-else class="status-chart">
			<div v-for="row in rows" :key="row.key" class="status-bar-row">
				<span class="status-bar-label">{{ row.label }}</span>
				<div class="status-bar-track">
					<div
						class="status-bar-fill"
						:style="{ width: row.pct + '%', background: row.color }" />
				</div>
				<span class="status-bar-count">{{ row.count }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { getRequests } from '../../../services/dashboardData.js'
import { getStatusLabel, getStatusColor } from '../../../services/requestStatus.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

const STATUS_KEYS = ['new', 'in_progress', 'completed', 'rejected', 'converted']

export default {
	name: 'RequestsByStatusWidget',
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			loaded: false,
			requests: [],
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-18
		 */
		rows() {
			const counts = {}
			for (const r of this.requests) {
				const s = r.status || 'new'
				counts[s] = (counts[s] || 0) + 1
			}
			const max = Math.max(...Object.values(counts), 1)
			return STATUS_KEYS.filter((s) => counts[s] > 0).map((s) => ({
				key: s,
				label: getStatusLabel(s),
				color: getStatusColor(s),
				count: counts[s],
				pct: (counts[s] / max) * 100,
			}))
		},
	},
	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-17
		 */
		async load() {
			try {
				this.requests = await getRequests()
			} catch (err) {
				console.error('RequestsByStatusWidget fetch error:', err)
			} finally {
				this.loaded = true
			}
		},
	},
}
</script>

<style scoped>
.status-widget-content {
	padding: 12px;
	height: 100%;
}

.chart-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.status-chart {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.status-bar-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.status-bar-label {
	width: 110px;
	font-size: 13px;
	text-align: right;
	flex-shrink: 0;
}

.status-bar-track {
	flex: 1;
	height: 22px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
}

.status-bar-fill {
	height: 100%;
	border-radius: 4px;
	min-width: 2px;
	transition: width 0.3s ease;
}

.status-bar-count {
	width: 30px;
	font-size: 13px;
	font-weight: 600;
	text-align: right;
	flex-shrink: 0;
}

@media (prefers-reduced-motion: reduce) {
	.status-bar-fill {
		transition: none;
	}
}
</style>
