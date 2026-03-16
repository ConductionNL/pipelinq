<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('pipelinq', 'Geen leads aan u toegewezen')">
				<template #icon>
					<AccountCheck />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import AccountCheck from 'vue-material-design-icons/AccountCheck.vue'
import { initializeStores } from '../../store/store.js'

const PRIORITY_LABELS = { low: 'Laag', normal: 'Normaal', high: 'Hoog', urgent: 'Urgent' }

export default {
	name: 'MyLeadsWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
		AccountCheck,
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
			leads: [],
			itemMenu: {
				show: {
					text: t('pipelinq', 'View lead'),
					icon: 'icon-confirm',
				},
			},
		}
	},
	computed: {
		items() {
			const now = new Date()
			return this.leads.map((lead) => {
				const isOverdue = lead.expectedCloseDate && new Date(lead.expectedCloseDate) < now
				const priorityLabel = PRIORITY_LABELS[lead.priority] || ''
				const dueStr = lead.expectedCloseDate
					? new Date(lead.expectedCloseDate).toLocaleDateString('nl-NL', { month: 'short', day: 'numeric' })
					: ''
				const subParts = [
					priorityLabel,
					lead.stage,
					dueStr ? (isOverdue ? '⚠ ' + dueStr : dueStr) : '',
				].filter(Boolean)

				return {
					id: lead.id,
					mainText: lead.title || t('pipelinq', 'Untitled lead'),
					subText: subParts.join(' · '),
				}
			})
		},
	},
	async mounted() {
		await this.fetchData()
	},
	methods: {
		onShow(item) {
			window.location.href = '/index.php/apps/pipelinq/leads/' + item.id
		},
		async fetchData() {
			this.loading = true
			try {
				const { objectStore } = await initializeStores()
				const config = objectStore.objectTypeRegistry

				if (config.lead && OC.currentUser) {
					this.leads = await this.fetchRaw(config, 'lead', {
						assignee: OC.currentUser,
						_limit: 20,
					})
				}
			} catch (err) {
				console.error('MyLeadsWidget fetch error:', err)
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
