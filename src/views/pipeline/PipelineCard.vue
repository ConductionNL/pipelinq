<template>
	<div
		class="pipeline-card"
		:class="{ 'pipeline-card--overdue': isOverdue }"
		draggable="true"
		@dragstart="onDragStart"
		@click="$emit('open', item)">
		<!-- Compact single-row layout: badge → title → meta → age -->
		<div class="pipeline-card__row">
			<span class="entity-badge" :class="'badge--' + entityType">
				{{ entityType.toUpperCase().slice(0, 4) }}
			</span>
			<span class="pipeline-card__title">
				{{ item.title }}
			</span>
			<span v-if="item.priority && item.priority !== 'normal'" class="priority-indicator" :class="'priority--' + item.priority" />
			<span v-if="item.value" class="card-meta">
				{{ formatNumber(item.value) }}
			</span>
			<span v-if="item.assignee" class="card-assignee">
				{{ item.assignee }}
			</span>
			<span v-if="daysAge > 0" class="aging-badge" :class="agingClass">
				{{ agingLabel }}
			</span>
			<span v-if="item.expectedCloseDate" class="card-date" :class="{ 'card-date--overdue': isOverdue }">
				{{ formatDate(item.expectedCloseDate) }}
			</span>
		</div>
		<!-- Quick actions row (click.stop prevents card navigation) -->
		<div class="pipeline-card__actions" @click.stop>
			<NcSelect
				v-model="selectedStage"
				:options="stageOptions"
				:clearable="false"
				:placeholder="t('pipelinq', 'Stage')"
				class="quick-select"
				@input="onStageChange" />
			<NcSelect
				v-model="selectedAssignee"
				:options="userOptions"
				:clearable="true"
				:placeholder="t('pipelinq', 'Assign')"
				class="quick-select"
				@input="onAssignChange" />
		</div>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import { getPriorityLabel, getPriorityColor, getStatusLabel } from '../../services/requestStatus.js'
import { getDaysAge, isStale, getAgingClass, formatAge } from '../../services/pipelineUtils.js'
import { useObjectStore } from '../../store/modules/object.js'
import { formatNumber, formatDate as formatLocaleDate } from '../../services/localeUtils.js'

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
		},
		stages: {
			type: Array,
			default: () => [],
		},
		columnProperty: {
			type: String,
			default: 'stage',
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
		currentColumnValue() {
			return this.item[this.columnProperty] || ''
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
		currentColumnValue: {
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
		formatNumber,
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
			if (!newStage || newStage === this.currentColumnValue) return
			try {
				const updated = { ...this.item }
				delete updated._entityType
				delete updated._schemaSlug
				updated[this.columnProperty] = newStage
				await this.objectStore.saveObject(this.entityType, updated)
				this.$emit('refresh')
			} catch {
				// Revert on failure
				this.selectedStage = this.currentColumnValue
			}
		},

		async onAssignChange(newAssignee) {
			if (newAssignee === this.item.assignee) return
			try {
				const updated = { ...this.item }
				delete updated._entityType
				delete updated._schemaSlug
				updated.assignee = newAssignee || ''
				await this.objectStore.saveObject(this.entityType, updated)
				this.$emit('refresh')
			} catch {
				// Revert on failure
				this.selectedAssignee = this.item.assignee
			}
		},

		onDragStart(e) {
			const data = {
				id: this.item.id,
				_schemaSlug: this.entityType,
			}
			data[this.columnProperty] = this.currentColumnValue
			e.dataTransfer.setData('application/json', JSON.stringify(data))
			e.dataTransfer.effectAllowed = 'move'
		},

		formatDate(dateStr) {
			return formatLocaleDate(dateStr)
		},
	},
}
</script>

<style scoped>
.pipeline-card {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	padding: 8px;
	cursor: grab;
	transition: background 0.15s;
}

.pipeline-card:hover {
	background: var(--color-background-hover);
}

.pipeline-card--overdue {
	border-left: 3px solid var(--color-error);
}

/* Compact flex-row: badge → title → meta → age/date */
.pipeline-card__row {
	display: flex;
	align-items: center;
	gap: 8px;
	min-height: 24px;
}

.entity-badge {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	padding: 1px 5px;
	border-radius: 3px;
	font-size: 9px;
	font-weight: 700;
	letter-spacing: 0.5px;
	flex-shrink: 0;
	line-height: 1;
}

.badge--lead {
	background: #dbeafe;
	color: #1d4ed8;
}

.badge--request {
	background: #ffedd5;
	color: #c2410c;
}

.pipeline-card__title {
	flex: 1;
	font-size: 13px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	min-width: 0;
}

.priority-indicator {
	width: 6px;
	height: 6px;
	border-radius: 50%;
	flex-shrink: 0;
}

.priority--high {
	background: #f59e0b;
}

.priority--urgent {
	background: #ef4444;
}

.card-meta {
	font-size: 11px;
	font-weight: 600;
	color: var(--color-text-light);
	flex-shrink: 0;
	white-space: nowrap;
}

.card-assignee {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	max-width: 60px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	flex-shrink: 0;
}

.aging-badge {
	font-size: 10px;
	font-weight: 600;
	padding: 0 4px;
	border-radius: 3px;
	flex-shrink: 0;
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

.card-date {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
	white-space: nowrap;
}

.card-date--overdue {
	color: var(--color-error);
	font-weight: 600;
}

/* Quick actions row */
.pipeline-card__actions {
	display: flex;
	gap: 4px;
	margin-top: 6px;
}

.quick-select {
	flex: 1;
	min-width: 0;
}

.quick-select :deep(.vs__dropdown-toggle) {
	min-height: 24px;
	font-size: 11px;
	border-color: transparent;
	background: transparent;
}

.quick-select :deep(.vs__dropdown-toggle:hover) {
	border-color: var(--color-border);
}

.quick-select :deep(.vs__search) {
	font-size: 11px;
}

.quick-select :deep(.vs__selected) {
	font-size: 11px;
}
</style>
