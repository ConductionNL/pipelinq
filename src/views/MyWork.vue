<template>
	<div class="my-work">
		<!-- Header -->
		<div class="my-work__header">
			<div class="my-work__title-row">
				<h2>{{ t('pipelinq', 'My Work') }}</h2>
				<span v-if="totalCount > 0" class="my-work__counts">
					{{ t('pipelinq', 'Leads') }} ({{ leadCount }}) · {{ t('pipelinq', 'Requests') }} ({{ requestCount }}) — {{ totalCount }} {{ t('pipelinq', 'items total') }}
				</span>
			</div>
			<div class="my-work__controls">
				<div class="filter-buttons">
					<NcButton
						:type="filter === 'all' ? 'primary' : 'secondary'"
						@click="filter = 'all'">
						{{ t('pipelinq', 'All') }}
					</NcButton>
					<NcButton
						:type="filter === 'lead' ? 'primary' : 'secondary'"
						@click="filter = 'lead'">
						{{ t('pipelinq', 'Leads') }}
					</NcButton>
					<NcButton
						:type="filter === 'request' ? 'primary' : 'secondary'"
						@click="filter = 'request'">
						{{ t('pipelinq', 'Requests') }}
					</NcButton>
				</div>
				<label class="show-completed-toggle">
					<input v-model="showCompleted" type="checkbox">
					{{ t('pipelinq', 'Show completed') }}
				</label>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="error" class="my-work__error">
			<p>{{ error }}</p>
			<NcButton @click="fetchAll">{{ t('pipelinq', 'Retry') }}</NcButton>
		</div>

		<div v-else-if="filteredItems.length === 0" class="my-work__empty">
			<p>{{ emptyMessage }}</p>
		</div>

		<div v-else class="my-work__groups">
			<div
				v-for="group in visibleGroups"
				:key="group.key"
				class="work-group">
				<div class="work-group__header" :class="'work-group__header--' + group.key">
					{{ group.label }}
					<span class="group-count" :class="{ 'group-count--overdue': group.key === 'overdue' }">
						{{ group.items.length }}
					</span>
				</div>
				<div class="work-group__items">
					<div
						v-for="item in group.items"
						:key="item.id"
						class="work-card"
						:class="{ 'work-card--overdue': item.isOverdue, 'work-card--completed': item.isClosed }"
						tabindex="0"
						@click="openItem(item)"
						@keydown.enter="openItem(item)">
						<div class="work-card__top">
							<span class="entity-badge" :class="'badge--' + item.entityType">
								{{ item.entityType === 'lead' ? 'LEAD' : 'REQ' }}
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
							<span v-if="item.stageOrStatus" class="meta-stage">{{ item.stageOrStatus }}</span>
							<span v-if="item.pipelineName" class="meta-pipeline">{{ item.pipelineName }}</span>
							<span v-if="item.entityType === 'lead' && item.value" class="meta-value">
								EUR {{ Number(item.value).toLocaleString('nl-NL') }}
							</span>
						</div>
						<div class="work-card__footer">
							<span v-if="item.isOverdue" class="overdue-text">
								{{ item.overdueDays }} {{ item.overdueDays === 1 ? t('pipelinq', 'day overdue') : t('pipelinq', 'days overdue') }}
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
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useObjectStore } from '../store/modules/object.js'
import {
	getStatusLabel,
	getPriorityLabel,
	getPriorityColor,
} from '../services/requestStatus.js'
import { getDaysAge, isStale } from '../services/pipelineUtils.js'

const PRIORITY_ORDER = { urgent: 0, high: 1, normal: 2, low: 3 }

function startOfToday() {
	const d = new Date()
	d.setHours(0, 0, 0, 0)
	return d
}

