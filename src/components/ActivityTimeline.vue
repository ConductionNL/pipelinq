<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -
  - ActivityTimeline.vue
  -
  - Displays a chronological feed of CRM activity items (contactmomenten,
  - tasks, emails, calendar events) for a given entity. Embedded in
  - ClientDetail, LeadDetail and RequestDetail views.
  -
  - @spec openspec/changes/activity-timeline/tasks.md#task-4
  -->

<template>
	<div class="activity-timeline">
		<div class="activity-timeline__filters" role="toolbar" :aria-label="t('pipelinq', 'Activity type filter')">
			<NcButton
				v-for="option in filterOptions"
				:key="option.value"
				:type="activeFilter === option.value ? 'primary' : 'secondary'"
				:aria-pressed="activeFilter === option.value ? 'true' : 'false'"
				class="activity-timeline__filter-btn"
				@click="setFilter(option.value)">
				{{ option.label }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading && items.length === 0" />

		<NcEmptyContent
			v-else-if="items.length === 0 && !loading"
			:name="t('pipelinq', 'No activities yet')"
			:description="t('pipelinq', 'Activities will appear here once contactmomenten, tasks, emails or calendar entries are linked to this record.')">
			<template #icon>
				<TimelineTextOutline :size="64" />
			</template>
		</NcEmptyContent>

		<ul v-else class="activity-timeline__list">
			<li
				v-for="item in items"
				:key="item.type + ':' + item.id"
				class="activity-timeline__item"
				:class="'activity-timeline__item--' + item.type">
				<span class="activity-timeline__icon" aria-hidden="true">
					<component :is="iconFor(item)" :size="20" />
				</span>
				<div class="activity-timeline__content">
					<div class="activity-timeline__header">
						<span class="activity-timeline__title">{{ item.title || t('pipelinq', '(no title)') }}</span>
						<span class="activity-timeline__date">{{ formatDate(item.date) }}</span>
					</div>
					<div v-if="item.description" class="activity-timeline__description">
						{{ truncate(item.description) }}
					</div>
					<div class="activity-timeline__meta">
						<span class="activity-timeline__type-label">{{ typeLabel(item.type) }}</span>
						<span v-if="item.user" class="activity-timeline__user">{{ item.user }}</span>
					</div>
				</div>
			</li>
		</ul>

		<div v-if="page < pages && !loading" class="activity-timeline__load-more">
			<NcButton type="secondary" @click="loadMore">
				{{ t('pipelinq', 'Load more') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import Phone from 'vue-material-design-icons/Phone.vue'
import Email from 'vue-material-design-icons/Email.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Message from 'vue-material-design-icons/Message.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import TimelineTextOutline from 'vue-material-design-icons/TimelineTextOutline.vue'

export default {
	name: 'ActivityTimeline',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		Phone,
		Email,
		EmailOutline,
		Calendar,
		CheckCircle,
		Message,
		ClockOutline,
		TimelineTextOutline,
	},
	props: {
		entityType: {
			type: String,
			required: true,
		},
		entityId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			items: [],
			page: 1,
			pages: 1,
			total: 0,
			limit: 20,
			loading: false,
			activeFilter: 'all',
		}
	},
	computed: {
		filterOptions() {
			return [
				{ value: 'all', label: this.t('pipelinq', 'All') },
				{ value: 'contactmoment', label: this.t('pipelinq', 'Contact moments') },
				{ value: 'task', label: this.t('pipelinq', 'Tasks') },
				{ value: 'email', label: this.t('pipelinq', 'Email') },
				{ value: 'calendar', label: this.t('pipelinq', 'Calendar') },
			]
		},
	},
	watch: {
		entityId: 'reload',
		entityType: 'reload',
	},
	mounted() {
		this.reload()
	},
	methods: {
		reload() {
			this.items = []
			this.page = 1
			this.pages = 1
			this.total = 0
			this.fetchPage(1)
		},
		setFilter(value) {
			if (this.activeFilter === value) {
				return
			}
			this.activeFilter = value
			this.reload()
		},
		async fetchPage(page) {
			if (!this.entityId || !this.entityType) {
				return
			}
			this.loading = true
			try {
				const params = {
					entityType: this.entityType,
					entityId: this.entityId,
					_page: page,
					_limit: this.limit,
				}
				if (this.activeFilter !== 'all') {
					params['types[]'] = this.activeFilter
				}
				const response = await axios.get(generateUrl('/apps/pipelinq/api/timeline'), { params })
				const data = response.data || {}
				const items = Array.isArray(data.items) ? data.items : []
				if (page === 1) {
					this.items = items
				} else {
					this.items = this.items.concat(items)
				}
				this.page = data.page || page
				this.pages = data.pages || 1
				this.total = data.total || 0
			} catch (error) {
				showError(this.t('pipelinq', 'Failed to load activities'))
			} finally {
				this.loading = false
			}
		},
		loadMore() {
			if (this.page < this.pages && !this.loading) {
				this.fetchPage(this.page + 1)
			}
		},
		iconFor(item) {
			if (item.type === 'contactmoment') {
				const channel = (item.metadata && item.metadata.channel) || ''
				if (channel === 'phone') {
					return Phone
				}
				if (channel === 'email') {
					return Email
				}
				return Message
			}
			if (item.type === 'worklog') {
				return ClockOutline
			}
			if (item.type === 'task') {
				return CheckCircle
			}
			if (item.type === 'email') {
				return EmailOutline
			}
			if (item.type === 'calendar') {
				return Calendar
			}
			return Message
		},
		typeLabel(type) {
			switch (type) {
			case 'contactmoment': return this.t('pipelinq', 'Contact moment')
			case 'worklog': return this.t('pipelinq', 'Worklog')
			case 'task': return this.t('pipelinq', 'Task')
			case 'email': return this.t('pipelinq', 'Email')
			case 'calendar': return this.t('pipelinq', 'Calendar')
			default: return type
			}
		},
		truncate(text) {
			if (!text) return ''
			const max = 120
			return text.length > max ? text.substring(0, max) + '...' : text
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			const date = new Date(dateStr)
			if (isNaN(date.getTime())) return ''
			return date.toLocaleString('nl-NL', {
				day: '2-digit',
				month: '2-digit',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.activity-timeline { display: flex; flex-direction: column; gap: 12px; }

.activity-timeline__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	padding-bottom: 6px;
}

.activity-timeline__filter-btn { font-size: 0.85em; }

.activity-timeline__list {
	display: flex;
	flex-direction: column;
	gap: 6px;
	list-style: none;
	padding: 0;
	margin: 0;
}

.activity-timeline__item {
	display: flex;
	gap: 12px;
	padding: 10px 12px;
	border-radius: var(--border-radius);
	border-left: 3px solid transparent;
	background: var(--color-background-hover);
}

.activity-timeline__item--contactmoment { border-left-color: var(--color-primary-element); }

.activity-timeline__item--task { border-left-color: var(--color-success); }

.activity-timeline__item--email { border-left-color: var(--color-warning); }

.activity-timeline__item--calendar { border-left-color: var(--color-text-maxcontrast); }

.activity-timeline__item--worklog { border-left-color: var(--color-info); }

.activity-timeline__icon {
	width: 24px;
	display: flex;
	align-items: flex-start;
	justify-content: center;
	color: var(--color-text-lighter);
}

.activity-timeline__content { flex: 1; min-width: 0; }

.activity-timeline__header {
	display: flex;
	justify-content: space-between;
	gap: 12px;
}

.activity-timeline__title {
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.activity-timeline__date {
	font-size: 0.85em;
	color: var(--color-text-lighter);
	white-space: nowrap;
}

.activity-timeline__description {
	font-size: 0.9em;
	color: var(--color-text-light);
	margin-top: 2px;
}

.activity-timeline__meta {
	display: flex;
	gap: 12px;
	font-size: 0.8em;
	color: var(--color-text-lighter);
	margin-top: 4px;
}

.activity-timeline__type-label { font-weight: 600; }

.activity-timeline__load-more {
	display: flex;
	justify-content: center;
	padding-top: 8px;
}
</style>
