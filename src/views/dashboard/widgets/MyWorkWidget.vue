<template>
	<div class="my-work-widget-content">
		<div v-if="!loaded" class="chart-empty">
			{{ t('pipelinq', 'Loading…') }}
		</div>
		<div v-else-if="items.length === 0" class="chart-empty">
			{{ t('pipelinq', 'No items assigned to you') }}
		</div>
		<div v-else class="my-work-list">
			<div
				v-for="item in items"
				:key="item.id"
				class="my-work-item"
				:class="{ 'my-work-item--overdue': item.isOverdue }"
				@click="openItem(item)">
				<span class="entity-badge" :class="'badge--' + item.entityType">
					{{ item.entityType === 'lead' ? 'LEAD' : 'REQ' }}
				</span>
				<span class="my-work-title">{{ item.title }}</span>
				<span class="my-work-stage">{{ item.stageOrStatus }}</span>
				<span v-if="item.dueDate" class="my-work-due" :class="{ overdue: item.isOverdue }">
					{{ formatDate(item.dueDate) }}
				</span>
			</div>
			<NcButton
				v-if="total > 5"
				type="tertiary"
				class="view-all-link"
				@click="$router.push({ name: 'MyWork' })">
				{{ t('pipelinq', 'View all ({count})', { count: total }) }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { getMyLeads, getMyRequests, getPipelines, getClosedStageNames } from '../../../services/dashboardData.js'
import { getStatusLabel } from '../../../services/requestStatus.js'
import { formatDate } from '../../../services/localeUtils.js'

const PRIORITY_ORDER = { urgent: 0, high: 1, normal: 2, low: 3 }

export default {
	name: 'MyWorkWidget',
	components: {
		NcButton,
	},
	data() {
		return {
			loaded: false,
			myLeads: [],
			myRequests: [],
			pipelines: [],
		}
	},
	computed: {
		allItems() {
			const closed = getClosedStageNames(this.pipelines)
			const now = new Date()
			const items = []

			for (const l of this.myLeads) {
				if (closed.has(l.stage)) continue
				const due = l.expectedCloseDate ? new Date(l.expectedCloseDate) : null
				items.push({
					id: l.id,
					entityType: 'lead',
					title: l.title,
					stageOrStatus: l.stage || '-',
					priority: l.priority || 'normal',
					dueDate: l.expectedCloseDate,
					isOverdue: due ? due < now : false,
				})
			}

			for (const r of this.myRequests) {
				if (r.status === 'completed' || r.status === 'rejected' || r.status === 'converted') continue
				const due = r.requestedAt ? new Date(r.requestedAt) : null
				items.push({
					id: r.id,
					entityType: 'request',
					title: r.title,
					stageOrStatus: getStatusLabel(r.status),
					priority: r.priority || 'normal',
					dueDate: r.requestedAt,
					isOverdue: due ? (now - due) > 30 * 24 * 60 * 60 * 1000 : false,
				})
			}

			items.sort((a, b) => {
				if (a.isOverdue !== b.isOverdue) return a.isOverdue ? -1 : 1
				const pa = PRIORITY_ORDER[a.priority] ?? 2
				const pb = PRIORITY_ORDER[b.priority] ?? 2
				if (pa !== pb) return pa - pb
				if (a.dueDate && b.dueDate) return new Date(a.dueDate) - new Date(b.dueDate)
				if (a.dueDate) return -1
				if (b.dueDate) return 1
				return 0
			})

			return items
		},
		total() {
			return this.allItems.length
		},
		items() {
			return this.allItems.slice(0, 5)
		},
	},
	async mounted() {
		try {
			const [myLeads, myRequests, pipelines] = await Promise.all([
				getMyLeads(), getMyRequests(), getPipelines(),
			])
			this.myLeads = myLeads
			this.myRequests = myRequests
			this.pipelines = pipelines
		} catch (err) {
			console.error('MyWorkWidget fetch error:', err)
		} finally {
			this.loaded = true
		}
	},
	methods: {
		formatDate,
		openItem(item) {
			if (item.entityType === 'lead') {
				this.$router.push({ name: 'LeadDetail', params: { id: item.id } })
			} else {
				this.$router.push({ name: 'RequestDetail', params: { id: item.id } })
			}
		},
	},
}
</script>

<style scoped>
.my-work-widget-content {
	padding: 4px 0;
	height: 100%;
	overflow: auto;
}

.chart-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.my-work-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.my-work-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	cursor: pointer;
}

.my-work-item:hover {
	background: var(--color-background-hover);
}

.my-work-item--overdue {
	background: rgba(233, 50, 45, 0.04);
}

.entity-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.5px;
	flex-shrink: 0;
}

.badge--lead {
	background: #dbeafe;
	color: #1d4ed8;
	border: 1px solid #93c5fd;
}

.badge--request {
	background: #ffedd5;
	color: #c2410c;
	border: 1px solid #fdba74;
}

.my-work-title {
	flex: 1;
	font-size: 13px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.my-work-stage {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.my-work-due {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.my-work-due.overdue {
	color: var(--color-error);
	font-weight: 600;
}

.view-all-link {
	margin-top: 4px;
	padding-left: 12px;
}
</style>
