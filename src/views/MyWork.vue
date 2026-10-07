<template>
	<div class="my-work">
		<!-- Header -->
		<div class="my-work__header">
			<div class="my-work__title-row">
				<h2>{{ t('pipelinq', 'My Work') }}</h2>
				<span v-if="totalCount > 0" class="my-work__counts">
					{{ t('pipelinq', 'Leads') }} ({{ leadCount }}) ·
					{{ t('pipelinq', 'Tickets') }} ({{ requestCount }}) ·
					{{ totalCount }} {{ t('pipelinq', 'items total') }}
				</span>
			</div>
			<div class="my-work__controls">
				<div class="filter-buttons">
					<NcButton
						:variant="filter === 'all' ? 'primary' : 'secondary'"
						@click="filter = 'all'">
						{{ t('pipelinq', 'All') }}
					</NcButton>
					<NcButton
						:variant="filter === 'lead' ? 'primary' : 'secondary'"
						@click="filter = 'lead'">
						{{ t('pipelinq', 'Leads') }}
					</NcButton>
					<NcButton
						:variant="filter === 'request' ? 'primary' : 'secondary'"
						@click="filter = 'request'">
						{{ t('pipelinq', 'Tickets') }}
					</NcButton>
					<NcButton
						:variant="filter === 'task' ? 'primary' : 'secondary'"
						@click="filter = 'task'">
						{{ t('pipelinq', 'Follow-ups') }}
					</NcButton>
				</div>
				<label class="show-completed-toggle">
					<input v-model="showCompleted" type="checkbox" />
					{{ t('pipelinq', 'Show completed') }}
				</label>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="error" class="my-work__error">
			<p>{{ error }}</p>
			<NcButton @click="fetchAll">
				{{ t('pipelinq', 'Retry') }}
			</NcButton>
		</div>

		<div v-else-if="filteredItems.length === 0" class="my-work__empty">
			<p>{{ emptyMessage }}</p>
		</div>

		<div v-else class="my-work__groups">
			<div v-for="group in visibleGroups" :key="group.key" class="work-group">
				<div
					class="work-group__header"
					:class="'work-group__header--' + group.key">
					{{ group.label }}
					<span
						class="group-count"
						:class="{ 'group-count--overdue': group.key === 'overdue' }">
						{{ group.items.length }}
					</span>
				</div>
				<div class="work-group__items">
					<router-link
						v-for="item in group.items"
						:key="item.id"
						:to="itemRoute(item)"
						class="work-card"
						:class="{
							'work-card--overdue': item.isOverdue,
							'work-card--completed': item.isClosed,
						}">
						<div class="work-card__top">
							<span
								class="entity-badge"
								:class="'badge--' + item.entityType">
								{{ badgeText(item.entityType, item.ticketType) }}
							</span>
							<span
								v-if="item.priority && item.priority !== 'normal'"
								class="priority-badge"
								:style="{ color: getPriorityColor(item.priority) }">
								{{ getPriorityLabel(item.priority) }}
							</span>
						</div>
						<div class="work-card__title">
							{{ item.title }}
							<span v-if="item.isStale" class="stale-badge">
								{{ t('pipelinq', 'Stale') }}
							</span>
						</div>
						<div class="work-card__meta">
							<span v-if="item.stageOrStatus" class="meta-stage">{{
								item.stageOrStatus
							}}</span>
							<span v-if="item.pipelineName" class="meta-pipeline">{{
								item.pipelineName
							}}</span>
							<span
								v-if="item.entityType === 'lead' && item.value"
								class="meta-value">
								{{ formatCurrency(item.value, currencyOr(item.currency)) }}
							</span>
						</div>
						<div class="work-card__footer">
							<span v-if="item.isOverdue" class="overdue-text">
								{{ item.overdueDays }}
								{{
									item.overdueDays === 1
										? t('pipelinq', 'day overdue')
										: t('pipelinq', 'days overdue')
								}}
							</span>
							<span v-else-if="item.isDueToday" class="due-today-text">
								{{ t('pipelinq', 'Due today') }}
							</span>
							<span v-else-if="item.dueDate" class="due-date-text">
								{{ formatDate(item.dueDate) }}
							</span>
							<span v-else class="no-due-text">
								{{ t('pipelinq', 'No due date') }}
							</span>
						</div>
					</router-link>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { formatCurrency, formatDateFull } from '../services/localeUtils.js'
