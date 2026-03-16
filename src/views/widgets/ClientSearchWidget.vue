<template>
	<NcDashboardWidget :items="filteredItems"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #default>
			<div class="client-search-input">
				<input
					v-model="searchQuery"
					type="text"
					:placeholder="t('pipelinq', 'Zoek klanten...')"
					class="client-search-field"
					@input="onSearch">
			</div>
		</template>
		<template #empty-content>
			<NcEmptyContent :title="emptyTitle">
				<template #icon>
					<AccountSearch />
				</template>
			</NcEmptyContent>
		</template>
	</NcDashboardWidget>
</template>

<script>
import { NcDashboardWidget, NcEmptyContent } from '@nextcloud/vue'
import AccountSearch from 'vue-material-design-icons/AccountSearch.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'ClientSearchWidget',
	components: {
		NcDashboardWidget,
		NcEmptyContent,
		AccountSearch,
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
			clients: [],
			searchQuery: '',
			itemMenu: {
				show: {
					text: t('pipelinq', 'View client'),
					icon: 'icon-confirm',
				},
			},
		}
	},
	computed: {
		emptyTitle() {
			if (this.searchQuery) {
				return t('pipelinq', 'Geen klanten gevonden voor "{query}"', { query: this.searchQuery })
			}
			return t('pipelinq', 'Geen klanten gevonden')
		},
		allItems() {
			return this.clients.map((client) => ({
				id: client.id,
				mainText: client.name || client.title || t('pipelinq', 'Unnamed client'),
				subText: [client.email, client.phone, client.city].filter(Boolean).join(' · '),
			}))
		},
		filteredItems() {
			if (!this.searchQuery) return this.allItems
			const query = this.searchQuery.toLowerCase()
			return this.allItems.filter((item) => {
				return item.mainText.toLowerCase().includes(query)
					|| item.subText.toLowerCase().includes(query)
			})
		},
	},
	async mounted() {
		await this.fetchData()
	},
	methods: {
		onShow(item) {
			window.location.href = '/index.php/apps/pipelinq/clients/' + item.id
		},
		onSearch() {
			// Filtering is done reactively via computed property
		},
		async fetchData() {
			this.loading = true
			try {
				const { objectStore } = await initializeStores()
				const config = objectStore.objectTypeRegistry

				if (config.client) {
					this.clients = await this.fetchRaw(config, 'client', { _limit: 200 })
				}
			} catch (err) {
				console.error('ClientSearchWidget fetch error:', err)
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

<style scoped>
.client-search-input {
	padding: 8px 16px;
}

.client-search-field {
	width: 100%;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	font-size: 14px;
}

.client-search-field:focus {
	border-color: var(--color-primary-element);
	outline: none;
}
</style>
