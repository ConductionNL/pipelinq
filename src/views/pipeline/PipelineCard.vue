<template>
	<div
		class="pipeline-card"
		:class="['pipeline-card--' + entityType, { 'pipeline-card--overdue': isOverdue }]"
		draggable="true"
		@dragstart="onDragStart"
		@click="$emit('open', item)">
		<div class="pipeline-card__header">
			<span class="entity-badge" :class="'badge--' + entityType">
				{{ entityType === 'lead' ? 'LEAD' : 'REQ' }}
			</span>
			<span
				v-if="item.priority && item.priority !== 'normal'"
				class="priority-badge"
				:style="{ color: getPriorityColor(item.priority) }">
				{{ getPriorityLabel(item.priority) }}
			</span>
			<span v-if="isStaleItem" class="stale-badge">
				{{ t('pipelinq', 'Stale') }}
			</span>
		</div>
		<div class="pipeline-card__title">
			{{ item.title }}
		</div>
		<div class="pipeline-card__meta">
			<span v-if="entityType === 'lead' && item.value" class="card-value">
				EUR {{ Number(item.value).toLocaleString('nl-NL') }}
			</span>
			<span v-if="entityType === 'request' && item.status" class="card-status">
				{{ getStatusLabel(item.status) }}
			</span>
		</div>
		<div class="pipeline-card__footer">
			<span v-if="item.assignee" class="card-assignee">
				{{ item.assignee }}
			</span>
			<span class="card-footer-right">
				<span v-if="item.expectedCloseDate" class="card-date" :class="{ overdue: isOverdue }">
					{{ formatDate(item.expectedCloseDate) }}
				</span>
				<span v-if="daysAge > 0" class="aging-badge" :class="agingClass">
					{{ agingLabel }}
				</span>
			</span>
		</div>
		<div class="pipeline-card__actions" @click.stop>
			<NcSelect
				v-model="selectedStage"
				:options="stageOptions"
				:clearable="false"
				:placeholder="t('pipelinq', 'Stage')"
				class="quick-select quick-select--stage"
				@input="onStageChange" />
			<NcSelect
				v-model="selectedAssignee"
				:options="userOptions"
				:clearable="true"
				:placeholder="t('pipelinq', 'Assign')"
				class="quick-select quick-select--assign"
				@input="onAssignChange" />
		</div>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { getPriorityLabel, getPriorityColor, getStatusLabel } from '../../services/requestStatus.js'
import { getDaysAge, isStale, getAgingClass, formatAge } from '../../services/pipelineUtils.js'
import { useObjectStore } from '../../store/modules/object.js'

// Module-level user cache shared across all PipelineCard instances
let usersCache = null