import { GROUP_ORDER, workGroup } from '../services/myWorkGroups.js'
import { isStale } from '../services/pipelineUtils.js'
import { currencyOr } from '../services/reportingCurrency.js'
import {
	getPriorityColor,
	getPriorityLabel,
	getStatusLabel,
} from '../services/requestStatus.js'
import { useObjectStore } from '../store/modules/object.js'

const PRIORITY_ORDER = { urgent: 0, high: 1, normal: 2, low: 3 }

// The closed half of the unified ticket lifecycle, the same list the Queue
// page excludes. A closed ticket of any type is done, not work.
const TERMINAL_TICKET_STATUSES = [
	'resolved',
	'completed',
	'rejected',
	'converted',
	'closed',
]

/**
 *
 */
function startOfToday() {
	const d = new Date()
	d.setHours(0, 0, 0, 0)
	return d
}

/**
 *
 */
function endOfWeek() {
	const d = startOfToday()
	const day = d.getDay()
	const daysUntilSunday = day === 0 ? 0 : 7 - day
	d.setDate(d.getDate() + daysUntilSunday)
	d.setHours(23, 59, 59, 999)
	return d
}

/**
 * Whole days from `date1` to `date2` (negative when date2 is earlier).
 *
 * @param {Date} date1 The earlier date.
 * @param {Date} date2 The later date.
 * @return {number} Whole days elapsed.
 */
function daysBetween(date1, date2) {
	const diff = date2.getTime() - date1.getTime()
	return Math.floor(diff / (1000 * 60 * 60 * 24))
}

