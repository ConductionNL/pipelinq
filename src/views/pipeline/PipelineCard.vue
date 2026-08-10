<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<template>
	<div
		class="pipeline-card"
		:class="{ 'pipeline-card--overdue': isOverdue }"
		draggable="true"
		role="button"
		tabindex="0"
		@dragstart="onDragStart"
		@click="$emit('open', item)"
		@keydown.enter.prevent="$emit('open', item)"
		@keydown.space.prevent="$emit('open', item)">
		<!-- Quick actions menu (top-right) — kept off the keyboard tab-order
		     for the card itself; CnRowActions handles its own focus.        -->
		<div class="pipeline-card__menu" @click.stop>
			<NcActions :force-menu="true" :inline="0" :aria-label="t('pipelinq', 'Card actions')">
				<NcActionButton @click="openMoveMenu">
					<template #icon>
						<ArrowRightThick :size="18" />
					</template>
					{{ t('pipelinq', 'Move to stage') }}
				</NcActionButton>
				<NcActionButton @click="openAssignMenu">
					<template #icon>
						<AccountPlus :size="18" />
					</template>
					{{ t('pipelinq', 'Assign') }}
				</NcActionButton>
				<NcActionButton @click="openPriorityMenu">
					<template #icon>
						<Flag :size="18" />
					</template>
					{{ t('pipelinq', 'Priority') }}
				</NcActionButton>
			</NcActions>
		</div>

		<!-- Compact single-row layout: badge → title → meta → age -->
		<div class="pipeline-card__row">
			<span class="entity-badge" :class="'badge--' + entityType">
				{{ entityType.toUpperCase().slice(0, 4) }}
			</span>
			<span class="pipeline-card__title">
				{{ item.title }}
			</span>
			<span v-if="item.priority && item.priority !== 'normal'"
				class="priority-indicator"
				:class="'priority--' + item.priority"
				:title="getPriorityLabel(item.priority)" />
			<span v-if="item.value" class="card-meta">
				{{ formatNumber(item.value) }}
			</span>
			<span v-if="item.assignee" class="card-assignee">
				{{ item.assignee }}
			</span>
			<span v-if="daysAge > 0" class="aging-badge" :class="agingClass">
				{{ agingLabel }} {{ t('pipelinq', 'in stage') }}
			</span>
			<span v-if="isStaleItem" class="stale-badge" :title="t('pipelinq', 'No activity for over {days} days', { days: staleThreshold })">
				{{ daysAge }}d {{ t('pipelinq', 'stale') }}
			</span>
			<span v-if="item.expectedCloseDate" class="card-date" :class="{ 'card-date--overdue': isOverdue }">
				<ClockAlert v-if="isOverdue" :size="14" class="card-date__icon" />
				{{ formatDate(item.expectedCloseDate) }}
			</span>
		</div>

		<!-- Sub-menus rendered as dialogs when activated; keep markup compact. -->
		<NcDialog
			v-if="moveMenuOpen"
			:name="t('pipelinq', 'Move to stage')"
			@closing="moveMenuOpen = false">
			<div class="dialog-list">
				<NcButton
					v-for="stage in stages"
					:key="stage.name"
					variant="secondary"
					class="dialog-list__item"
					@click="onPickStage(stage)">
					{{ stage.name }}
				</NcButton>
			</div>
		</NcDialog>

		<NcDialog
			v-if="assignMenuOpen"
			:name="t('pipelinq', 'Assign user')"
			@closing="assignMenuOpen = false">
			<NcSelect
				v-model="pickedAssignee"
				:options="userOptions"
				:clearable="true"
				:input-label="t('pipelinq', 'Assignee')"
				@update:model-value="onPickAssignee" />
		</NcDialog>

		<NcDialog
			v-if="priorityMenuOpen"
			:name="t('pipelinq', 'Set priority')"
			@closing="priorityMenuOpen = false">
			<div class="dialog-list">
				<NcButton
					v-for="p in priorityOptions"
					:key="p.value"
					:variant="item.priority === p.value ? 'primary' : 'secondary'"
					class="dialog-list__item"
					@click="onPickPriority(p.value)">
					{{ p.label }}
				</NcButton>
			</div>
		</NcDialog>
	</div>
</template>

<script>
import { NcSelect, NcActions, NcActionButton, NcButton, NcDialog } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ArrowRightThick from 'vue-material-design-icons/ArrowRightThick.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import Flag from 'vue-material-design-icons/Flag.vue'
import ClockAlert from 'vue-material-design-icons/ClockAlert.vue'
import { getPriorityLabel, getPriorityColor, getStatusLabel } from '../../services/requestStatus.js'
import { getDaysAge, getAgingClass, formatAge, getStaleThreshold, resolveObjectType } from '../../services/pipelineUtils.js'
import { useObjectStore } from '../../store/modules/object.js'
import { useSettingsStore } from '../../store/modules/settings.js'
// eslint-disable-next-line no-unused-vars -- used in template via Options API fallthrough
import { formatNumber, formatDate as formatLocaleDate } from '../../services/localeUtils.js'