export default {
	name: 'PipelineCard',
	components: {
		NcSelect,
	},
	props: {
		item: {
			type: Object,
			required: true,
		},
		entityType: {
			type: String,
			default: 'lead',
			validator: v => ['lead', 'request'].includes(v),
		},
		stages: {
			type: Array,
			default: () => [],
		},
	},
	data() {
		return {
			users: [],
			selectedStage: null,
			selectedAssignee: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isOverdue() {
			if (this.entityType === 'lead') {
				if (!this.item.expectedCloseDate) return false
				return new Date(this.item.expectedCloseDate) < new Date()
			}
			if (this.entityType === 'request') {
				if (!this.item.requestedAt) return false
				const daysSince = Math.floor((Date.now() - new Date(this.item.requestedAt).getTime()) / 86400000)
				return daysSince > 30
			}
			return false
		},
		daysAge() {
			return getDaysAge(this.item)
		},
		agingClass() {
			return getAgingClass(this.daysAge)
		},
		agingLabel() {
			return formatAge(this.daysAge)
		},
		isStaleItem() {
			return isStale(this.item, this.entityType)
		},
		stageOptions() {
			return this.stages.map(s => s.name)
		},
		userOptions() {
			return this.users
		},
	},
	watch: {
		'item.stage': {
			immediate: true,
			handler(val) {
				this.selectedStage = val || null
			},
		},
		'item.assignee': {
			immediate: true,
			handler(val) {
				this.selectedAssignee = val || null
			},
		},
	},
	async mounted() {
		await this.loadUsers()
	},
	methods: {
		getPriorityLabel,
		getPriorityColor,
		getStatusLabel,

		async loadUsers() {
			if (usersCache) {
				this.users = usersCache
				return
			}
			try {
				const response = await fetch('/ocs/v2.php/cloud/users?format=json', {
					headers: {
						'OCS-APIREQUEST': 'true',
						requesttoken: OC.requestToken,
					},
				})
				if (response.ok) {
					const data = await response.json()
					usersCache = data?.ocs?.data?.users || []
					this.users = usersCache
				}
			} catch {
				this.users = []
			}
		},

		async onStageChange(newStage) {
			if (!newStage || newStage === this.item.stage) return
			try {
				const updated = { ...this.item }
				delete updated._entityType
				updated.stage = newStage
				await this.objectStore.saveObject(this.entityType, updated)
				this.$emit('refresh')
			} catch {
				// Revert on failure
				this.selectedStage = this.item.stage
			}
		},

		async onAssignChange(newAssignee) {
			if (newAssignee === this.item.assignee) return
			try {
				const updated = { ...this.item }
				delete updated._entityType
				updated.assignee = newAssignee || ''
				await this.objectStore.saveObject(this.entityType, updated)
				this.$emit('refresh')
			} catch {
				// Revert on failure
				this.selectedAssignee = this.item.assignee
			}
		},

		onDragStart(e) {
			e.dataTransfer.setData('application/json', JSON.stringify({
				id: this.item.id,
				entityType: this.entityType,
				stage: this.item.stage,
			}))
			e.dataTransfer.effectAllowed = 'move'
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			try {
				return new Date(dateStr).toLocaleDateString('nl-NL', { month: 'short', day: 'numeric' })
			} catch {
				return dateStr
			}
		},
	},
}
</script>

<style scoped>
.pipeline-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 10px 12px;
	cursor: pointer;
	transition: box-shadow 0.15s;
}

.pipeline-card:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.pipeline-card[draggable="true"] {
	cursor: grab;
}

.pipeline-card__header {
	display: flex;
	align-items: center;
	gap: 6px;
	margin-bottom: 4px;
}

.entity-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.5px;
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

.priority-badge {
	font-size: 11px;
	font-weight: 600;
}

.pipeline-card__title {
	font-weight: 600;
	font-size: 13px;
	margin-bottom: 4px;
	line-height: 1.3;
}

.pipeline-card__meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.card-value {
	font-weight: 600;
	color: var(--color-text-light);
}

.pipeline-card__footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.card-assignee {
	max-width: 100px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.pipeline-card--overdue {
	border-left: 3px solid var(--color-error);
}

.card-footer-right {
	display: flex;
	align-items: center;
	gap: 6px;
}

.card-date.overdue {
	color: var(--color-error);
	font-weight: 600;
}

.stale-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	background: #fff7ed;
	color: #c2410c;
	border: 1px solid #fdba74;
}

.aging-badge {
	display: inline-block;
	padding: 1px 5px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	background: var(--color-background-dark);
}

.aging-badge.aging-warning {
	color: #92400e;
	background: #fef3c7;
}

.aging-badge.aging-alert {
	color: #991b1b;
	background: #fee2e2;
}

.pipeline-card__actions {
	display: flex;
	gap: 6px;
	margin-top: 8px;
	border-top: 1px solid var(--color-border);
	padding-top: 8px;
}

.quick-select {
	flex: 1;
	min-width: 0;
}

.quick-select :deep(.vs__dropdown-toggle) {
	min-height: 28px;
	font-size: 11px;
}

.quick-select :deep(.vs__search) {
	font-size: 11px;
}

.quick-select :deep(.vs__selected) {
	font-size: 11px;
}
</style>
