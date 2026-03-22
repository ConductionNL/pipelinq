<template>
	<div class="activity-timeline">
		<div class="activity-timeline__header">
			<h3>{{ t('pipelinq', 'Activity Timeline') }}</h3>
			<div class="activity-timeline__controls">
				<div class="activity-timeline__filter">
					<select v-model="typeFilter" class="activity-timeline__select">
						<option value="">
							{{ t('pipelinq', 'All types') }}
						</option>
						<option v-for="opt in typeOptions" :key="opt.value" :value="opt.value">
							{{ opt.label }}
						</option>
					</select>
				</div>
				<div class="activity-timeline__search">
					<input
						v-model="searchQuery"
						type="text"
						:placeholder="t('pipelinq', 'Search timeline...')"
						class="activity-timeline__search-input">
				</div>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="filteredEntries.length === 0" class="activity-timeline__empty">
			<p v-if="searchQuery">
				{{ t('pipelinq', 'No activities found for \'{query}\'', { query: searchQuery }) }}
			</p>
			<p v-else>
				{{ t('pipelinq', 'No activity yet') }}
			</p>
			<NcButton v-if="searchQuery" @click="searchQuery = ''">
				{{ t('pipelinq', 'Clear search') }}
			</NcButton>
		</div>

		<div v-else class="activity-timeline__entries">
			<template v-for="(group, index) in groupedEntries">
				<div :key="'header-' + index" class="activity-timeline__date-header">
					{{ group.label }}
				</div>
				<div
					v-for="entry in group.entries"
					:key="entry.id"
					class="activity-timeline__entry">
					<div class="activity-timeline__icon" :class="'icon--' + entry.type">
						{{ typeIcons[entry.type] || '●' }}
					</div>
					<div class="activity-timeline__content">
						<div class="activity-timeline__description">
							{{ entry.description }}
						</div>
						<div v-if="entry.content" class="activity-timeline__note-content">
							{{ entry.content }}
						</div>
						<div class="activity-timeline__meta">
							<span
								class="activity-timeline__time"
								:title="entry.fullTimestamp">
								{{ entry.relativeTime }}
							</span>
							<span v-if="entry.actor" class="activity-timeline__actor">
								{{ entry.actor }}
							</span>
							<span
								v-if="entry.sourceEntity"
								class="activity-timeline__source-badge">
								{{ entry.sourceEntity }}
							</span>
						</div>
					</div>
				</div>
			</template>

			<div v-if="hasMore" class="activity-timeline__load-more">
				<NcButton @click="loadMore">
					{{ t('pipelinq', 'Load more') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

const TYPE_ICONS = {
	note: '\u270F',
	call: '\u260E',
	email: '\u2709',
	meeting: '\uD83D\uDCC5',
	status_change: '\u2192',
	stage_change: '\u2192',
	assignment: '\uD83D\uDC64',
	document: '\uD83D\uDCC4',
	field_change: '\u21C4',
	created: '\u2795',
}

export default {
	name: 'ActivityTimeline',
	components: {
		NcButton,
		NcLoadingIcon,
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
			loading: false,
			entries: [],
			typeFilter: '',
			searchQuery: '',
			page: 1,
			limit: 20,
			totalCount: 0,
			typeIcons: TYPE_ICONS,
		}
	},
	computed: {
		typeOptions() {
			return [
				{ value: 'note', label: t('pipelinq', 'Notes') },
				{ value: 'call', label: t('pipelinq', 'Calls') },
				{ value: 'email', label: t('pipelinq', 'Emails') },
				{ value: 'meeting', label: t('pipelinq', 'Meetings') },
				{ value: 'status_change', label: t('pipelinq', 'Status changes') },
				{ value: 'assignment', label: t('pipelinq', 'Assignments') },
				{ value: 'created', label: t('pipelinq', 'Created') },
			]
		},

		filteredEntries() {
			let result = this.entries

			if (this.typeFilter) {
				result = result.filter(e => e.type === this.typeFilter)
			}

			if (this.searchQuery) {
				const query = this.searchQuery.toLowerCase()
				result = result.filter(e => {
					const desc = (e.description || '').toLowerCase()
					const content = (e.content || '').toLowerCase()
					return desc.includes(query) || content.includes(query)
				})
			}

			return result
		},

		groupedEntries() {
			const groups = []
			let currentLabel = null
			let currentGroup = null

			for (const entry of this.filteredEntries) {
				const label = this.getDateLabel(entry.timestamp)
				if (label !== currentLabel) {
					currentLabel = label
					currentGroup = { label, entries: [] }
					groups.push(currentGroup)
				}
				currentGroup.entries.push(entry)
			}

			return groups
		},

		hasMore() {
			return this.entries.length < this.totalCount
		},
	},
	watch: {
		entityId() {
			this.resetAndFetch()
		},
	},
	mounted() {
		this.fetchTimeline()
	},
	methods: {
		resetAndFetch() {
			this.entries = []
			this.page = 1
			this.totalCount = 0
			this.fetchTimeline()
		},

		async fetchTimeline() {
			this.loading = true
			try {
				const auditEntries = await this.fetchAuditTrail()
				this.entries = auditEntries
				this.totalCount = auditEntries.length
			} catch (err) {
				console.error('Failed to load activity timeline:', err)
			} finally {
				this.loading = false
			}
		},

		async fetchAuditTrail() {
			// Use the OpenRegister audit trail as the timeline source.
			// This provides all changes made to the object over time.
			const objectStore = (await import('../store/modules/object.js')).useObjectStore()
			const config = objectStore.objectTypeRegistry[this.entityType]
			if (!config) return []

			const url = `/apps/openregister/api/objects/${config.register}/${config.schema}/${this.entityId}/audit`
			try {
				const response = await fetch(url, {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})
				if (!response.ok) return []
				const data = await response.json()
				const auditItems = data.results || data || []

				return auditItems.map((item, idx) => this.mapAuditEntry(item, idx)).reverse()
			} catch {
				return []
			}
		},

		mapAuditEntry(item, index) {
			const timestamp = item.created || item.updated || new Date().toISOString()
			let type = 'field_change'
			let description = t('pipelinq', 'Record updated')

			if (index === 0 || item.version === 1) {
				type = 'created'
				description = t('pipelinq', '{entityType} created', { entityType: this.entityType })
			} else if (item.changes) {
				const changeKeys = Object.keys(item.changes)
				if (changeKeys.includes('assignee')) {
					type = 'assignment'
					description = t('pipelinq', 'Assignee changed to {value}', { value: item.changes.assignee?.new || '' })
				} else if (changeKeys.includes('stage')) {
					type = 'stage_change'
					description = t('pipelinq', 'Stage changed from {from} to {to}', {
						from: item.changes.stage?.old || '',
						to: item.changes.stage?.new || '',
					})
				} else if (changeKeys.includes('status')) {
					type = 'status_change'
					description = t('pipelinq', 'Status changed from {from} to {to}', {
						from: item.changes.status?.old || '',
						to: item.changes.status?.new || '',
					})
				} else {
					description = t('pipelinq', 'Fields updated: {fields}', { fields: changeKeys.join(', ') })
				}
			}

			return {
				id: item.id || `audit-${index}`,
				type,
				description,
				content: null,
				actor: item.userId || item.user || '',
				timestamp,
				fullTimestamp: new Date(timestamp).toISOString(),
				relativeTime: this.getRelativeTime(timestamp),
				sourceEntity: null,
			}
		},

		getDateLabel(timestamp) {
			const date = new Date(timestamp)
			const today = new Date()
			const yesterday = new Date(today)
			yesterday.setDate(yesterday.getDate() - 1)

			if (date.toDateString() === today.toDateString()) {
				return t('pipelinq', 'Today')
			}
			if (date.toDateString() === yesterday.toDateString()) {
				return t('pipelinq', 'Yesterday')
			}
			return date.toLocaleDateString('nl-NL', { day: 'numeric', month: 'long', year: 'numeric' })
		},

		getRelativeTime(timestamp) {
			const now = new Date()
			const date = new Date(timestamp)
			const diffMs = now - date
			const diffMin = Math.floor(diffMs / 60000)
			const diffHours = Math.floor(diffMs / 3600000)
			const diffDays = Math.floor(diffMs / 86400000)

			if (diffMin < 1) return t('pipelinq', 'Just now')
			if (diffMin < 60) return t('pipelinq', '{count}m ago', { count: diffMin })
			if (diffHours < 24) return t('pipelinq', '{count}h ago', { count: diffHours })
			if (diffDays < 7) return t('pipelinq', '{count}d ago', { count: diffDays })
			return date.toLocaleDateString('nl-NL', { day: 'numeric', month: 'short' })
		},

		loadMore() {
			this.page += 1
			this.fetchTimeline()
		},
	},
}
</script>

<style scoped>
.activity-timeline {
	margin-top: 16px;
}

.activity-timeline__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 12px;
	margin-bottom: 16px;
}

