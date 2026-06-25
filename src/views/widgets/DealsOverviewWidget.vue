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
import { generateUrl } from '@nextcloud/router'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import { initializeStores } from '../../store/store.js'
import { formatCurrency } from '../../services/localeUtils.js'
import { toText } from '../../utils/widgetText.js'

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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-28
		 */
		clientMap() {
			const map = {}
			for (const c of this.clients) {
				map[c.id] = c
			}
			return map
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-31
		 */
		items() {
			return this.leads.map((lead) => {
				const client = this.clientMap[lead.client] || this.clientMap[lead.clientId]
				const clientName = client ? (toText(client.name) || toText(client.title)) : ''
				const value = lead.value ? formatCurrency(lead.value) : ''
				const subParts = [clientName, value, toText(lead.stage)].filter(Boolean)

				return {
					id: lead.id,
					mainText: toText(lead.title) || t('pipelinq', 'Untitled lead'),
					subText: subParts.join(' · '),
				}
			})
		},
	},
	async mounted() {
		await this.fetchData()
	},
	methods: {
		/**
		 * @param item
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-32
		 */
		onShow(item) {
			window.location.href = generateUrl('/apps/pipelinq/leads/' + item.id)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-29
		 */
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
		/**
		 * @param config
		 * @param type
		 * @param params
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-30
		 */
		async fetchRaw(config, type, params = {}) {
			const typeConfig = config[type]
			if (!typeConfig) return []

			const queryParams = new URLSearchParams()
			for (const [key, value] of Object.entries(params)) {
				if (value === undefined || value === null || value === '') continue
				queryParams.set(key, value)
			}

			const url = generateUrl('/apps/openregister/api/objects/' + typeConfig.register + '/' + typeConfig.schema
				+ (queryParams.toString() ? '?' + queryParams.toString() : ''))

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
