<!-- SPDX-License-Identifier: EUPL-1.2 -->

<template>
	<div class="activity-timeline">
		<!-- Filter bar -->
		<div class="activity-timeline__filter">
			<button
				:class="['filter-button', { 'filter-button--active': !selectedTypes || selectedTypes.length === 0 }]"
				@click="selectedTypes = []">
				{{ t('pipelinq', 'All') }}
			</button>
			<button
				:class="['filter-button', { 'filter-button--active': isTypeSelected('contactmoment') }]"
				@click="toggleType('contactmoment')">
				{{ t('pipelinq', 'Contact moments') }}
			</button>
			<button
				:class="['filter-button', { 'filter-button--active': isTypeSelected('task') }]"
				@click="toggleType('task')">
				{{ t('pipelinq', 'Tasks') }}
			</button>
			<button
				:class="['filter-button', { 'filter-button--active': isTypeSelected('email') }]"
				@click="toggleType('email')">
				{{ t('pipelinq', 'Email') }}
			</button>
			<button
				:class="['filter-button', { 'filter-button--active': isTypeSelected('calendar') }]"
				@click="toggleType('calendar')">
				{{ t('pipelinq', 'Calendar') }}
			</button>
		</div>

		<!-- Loading state -->
		<div v-if="loading" class="activity-timeline__loading">
			<NcLoadingIcon :size="24" />
		</div>

		<!-- Empty state -->
		<CnEmptyState
			v-else-if="items.length === 0"
			:title="t('pipelinq', 'No activities yet')"
			:description="t('pipelinq', 'There are no activities to display')" />

		<!-- Timeline items -->
		<div v-else class="activity-timeline__items">
			<div
				v-for="item in items"
				:key="item.id"
				class="activity-item">
				<div class="activity-item__icon">
					<CnIcon :icon="getIconForType(item.type)" :size="20" />
				</div>
				<div class="activity-item__content">
					<div class="activity-item__header">
						<div class="activity-item__title">{{ item.title }}</div>
						<div class="activity-item__date">{{ formatDateFromNow(item.date) }}</div>
					</div>
					<div v-if="item.description" class="activity-item__description">
						{{ truncateText(item.description, 120) }}
					</div>
					<div class="activity-item__meta">
						<span v-if="item.user" class="activity-item__user">{{ item.user }}</span>
						<span class="activity-item__type">{{ getTypeLabel(item.type) }}</span>
					</div>
				</div>
			</div>
		</div>

		<!-- Load more button -->
		<div v-if="canLoadMore && !loading" class="activity-timeline__load-more">
			<NcButton type="secondary" @click="loadMore">
				{{ t('pipelinq', 'Load more') }}
			</NcButton>
		</div>

		<!-- Error dialog -->
		<NcDialog
			v-if="error"
			:title="t('pipelinq', 'Error')"
			:show="true"
			@close="error = null">
			{{ error }}
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { NcLoadingIcon, NcButton, NcDialog } from '@nextcloud/vue'
import { CnEmptyState, CnIcon } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'ActivityTimeline',
	components: {
		NcLoadingIcon,
		NcButton,
		NcDialog,
		CnEmptyState,
		CnIcon,
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
			selectedTypes: [],
			currentPage: 1,
			totalPages: 1,
			loading: false,
			error: null,
		}
	},
	computed: {
		canLoadMore() {
			return this.currentPage < this.totalPages
		},
	},
	mounted() {
		this.fetchTimeline()
	},
	methods: {
		async fetchTimeline() {
			this.loading = true
			this.error = null

			try {
				const params = {
					entityType: this.entityType,
					entityId: this.entityId,
					_page: this.currentPage,
					_limit: 20,
				}

				// Add type filter if any types are selected
				if (this.selectedTypes.length > 0) {
					params.types = this.selectedTypes
				}

				const response = await axios.get(
					generateUrl('/apps/pipelinq/api/timeline'),
					{ params },
				)

				if (this.currentPage === 1) {
					this.items = response.data.items || []
				} else {
					this.items = this.items.concat(response.data.items || [])
				}

				this.totalPages = response.data.pages || 1
			} catch (error) {
				this.error = t('pipelinq', 'Failed to load activities')
				console.error('Failed to fetch timeline:', error)
			} finally {
				this.loading = false
			}
		},
		toggleType(type) {
			const index = this.selectedTypes.indexOf(type)
			if (index > -1) {
				this.selectedTypes.splice(index, 1)
			} else {
				this.selectedTypes.push(type)
			}
			this.currentPage = 1
			this.items = []
			this.fetchTimeline()
		},
		isTypeSelected(type) {
			return this.selectedTypes.includes(type)
		},
		loadMore() {
			this.currentPage += 1
			this.fetchTimeline()
		},
		getIconForType(type) {
			const iconMap = {
				contactmoment: 'mdiPhone',
				task: 'mdiCheckCircle',
				email: 'mdiEmailOutline',
				calendar: 'mdiCalendar',
				worklog: 'mdiClipboardClock',
			}
			return iconMap[type] || 'mdiClipboard'
		},
		getTypeLabel(type) {
			const labels = {
				contactmoment: t('pipelinq', 'Contact moment'),
				task: t('pipelinq', 'Task'),
				email: t('pipelinq', 'Email'),
				calendar: t('pipelinq', 'Calendar'),
				worklog: t('pipelinq', 'Worklog'),
			}
			return labels[type] || type
		},
		formatDateFromNow(dateStr) {
			if (!dateStr) return ''

			const date = new Date(dateStr)
			const now = new Date()
			const diffMs = now - date
			const diffMins = Math.floor(diffMs / 60000)
			const diffHours = Math.floor(diffMs / 3600000)
			const diffDays = Math.floor(diffMs / 86400000)

			if (diffMins < 1) {
				return t('pipelinq', 'just now')
			} else if (diffMins < 60) {
				return t('pipelinq', '{count} minutes ago', { count: diffMins })
			} else if (diffHours < 24) {
				return t('pipelinq', '{count} hours ago', { count: diffHours })
			} else if (diffDays < 30) {
				return t('pipelinq', '{count} days ago', { count: diffDays })
			}

			return date.toLocaleDateString('nl-NL')
		},
		truncateText(text, maxLength) {
			if (!text) return ''
			if (text.length <= maxLength) return text
			return text.substring(0, maxLength) + '...'
		},
	},
}
</script>