.activity-timeline__header h3 {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.activity-timeline__controls {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

.activity-timeline__select {
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	font-size: 13px;
}

.activity-timeline__search-input {
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 13px;
	min-width: 180px;
}

.activity-timeline__empty {
	text-align: center;
	padding: 40px 20px;
	color: var(--color-text-maxcontrast);
}

.activity-timeline__date-header {
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: var(--color-text-maxcontrast);
	padding: 12px 0 4px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 8px;
}

.activity-timeline__entry {
	display: flex;
	gap: 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border-dark, rgba(0,0,0,0.05));
}

.activity-timeline__icon {
	flex-shrink: 0;
	width: 32px;
	height: 32px;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	background: var(--color-background-dark);
	font-size: 14px;
}

.icon--note { background: #dbeafe; }
.icon--call { background: #dcfce7; }
.icon--email { background: #fef3c7; }
.icon--meeting { background: #e0e7ff; }
.icon--status_change,
.icon--stage_change { background: #fce7f3; }
.icon--assignment { background: #f3e8ff; }
.icon--document { background: #ecfdf5; }
.icon--field_change { background: #fff7ed; }
.icon--created { background: #dcfce7; }

.activity-timeline__content {
	flex: 1;
	min-width: 0;
}

.activity-timeline__description {
	font-size: 14px;
	font-weight: 500;
}

.activity-timeline__note-content {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
	white-space: pre-wrap;
}

.activity-timeline__meta {
	display: flex;
	gap: 8px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.activity-timeline__source-badge {
	padding: 1px 6px;
	border-radius: 4px;
	background: var(--color-background-dark);
	font-size: 11px;
}

.activity-timeline__load-more {
	text-align: center;
	padding: 16px;
}
</style>