export default {
	name: 'MyWork',
	components: {
		NcButton,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: false,
			error: null,
			filter: 'all',
			showCompleted: false,
			myLeads: [],
			myRequests: [],
			myTasks: [],
			pipelines: [],
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-12
		 */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-4
		 */
		currentUser() {
			return window.OC?.getCurrentUser?.()?.uid
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-2
		 */
		closedStageNames() {
			const names = new Set()
			for (const p of this.pipelines) {
				if (p.stages) {
					for (const s of p.stages) {
						if (s.isClosed) names.add(s.name)
					}
				}
			}
			return names
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-14
		 */
		pipelineMap() {
			const map = {}
			for (const p of this.pipelines) {
				map[p.id] = p.title
			}
			return map
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-1
		 */
		allItems() {
			const now = startOfToday()
			const weekEnd = endOfWeek()
			const thirtyDaysAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000)
			const items = []

			for (const l of this.myLeads) {
				const isClosed = this.closedStageNames.has(l.stage)
				if (!this.showCompleted && isClosed) continue

				const due = l.expectedCloseDate
					? new Date(l.expectedCloseDate)
					: null
				const isOverdue = due ? due < now : false
				const isDueToday = due
					? due >= now
						&& due < new Date(now.getTime() + 24 * 60 * 60 * 1000)
					: false

				items.push({
					id: l.id,
					entityType: 'lead',
					title: l.title || '-',
					stageOrStatus: l.stage || '-',
					pipelineName: l.pipeline
						? this.pipelineMap[l.pipeline] || ''
						: '',
					priority: l.priority || 'normal',
					value: l.value,
					currency: l.currency || null,
					dueDate: l.expectedCloseDate,
					isOverdue,
					isDueToday,
					overdueDays: isOverdue ? daysBetween(due, now) : 0,
					isClosed,
					isStale: isStale(l, 'lead'),
					_dueMs: due ? due.getTime() : Infinity,
					_group: this.computeGroup(due, now, weekEnd, isClosed),
				})
			}

			for (const r of this.myRequests) {
				const isTerminal = TERMINAL_TICKET_STATUSES.includes(r.status)
				if (!this.showCompleted && isTerminal) continue

				const due = r.occurredAt ? new Date(r.occurredAt) : null
				const isOverdue = !isTerminal && due ? due < thirtyDaysAgo : false
				const overdueDays = isOverdue ? daysBetween(due, now) : 0

				items.push({
					id: r.id,
					entityType: 'request',
					ticketType: r.ticketType || 'request',
					title: r.title || '-',
					stageOrStatus: getStatusLabel(r.status),
					pipelineName: r.pipeline
						? this.pipelineMap[r.pipeline] || ''
						: '',
					priority: r.priority || 'normal',
					value: null,
					dueDate: r.occurredAt,
					isOverdue,
					isDueToday: false,
					overdueDays,
					isClosed: isTerminal,
					isStale: false,
					_dueMs: due ? due.getTime() : Infinity,
					_group: isOverdue ? 'overdue' : 'no-due-date',
				})
			}

			// Follow-up tasks and callbacks assigned to me (crmTask), so the
			// day's calls and follow-ups sit in the same list as the deals.
			for (const task of this.myTasks) {
				const isDone = ['completed', 'expired'].includes(task.status)
				if (!this.showCompleted && isDone) continue

				const due = task.deadline ? new Date(task.deadline) : null
				const group = workGroup(due, now, weekEnd, isDone)

				items.push({
					id: task.id,
					entityType: 'task',
					title: task.subject || '-',
					stageOrStatus: task.status || '',
					pipelineName: '',
					priority: task.priority || 'normal',
					value: null,
					dueDate: task.deadline,
					isOverdue: group === 'overdue',
					isDueToday: group === 'due-today',
					overdueDays: group === 'overdue' ? daysBetween(due, now) : 0,
					isClosed: isDone,
					isStale: false,
					_dueMs: due ? due.getTime() : Infinity,
					_group: group,
				})
			}

			return items
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-8
		 */
		filteredItems() {
			if (this.filter === 'all') return this.allItems
			return this.allItems.filter((i) => i.entityType === this.filter)
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-11
		 */
		leadCount() {
			return this.filteredItems.filter((i) => i.entityType === 'lead').length
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-15
		 */
		requestCount() {
			return this.filteredItems.filter((i) => i.entityType === 'request')
				.length
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-16
		 */
		totalCount() {
			return this.filteredItems.length
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-10
		 */
		groupedItems() {
			const groups = Object.fromEntries(GROUP_ORDER.map((key) => [key, []]))

			for (const item of this.filteredItems) {
				const g = groups[item._group]
				if (g) g.push(item)
			}

			// Sort within each group
			for (const key of Object.keys(groups)) {
				groups[key].sort((a, b) => {
					const pa = PRIORITY_ORDER[a.priority] ?? 2
					const pb = PRIORITY_ORDER[b.priority] ?? 2
					if (pa !== pb) return pa - pb
					return a._dueMs - b._dueMs
				})
			}

			return groups
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-17
		 */
		visibleGroups() {
			const defs = [
				{ key: 'due-today', label: t('pipelinq', 'Today') },
				{ key: 'overdue', label: t('pipelinq', 'Overdue') },
				{ key: 'due-this-week', label: t('pipelinq', 'Due This Week') },
				{ key: 'upcoming', label: t('pipelinq', 'Upcoming') },
				{ key: 'no-due-date', label: t('pipelinq', 'No Due Date') },
			]
			return defs
				.map((d) => ({ ...d, items: this.groupedItems[d.key] || [] }))
				.filter((d) => d.items.length > 0)
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-5
		 */
		emptyMessage() {
			if (this.filter === 'lead')
				return t('pipelinq', 'No leads assigned to you')
			if (this.filter === 'request')
				return t('pipelinq', 'No tickets assigned to you')
			if (this.filter === 'task')
				return t('pipelinq', 'No follow-ups assigned to you')
			return t('pipelinq', 'No items assigned to you')
		},
	},

	mounted() {
		this.fetchAll()
	},

	methods: {
		formatCurrency,
		currencyOr,
		getPriorityLabel,
		getPriorityColor,

		/**
		 * Bucket an item into the My Work grouping by its due date.
		 *
		 * @param {string|null} due The item's due date, or null when it has none.
		 * @param {Date} now The current instant.
		 * @param {Date} weekEnd End of the current week, the "this week" boundary.
		 * @param {boolean} isClosed Whether the item is already closed; a closed
		 *   item never lands in an overdue bucket.
		 * @return {string} The group key.
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-3
		 */
		computeGroup(due, now, weekEnd, isClosed) {
			return workGroup(due, now, weekEnd, isClosed)
		},

		/**
		 * The short type badge on a work card.
		 *
		 * @param {string} entityType lead, request (any ticket) or task.
		 * @param {string} [ticketType] The ticket type, for a ticket.
		 * @return {string}
		 * @spec openspec/specs/mobile-experience/spec.md#requirement-my-work-works-as-a-phone-list-req-mob-002
		 */
		badgeText(entityType, ticketType) {
			if (entityType === 'lead') return 'LEAD'
			if (entityType === 'task') return t('pipelinq', 'TASK')
			if (ticketType === 'complaint') return t('pipelinq', 'COMPLAINT')
			if (ticketType === 'interaction') return t('pipelinq', 'CONTACT')
			return 'REQ'
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-6
		 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/my-work/spec.md
		 */
		async fetchAll() {
			this.loading = true
			this.error = null

			try {
				const config = this.objectStore.objectTypeRegistry
				const promises = []

				if (config.lead && this.currentUser) {
					promises.push(
						this.fetchRaw('lead', {
							assignee: this.currentUser,
							_limit: 200,
						}).then((items) => {
							this.myLeads = items
						}),
					)
				}
				// Every ticket assigned to me, whatever its type: an assigned
				// complaint or contact moment is my work too (pipelinq review G1).
				// Held in local state, never read from the shared
				// collections.ticket bucket.
				if (config.ticket && this.currentUser) {
					promises.push(
						this.fetchRaw('ticket', {
							assignee: this.currentUser,
							_limit: 200,
						}).then((items) => {
							this.myRequests = items
						}),
					)
				}
				if (config.crmTask && this.currentUser) {
					promises.push(
						this.fetchRaw('crmTask', {
							assigneeUserId: this.currentUser,
							_limit: 200,
						}).then((items) => {
							this.myTasks = items
						}),
					)
				}
				if (config.pipeline) {
					promises.push(
						this.fetchRaw('pipeline', { _limit: 100 }).then((items) => {
							this.pipelines = items
						}),
					)
				}

				await Promise.all(promises)
			} catch (err) {
				this.error =
					err.message || t('pipelinq', 'Failed to load work items')
				console.error('MyWork fetch error:', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Fetch a registered object type straight from OpenRegister.
		 *
		 * @param {string} type The registered object-type slug.
		 * @param {object} [params] Query parameters.
		 * @return {Promise<Array>} The matching records, or [] when the type is
		 *   not registered on this instance.
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-7
		 */
		async fetchRaw(type, params = {}) {
			const config = this.objectStore.objectTypeRegistry[type]
			if (!config) return []

			const queryParams = new URLSearchParams()
			for (const [key, value] of Object.entries(params)) {
				if (value === undefined || value === null || value === '') continue
				queryParams.set(key, value)
			}

			const url = generateUrl(
				`/apps/openregister/api/objects/${config.register}/${config.schema}`
					+ (queryParams.toString() ? '?' + queryParams.toString() : ''),
			)

			const response = await fetch(url, {
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
					'OCS-APIREQUEST': 'true',
				},
			})

			if (!response.ok) throw new Error(`Failed to fetch ${type}`)
			const data = await response.json()
			return data.results || data || []
		},

		/**
		 * Format a stored date for display, falling back to the raw value when
		 * it cannot be parsed.
		 *
		 * @param {string} dateStr The stored date.
		 * @return {string} The display string.
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-9
		 */
		formatDate(dateStr) {
			if (!dateStr) return ''
			try {
				return formatDateFull(dateStr)
			} catch {
				return dateStr
			}
		},

		/**
		 * The detail page a My Work card links to.
		 *
		 * @param {object} item The row, carrying its entityType and id.
		 * @return {object} The route location.
		 * @spec openspec/changes/reverse-2026-05-26-fe-mywork-ui/tasks.md#task-13
		 */
		itemRoute(item) {
			// Requests are `ticket` rows narrowed by ticketType
			// (unify-ticket-supertype) — every other non-lead, non-task work
			// item opens on the unified TicketDetail page, which reads its own
			// ticketType.
			let name = 'TicketDetail'
			if (item.entityType === 'lead') {
				name = 'LeadDetail'
			} else if (item.entityType === 'task') {
				name = 'TaskDetail'
			}
			return { name, params: { id: item.id } }
		},
	},
}
</script>

<style scoped>
.my-work {
	/* Entity badge palettes, one per type. */
	--my-work-lead-bg: #dbeafe;
	--my-work-lead-text: #1d4ed8;
	--my-work-lead-border: #93c5fd;
	--my-work-request-bg: #ffedd5;
	--my-work-request-text: #c2410c;
	--my-work-request-border: #fdba74;
	padding: 20px;
	max-width: 900px;
}

/* Header */
.my-work__header {
	margin-bottom: 20px;
}

.my-work__title-row {
	display: flex;
	align-items: baseline;
	gap: 12px;
	margin-bottom: 12px;
	flex-wrap: wrap;
}

.my-work__counts {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.my-work__controls {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.filter-buttons {
	display: flex;
	gap: 4px;
}

.show-completed-toggle {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 14px;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
}

/* Empty / error */
.my-work__empty,
.my-work__error {
	padding: 60px 20px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 15px;
}

.my-work__error {
	color: var(--color-text-error);
}

.my-work__error p {
	margin-bottom: 12px;
}

/* Groups */
.my-work__groups {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.work-group__header {
	font-size: 14px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	padding: 8px 0;
	border-bottom: 2px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}

.work-group__header--overdue {
	color: var(--color-text-error);
	border-bottom-color: var(--color-element-error);
}

.group-count {
	display: inline-block;
	min-width: 20px;
	text-align: center;
	padding: 0 6px;
	border-radius: 10px;
	font-size: 12px;
	font-weight: 700;
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	margin-inline-start: 6px;
}

.group-count--overdue {
	background: var(--color-error);
	color: var(--color-error-text);
}

.work-group__items {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
}

/* Work card */
.work-card {
	display: block;
	color: inherit;
	text-decoration: none;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	cursor: pointer;
	transition: box-shadow 0.15s;
}

/* Nextcloud's reset puts cursor: default on every div and span. */
.work-card * {
	cursor: pointer;
}

.work-card:hover,
.work-card:focus-visible {
	box-shadow: 0 2px 8px var(--color-box-shadow);
	outline: none;
}

.work-card--overdue {
	border-inline-start: 3px solid var(--color-element-error);
}

.work-card--completed {
	opacity: 0.6;
}

.work-card__top {
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
	background: var(--my-work-lead-bg);
	color: var(--my-work-lead-text);
	border: 1px solid var(--my-work-lead-border);
}

.badge--request {
	background: var(--my-work-request-bg);
	color: var(--my-work-request-text);
	border: 1px solid var(--my-work-request-border);
}

.badge--task {
	background: var(--color-background-dark);
	color: var(--color-main-text);
	border: 1px solid var(--color-border-dark);
}

.priority-badge {
	font-size: 11px;
	font-weight: 600;
}

.work-card__title {
	font-weight: 600;
	font-size: 14px;
	margin-bottom: 4px;
}

.work-card__meta {
	display: flex;
	gap: 8px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-wrap: wrap;
}

.meta-stage,
.meta-pipeline {
	white-space: nowrap;
}

.meta-value {
	font-weight: 600;
}

.work-card__footer {
	margin-top: 6px;
	font-size: 12px;
}

.overdue-text {
	color: var(--color-text-error);
	font-weight: 600;
}

.due-today-text {
	color: var(--color-warning-text);
	font-weight: 600;
}

.due-date-text {
	color: var(--color-text-maxcontrast);
}

.no-due-text {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.stale-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	background: var(--color-warning);
	color: var(--color-warning-text);
	margin-inline-start: 6px;
	vertical-align: middle;
}

/* Phone: one column, today's work first (the group order), 44 px targets. */
@media (max-width: 600px) {
	.my-work {
		padding: 12px;
		max-width: 100%;
		overflow-x: hidden;
	}

	.filter-buttons {
		flex-wrap: wrap;
	}

	.filter-buttons :deep(button),
	.show-completed-toggle,
	.work-card {
		min-height: 44px;
	}

	.work-card {
		padding: 12px;
	}

	.work-card__meta,
	.work-card__footer {
		flex-wrap: wrap;
	}

	.work-card__title {
		overflow-wrap: anywhere;
	}
}

@media (prefers-reduced-motion: reduce) {
	.work-card {
		transition: none;
	}
}
</style>

<style>
/* Dark palettes for the entity badges; unscoped so the body theme attribute can select them. */
body[data-theme-dark] .my-work {
	--my-work-lead-bg: rgba(59, 130, 246, 0.18);
	--my-work-lead-text: #93c5fd;
	--my-work-lead-border: #1d4ed8;
	--my-work-request-bg: rgba(249, 115, 22, 0.18);
	--my-work-request-text: #fdba74;
	--my-work-request-border: #fdba74;
}

@media (prefers-color-scheme: dark) {
	body[data-theme-default] .my-work {
		--my-work-lead-bg: rgba(59, 130, 246, 0.18);
		--my-work-lead-text: #93c5fd;
		--my-work-lead-border: #1d4ed8;
		--my-work-request-bg: rgba(249, 115, 22, 0.18);
		--my-work-request-text: #fdba74;
		--my-work-request-border: #fdba74;
	}
}
</style>