function endOfWeek() {
	const d = startOfToday()
	const day = d.getDay()
	const daysUntilSunday = day === 0 ? 0 : 7 - day
	d.setDate(d.getDate() + daysUntilSunday)
	d.setHours(23, 59, 59, 999)
	return d
}

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
			pipelines: [],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		currentUser() {
			return OC.currentUser
		},

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

		pipelineMap() {
			const map = {}
			for (const p of this.pipelines) {
				map[p.id] = p.title
			}
			return map
		},

		allItems() {
			const now = startOfToday()
			const weekEnd = endOfWeek()
			const thirtyDaysAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000)
			const items = []

			for (const l of this.myLeads) {
				const isClosed = this.closedStageNames.has(l.stage)
				if (!this.showCompleted && isClosed) continue

				const due = l.expectedCloseDate ? new Date(l.expectedCloseDate) : null
				const isOverdue = due ? due < now : false
				const isDueToday = due ? (due >= now && due < new Date(now.getTime() + 24 * 60 * 60 * 1000)) : false

				items.push({
					id: l.id,
					entityType: 'lead',
					title: l.title || '-',
					stageOrStatus: l.stage || '-',
					pipelineName: l.pipeline ? (this.pipelineMap[l.pipeline] || '') : '',
					priority: l.priority || 'normal',
					value: l.value,
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
				const isTerminal = ['completed', 'rejected', 'converted'].includes(r.status)
				if (!this.showCompleted && isTerminal) continue

				const due = r.requestedAt ? new Date(r.requestedAt) : null
				const isOverdue = !isTerminal && due ? due < thirtyDaysAgo : false
				const overdueDays = isOverdue ? daysBetween(due, now) : 0

				items.push({
					id: r.id,
					entityType: 'request',
					title: r.title || '-',
					stageOrStatus: getStatusLabel(r.status),
					pipelineName: r.pipeline ? (this.pipelineMap[r.pipeline] || '') : '',
					priority: r.priority || 'normal',
					value: null,
					dueDate: r.requestedAt,
					isOverdue,
					isDueToday: false,
					overdueDays,
					isClosed: isTerminal,
					isStale: false,
					_dueMs: due ? due.getTime() : Infinity,
					_group: isOverdue ? 'overdue' : 'no-due-date',
				})
			}

			return items
		},

		filteredItems() {
			if (this.filter === 'all') return this.allItems
			return this.allItems.filter(i => i.entityType === this.filter)
		},

		leadCount() {
			return this.filteredItems.filter(i => i.entityType === 'lead').length
		},
		requestCount() {
			return this.filteredItems.filter(i => i.entityType === 'request').length
		},
		totalCount() {
			return this.filteredItems.length
		},

		groupedItems() {
			const groups = {
				overdue: [],
				'due-this-week': [],
				upcoming: [],
				'no-due-date': [],
			}

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

		visibleGroups() {
			const defs = [
				{ key: 'overdue', label: t('pipelinq', 'Overdue') },
				{ key: 'due-this-week', label: t('pipelinq', 'Due This Week') },
				{ key: 'upcoming', label: t('pipelinq', 'Upcoming') },
				{ key: 'no-due-date', label: t('pipelinq', 'No Due Date') },
			]
			return defs
				.map(d => ({ ...d, items: this.groupedItems[d.key] || [] }))
				.filter(d => d.items.length > 0)
		},

		emptyMessage() {
			if (this.filter === 'lead') return t('pipelinq', 'No leads assigned to you')
			if (this.filter === 'request') return t('pipelinq', 'No requests assigned to you')
			return t('pipelinq', 'No items assigned to you')
		},
	},
	mounted() {
		this.fetchAll()
	},
	methods: {
		getPriorityLabel,
		getPriorityColor,

		computeGroup(due, now, weekEnd, isClosed) {
			if (!due) return 'no-due-date'
			if (isClosed) return 'no-due-date'
			if (due < now) return 'overdue'
			if (due <= weekEnd) return 'due-this-week'
			return 'upcoming'
		},

		async fetchAll() {
			this.loading = true
			this.error = null

			try {
				const config = this.objectStore.objectTypeRegistry
				const promises = []

				if (config.lead && this.currentUser) {
					promises.push(
						this.fetchRaw('lead', { assignee: this.currentUser, _limit: 200 })
							.then(items => { this.myLeads = items }),
					)
				}
				if (config.request && this.currentUser) {
					promises.push(
						this.fetchRaw('request', { assignee: this.currentUser, _limit: 200 })
							.then(items => { this.myRequests = items }),
					)
				}
				if (config.pipeline) {
					promises.push(
						this.fetchRaw('pipeline', { _limit: 100 })
							.then(items => { this.pipelines = items }),
					)
				}

				await Promise.all(promises)
			} catch (err) {
				this.error = err.message || t('pipelinq', 'Failed to load work items')
				console.error('MyWork fetch error:', err)
			} finally {
				this.loading = false
			}
		},

		async fetchRaw(type, params = {}) {
			const config = this.objectStore.objectTypeRegistry[type]
			if (!config) return []

			const queryParams = new URLSearchParams()
			for (const [key, value] of Object.entries(params)) {
				if (value === undefined || value === null || value === '') continue
				queryParams.set(key, value)
			}

			const url = `/apps/openregister/api/objects/${config.register}/${config.schema}`
				+ (queryParams.toString() ? '?' + queryParams.toString() : '')

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

		formatDate(dateStr) {
			if (!dateStr) return ''
			try {
				return new Date(dateStr).toLocaleDateString('nl-NL', { month: 'short', day: 'numeric', year: 'numeric' })
			} catch {
				return dateStr
			}
		},

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
.my-work {
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
	color: var(--color-error);
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
	color: var(--color-error);
	border-bottom-color: var(--color-error);
}

.group-count {
	display: inline-block;
	min-width: 20px;
	text-align: center;
	padding: 0 6px;
	border-radius: 10px;
	font-size: 12px;
	font-weight: 700;
	background: var(--color-background-darker, rgba(0,0,0,0.07));
	color: var(--color-text-maxcontrast);
	margin-left: 6px;
}

.group-count--overdue {
	background: var(--color-error);
	color: #fff;
}

.work-group__items {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
}

/* Work card */
.work-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	cursor: pointer;
	transition: box-shadow 0.15s;
}

.work-card:hover,
.work-card:focus-visible {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	outline: none;
}

.work-card--overdue {
	border-left: 3px solid var(--color-error);
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
	color: var(--color-error);
	font-weight: 600;
}

.due-today-text {
	color: var(--color-warning);
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
	background: #fff7ed;
	color: #c2410c;
	border: 1px solid #fdba74;
	margin-left: 6px;
	vertical-align: middle;
}
</style>