<style scoped>
.activity-timeline {
	width: 100%;
}

.activity-timeline__filter {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.filter-button {
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	background: transparent;
	border-radius: 4px;
	cursor: pointer;
	font-size: 13px;
	transition: all 0.2s ease;
	color: var(--color-text);
}

.filter-button:hover {
	background: var(--color-background-hover);
	border-color: var(--color-primary-element);
}

.filter-button--active {
	background: var(--color-primary-element);
	border-color: var(--color-primary-element);
	color: white;
	font-weight: 600;
}

.activity-timeline__loading {
	display: flex;
	justify-content: center;
	padding: 32px 0;
}

.activity-timeline__items {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.activity-item {
	display: flex;
	gap: 12px;
	padding: 12px;
	border-radius: 4px;
	border: 1px solid var(--color-border);
	background: var(--color-background-secondary);
	transition: all 0.2s ease;
}

.activity-item:hover {
	background: var(--color-background-hover);
	border-color: var(--color-primary-element);
}

.activity-item__icon {
	flex-shrink: 0;
	display: flex;
	align-items: flex-start;
	padding-top: 2px;
	color: var(--color-primary-element);
}

.activity-item__content {
	flex: 1;
	min-width: 0;
}

.activity-item__header {
	display: flex;
	justify-content: space-between;
	gap: 12px;
	margin-bottom: 4px;
}

.activity-item__title {
	font-weight: 600;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	flex: 1;
}

.activity-item__date {
	font-size: 12px;
	color: var(--color-text-lighter);
	white-space: nowrap;
	flex-shrink: 0;
}

.activity-item__description {
	font-size: 12px;
	color: var(--color-text);
	margin-bottom: 6px;
	overflow: hidden;
	text-overflow: ellipsis;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
}

.activity-item__meta {
	display: flex;
	gap: 8px;
	font-size: 11px;
	color: var(--color-text-lighter);
}

.activity-item__user {
	font-weight: 500;
}

.activity-item__type {
	padding: 0 4px;
	border-radius: 2px;
	background: var(--color-primary-element);
	color: white;
	font-size: 10px;
}

.activity-timeline__load-more {
	display: flex;
	justify-content: center;
	margin-top: 16px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
