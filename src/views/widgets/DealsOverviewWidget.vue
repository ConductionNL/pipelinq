<template>
	<NcDashboardWidget :items="items"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #empty-content>
			<NcEmptyContent :title="t('pipelinq', 'No leads found')">
				<template #icon>
					<TrendingUp />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import { initializeStores } from '../../store/store.js'
import { formatCurrency } from '../../services/localeUtils.js'

export default {
	name: 'DealsOverviewWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
		TrendingUp,
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
			clients: [],
			itemMenu: {
				show: {
					text: t('pipelinq', 'View lead'),
					icon: 'icon-confirm',
				},
			},
		}
	},
	computed: {
		clientMap() {
			const map = {}
			for (const c of this.clients) {
				map[c.id] = c
			}
			return map
		},
		items() {
			return this.leads.map((lead) => {
				const client = this.clientMap[lead.client] || this.clientMap[lead.clientId]
				const clientName = client ? (client.name || client.title || '') : ''
				const value = lead.value ? formatCurrency(lead.value) : ''
				const subParts = [clientName, value, lead.stage].filter(Boolean)

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

				if (config.lead) {
					this.leads = await this.fetchRaw(config, 'lead', { _limit: 20, _order: 'created_at:desc' })
				}
				if (config.client) {
					this.clients = await this.fetchRaw(config, 'client', { _limit: 500 })
				}
			} catch (err) {
				console.error('DealsOverviewWidget fetch error:', err)
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