// Module-level user cache shared across all PipelineCard instances
let usersCache = null

export default {
	name: 'PipelineCard',
	components: {
		NcSelect,
		NcActions,
		NcActionButton,
		NcButton,
		NcDialog,
		ArrowRightThick,
		AccountPlus,
		Flag,
		ClockAlert,
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
			moveMenuOpen: false,
			assignMenuOpen: false,
			priorityMenuOpen: false,
			pickedAssignee: null,
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-42
		 */
		objectStore() {
			return useObjectStore()
		},
		/**
		 * The registered OpenRegister object type behind this card's *logical*
		 * `entityType`, plus the `ticketType` discriminator when that logical type
		 * is one of the three subtypes folded into the `ticket` supertype
		 * (unify-ticket-supertype). `entityType` stays logical so the badge, the
		 * overdue rule and the board's routing keep working unchanged.
		 *
		 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-unified-tickets-workspace
		 */
		resolvedType() {
			return resolveObjectType(this.entityType)
		},
		/**
		 * Pinia settings store — holds the stale-threshold configuration
		 * surfaced through the existing settings endpoint.
		 *
		 * @spec openspec/specs/lead-management/spec.md
		 */
		settingsStore() {
			return useSettingsStore()
		},
		/**
		 * Resolved stale threshold (days) from settings; defaults to 14 if
		 * the store hasn't been initialised yet.
		 *
		 * @spec openspec/specs/lead-management/spec.md
		 */
		staleThreshold() {
			return getStaleThreshold(this.settingsStore.config)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-36
		 */
		currentColumnValue() {
			return this.item[this.columnProperty] || ''
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-40
		 * @spec openspec/specs/lead-management/spec.md
		 */
		isOverdue() {
			if (this.entityType === 'lead') {
				if (!this.item.expectedCloseDate) return false
				if (this.item.status === 'won' || this.item.status === 'lost') return false
				// Cards in stages flagged isClosed never show overdue
				const currentStage = this.stages.find(s => s.name === this.item.stage)
				if (currentStage && currentStage.isClosed) return false
				return new Date(this.item.expectedCloseDate) < new Date()
			}
			if (this.entityType === 'request') {
				// `requestedAt` became `occurredAt` on the ticket supertype.
				if (!this.item.occurredAt) return false
				const daysSince = Math.floor((Date.now() - new Date(this.item.occurredAt).getTime()) / 86400000)
				return daysSince > 30
			}
			return false
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-37
		 */
		daysAge() {
			return getDaysAge(this.item)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-34
		 */
		agingClass() {
			return getAgingClass(this.daysAge)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-35
		 */
		agingLabel() {
			return formatAge(this.daysAge)
		},
		/**
		 * Stale = no activity for `staleThreshold` days. Driven by the
		 * settings store, not a hardcoded constant.
		 *
		 * @spec openspec/specs/lead-management/spec.md
		 */
		isStaleItem() {
			if (this.entityType !== 'lead') return false
			return this.daysAge >= this.staleThreshold
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-46
		 */
		stageOptions() {
			return this.stages.map(s => s.name)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-47
		 */
		userOptions() {
			return this.users
		},
		/**
		 * Priority levels exposed in the quick action menu.
		 *
		 * @spec openspec/specs/lead-management/spec.md
		 */
		priorityOptions() {
			return [
				{ value: 'low', label: t('pipelinq', 'Low') },
				{ value: 'normal', label: t('pipelinq', 'Normal') },
				{ value: 'high', label: t('pipelinq', 'High') },
				{ value: 'urgent', label: t('pipelinq', 'Urgent') },
			]
		},
	},
	watch: {
		currentColumnValue: {
			immediate: true,
			/**
			 * @param val
			 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-39
			 */
			handler(val) {
				this.selectedStage = val || null
			},
		},
		'item.assignee': {
			immediate: true,
			/**
			 * @param val
			 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-39
			 */
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

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-41
		 */
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

		/**
		 * Copy the card's item into a save payload: drop the board-only `_` keys
		 * and re-assert the `ticketType` discriminator when this card is a ticket
		 * subtype, so a PUT can never strip it (unify-ticket-supertype).
		 *
		 * @return {object} Payload for objectStore.saveObject().
		 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
		 */
		buildSavePayload() {
			const updated = { ...this.item }
			delete updated._entityType
			delete updated._schemaSlug
			if (this.resolvedType.ticketType && !updated.ticketType) {
				updated.ticketType = this.resolvedType.ticketType
			}
			return updated
		},

		/**
		 * @param newStage
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-45
		 */
		async onStageChange(newStage) {
			if (!newStage || newStage === this.currentColumnValue) return
			try {
				const updated = this.buildSavePayload()
				updated[this.columnProperty] = newStage
				const targetStage = this.stages.find(s => s.name === newStage)
				if (targetStage && typeof targetStage.order === 'number' && this.columnProperty === 'stage') {
					updated.stageOrder = targetStage.order
				}
				await this.objectStore.saveObject(this.resolvedType.objectType, updated)
				showSuccess(t('pipelinq', 'Lead moved to {stage}', { stage: newStage }))
				this.$emit('refresh')
			} catch (e) {
				this.selectedStage = this.currentColumnValue
				showError(t('pipelinq', 'Failed to move lead. Please try again.'))
			}
		},

		/**
		 * @param newAssignee
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-43
		 */
		async onAssignChange(newAssignee) {
			if (newAssignee === this.item.assignee) return
			try {
				const updated = this.buildSavePayload()
				updated.assignee = newAssignee || ''
				await this.objectStore.saveObject(this.resolvedType.objectType, updated)
				showSuccess(t('pipelinq', 'Assignee updated'))
				this.$emit('refresh')
			} catch (e) {
				this.selectedAssignee = this.item.assignee
				showError(t('pipelinq', 'Failed to update assignee.'))
			}
		},

		/**
		 * Open the move-to-stage dialog.
		 *
		 * @spec openspec/specs/lead-management/spec.md
		 */
		openMoveMenu() {
			this.moveMenuOpen = true
		},

		/**
		 * Open the assignee picker dialog.
		 *
		 * @spec openspec/specs/lead-management/spec.md
		 */
		openAssignMenu() {
			this.pickedAssignee = this.item.assignee || null
			this.assignMenuOpen = true
		},

		/**
		 * Open the priority picker dialog.
		 *
		 * @spec openspec/specs/lead-management/spec.md
		 */
		openPriorityMenu() {
			this.priorityMenuOpen = true
		},

		/**
		 * Handle stage selection from the move dialog.
		 *
		 * @param {object} stage The selected stage.
		 * @spec openspec/specs/lead-management/spec.md
		 */
		async onPickStage(stage) {
			this.moveMenuOpen = false
			await this.onStageChange(stage.name)
		},

		/**
		 * Handle assignee selection from the assign dialog.
		 *
		 * @param {string|null} uid The selected user id.
		 * @spec openspec/specs/lead-management/spec.md
		 */
		async onPickAssignee(uid) {
			this.assignMenuOpen = false
			await this.onAssignChange(uid)
		},

		/**
		 * Handle priority selection from the priority dialog. Wraps store
		 * writes in try/catch with user-visible feedback per REQ-LM-001.
		 *
		 * @param {string} priority The chosen priority.
		 * @spec openspec/specs/lead-management/spec.md
		 */
		async onPickPriority(priority) {
			this.priorityMenuOpen = false
			if (priority === this.item.priority) return
			try {
				const updated = this.buildSavePayload()
				updated.priority = priority
				await this.objectStore.saveObject(this.resolvedType.objectType, updated)
				showSuccess(t('pipelinq', 'Priority updated'))
				this.$emit('refresh')
			} catch (e) {
				showError(t('pipelinq', 'Failed to update priority.'))
			}
		},

		/**
		 * @param e
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-44
		 */
		onDragStart(e) {
			const data = {
				id: this.item.id,
				_schemaSlug: this.entityType,
			}
			data[this.columnProperty] = this.currentColumnValue
			e.dataTransfer.setData('application/json', JSON.stringify(data))
			e.dataTransfer.effectAllowed = 'move'
		},

		/**
		 * @param dateStr
		 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-38
		 */
		formatDate(dateStr) {
			return formatLocaleDate(dateStr)
		},
	},
}
</script>

<style scoped>
.pipeline-card {
	position: relative;
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

.pipeline-card__menu {
	position: absolute;
	top: 4px;
	right: 4px;
	z-index: 2;
}

.stale-badge {
	font-size: 10px;
	font-weight: 600;
	padding: 0 5px;
	border-radius: 3px;
	flex-shrink: 0;
	background: var(--color-warning, #f59e0b);
	color: var(--color-primary-text, #fff);
}

.card-date__icon {
	margin-right: 2px;
	vertical-align: middle;
}

.dialog-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
}

.dialog-list__item {
	width: 100%;
	justify-content: flex-start;
}

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

@media (prefers-reduced-motion: reduce) {
	.pipeline-card {
		transition: none;
	}
}
</style>
