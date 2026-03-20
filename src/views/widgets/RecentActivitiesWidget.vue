<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('pipelinq', 'No recent activities')">
				<template #icon>
					<ClockOutline />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import { initializeStores } from '../../store/store.js'
import { formatDate } from '../../services/localeUtils.js'

export default {
	name: 'RecentActivitiesWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
		ClockOutline,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loading: false,
			activities: [],
			itemMenu: {
				show: {
					text: t('pipelinq', 'View'),
					icon: 'icon-confirm',
				},
			},
		}
	},
	computed: {
		items() {
			return this.activities.map((activity) => {
				const typeLabel = activity.entityType === 'lead' ? 'Lead' : 'Request'
				const timeAgo = this.formatTimeAgo(activity.modified)

				return {
					id: activity.entityType + '-' + activity.id,
					mainText: activity.title || t('pipelinq', 'Untitled'),
					subText: typeLabel + ' · ' + timeAgo,
					_entityType: activity.entityType,
					_entityId: activity.id,
				}
			})
		},
	},
	async mounted() {
		await this.fetchData()
	},
	methods: {
		onShow(item) {
			const type = item._entityType === 'lead' ? 'leads' : 'requests'
			window.location.href = '/index.php/apps/pipelinq/' + type + '/' + item._entityId
		},
		formatTimeAgo(dateStr) {
			if (!dateStr) return ''
			try {
				const date = new Date(dateStr)
				const now = new Date()
				const diffMs = now - date
				const diffMinutes = Math.floor(diffMs / 60000)
				const diffHours = Math.floor(diffMinutes / 60)
				const diffDays = Math.floor(diffHours / 24)

				if (diffMinutes < 1) return t('pipelinq', 'just now')
				if (diffMinutes < 60) return t('pipelinq', '{minutes}m ago', { minutes: diffMinutes })
				if (diffHours < 24) return t('pipelinq', '{hours}h ago', { hours: diffHours })
				if (diffDays < 7) return t('pipelinq', '{days}d ago', { days: diffDays })
				return formatDate(dateStr)
			} catch {
				return dateStr
			}
		},
		async fetchData() {
			this.loading = true
			try {
				const { objectStore } = await initializeStores()
				const config = objectStore.objectTypeRegistry

				const promises = []

				if (config.lead) {
					promises.push(
						this.fetchRaw(config, 'lead', { _limit: 10, _order: 'updated:desc' })
							.then(items => items.map(item => ({
								...item,
								entityType: 'lead',
								modified: item.updated || item.dateModified || item.created,
							}))),
					)
				}

				if (config.request) {
					promises.push(
						this.fetchRaw(config, 'request', { _limit: 10, _order: 'updated:desc' })
							.then(items => items.map(item => ({
								...item,
								entityType: 'request',
								modified: item.updated || item.dateModified || item.created,
							}))),
					)
				}

				const results = await Promise.all(promises)
				const combined = results.flat()

				// Sort by modified date descending
				combined.sort((a, b) => {
					const dateA = a.modified ? new Date(a.modified) : new Date(0)
					const dateB = b.modified ? new Date(b.modified) : new Date(0)
					return dateB - dateA
				})

				this.activities = combined.slice(0, 15)
			} catch (err) {
				console.error('RecentActivitiesWidget fetch error:', err)
			} finally {
				this.loading = false
			}
		},
		async fetchRaw(config, type, params = {}) {
			const typeConfig = config[type]
			if (!typeConfig) return []

			const queryParams = new URLSearchParams()
			for (const [key, value] of Object.entries(params)) {
				if (value === undefined || value === null || value === '') continue
				queryParams.set(key, value)
			}

			const url = '/apps/openregister/api/objects/' + typeConfig.register + '/' + typeConfig.schema
				+ (queryParams.toString() ? '?' + queryParams.toString() : '')

			const response = await fetch(url, {
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
					'OCS-APIREQUEST': 'true',
				},
			})

			if (!response.ok) throw new Error('Failed to fetch ' + type)
			const data = await response.json()
			return data.results || data || []
		},
	},
}
</script>
