<template>
	<div class="complaints-widget" @click="$router.push({ name: 'Complaints' })">
		<div v-if="loading" class="widget-loading">
			{{ t('pipelinq', 'Loading...') }}
		</div>
		<div v-else-if="totalOpen === 0" class="widget-empty">
			{{ t('pipelinq', 'No open complaints') }}
		</div>
		<div v-else class="widget-content">
			<div class="widget-stat widget-stat--total">
				<span class="stat-count">{{ totalOpen }}</span>
				<span class="stat-label">{{ t('pipelinq', 'Open') }}</span>
			</div>
			<div v-if="overdueCount > 0" class="widget-stat widget-stat--overdue">
				<span class="stat-count">{{ overdueCount }}</span>
				<span class="stat-label">{{ t('pipelinq', 'Overdue') }}</span>
			</div>
			<div class="widget-breakdown">
				<span v-if="newCount > 0" class="breakdown-item">
					<span class="breakdown-dot" style="background: #0082c9;" />
					{{ newCount }} {{ t('pipelinq', 'new') }}
				</span>
				<span v-if="inProgressCount > 0" class="breakdown-item">
					<span class="breakdown-dot" style="background: #e9a400;" />
					{{ inProgressCount }} {{ t('pipelinq', 'in progress') }}
				</span>
			</div>
		</div>
	</div>
</template>

<script>
export default {
	name: 'ComplaintsOverviewWidget',
	props: {
		complaints: {
			type: Array,
			default: () => [],
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},
	computed: {
		openComplaints() {
			return this.complaints.filter(c => c.status === 'new' || c.status === 'in_progress')
		},
		totalOpen() {
			return this.openComplaints.length
		},
		newCount() {
			return this.complaints.filter(c => c.status === 'new').length
		},
		inProgressCount() {
			return this.complaints.filter(c => c.status === 'in_progress').length
		},
		overdueCount() {
			const now = new Date()
			return this.openComplaints.filter(c => {
				if (!c.slaDeadline) return false
				return new Date(c.slaDeadline) < now
			}).length
		},
	},
}
</script>

<style scoped>
.complaints-widget {
	padding: 12px;
	cursor: pointer;
	height: 100%;
}

.complaints-widget:hover {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.widget-loading,
.widget-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 16px;
	font-size: 14px;
}

.widget-content {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.widget-stat {
	display: flex;
	align-items: baseline;
	gap: 6px;
}

.stat-count {
	font-size: 28px;
	font-weight: 700;
	line-height: 1;
}

.stat-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.widget-stat--overdue .stat-count {
	color: #e9322d;
}

.widget-stat--overdue .stat-label {
	color: #e9322d;
}

.widget-breakdown {
	display: flex;
	gap: 12px;
	margin-top: 4px;
}

.breakdown-item {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.breakdown-dot {
	width: 8px;
	height: 8px;
	border-radius: 50%;
	flex-shrink: 0;
}
</style>
