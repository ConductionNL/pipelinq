<template>
	<NcDashboardWidget :items="filteredItems"
		:loading="loading"
		:item-menu="itemMenu"
		@show="onShow">
		<template #default>
			<div class="client-search-filters">
				<input
					v-model="searchQuery"
					type="text"
					:placeholder="t('pipelinq', 'Search clients...')"
					class="client-search-field">
				<div class="client-filter-row">
					<select v-model="filterType" class="client-filter-select">
						<option value="">{{ t('pipelinq', 'All types') }}</option>
						<option value="person">{{ t('pipelinq', 'Person') }}</option>
						<option value="organization">{{ t('pipelinq', 'Organization') }}</option>
					</select>
					<input
						v-model="filterAddress"
						type="text"
						:placeholder="t('pipelinq', 'Filter by address...')"
						class="client-filter-field">
				</div>
				<div class="client-filter-row">
					<select v-model="filterIndustry" class="client-filter-select">
						<option value="">{{ t('pipelinq', 'All industries') }}</option>
						<option v-for="industry in industries" :key="industry" :value="industry">
							{{ industry }}
						</option>
					</select>
				</div>
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
			filterType: '',
			filterAddress: '',
			filterIndustry: '',
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
			if (this.searchQuery || this.filterType || this.filterAddress || this.filterIndustry) {
				return t('pipelinq', 'No clients match your filters')
			}
			return t('pipelinq', 'No clients found')
		},
		industries() {
			const set = new Set()
			for (const client of this.clients) {
				if (client.industry) set.add(client.industry)
			}
			return [...set].sort()
		},
		allItems() {
			return this.clients.map((client) => ({
				id: client.id,
				mainText: client.name || client.title || t('pipelinq', 'Unnamed client'),
				subText: [client.type, client.address, client.industry].filter(Boolean).join(' · '),
				_type: client.type || '',
				_address: client.address || '',
				_industry: client.industry || '',
			}))
		},
		filteredItems() {
			let items = this.allItems

			if (this.filterType) {
				items = items.filter((item) => item._type === this.filterType)
			}

			if (this.filterAddress) {
				const addr = this.filterAddress.toLowerCase()
				items = items.filter((item) => item._address.toLowerCase().includes(addr))
			}

			if (this.filterIndustry) {
				items = items.filter((item) => item._industry === this.filterIndustry)
			}

			if (this.searchQuery) {
				const query = this.searchQuery.toLowerCase()
				items = items.filter((item) => {
					return item.mainText.toLowerCase().includes(query)
						|| item.subText.toLowerCase().includes(query)
				})
			}

			return items
		},
	},
	async mounted() {
		await this.fetchData()
	},
	methods: {
		onShow(item) {
			window.location.href = '/index.php/apps/pipelinq/clients/' + item.id
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
.client-search-filters {
	padding: 8px 16px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.client-search-field,
.client-filter-field,
.client-filter-select {
	width: 100%;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	font-size: 14px;
}

.client-search-field:focus,
.client-filter-field:focus,
.client-filter-select:focus {
	border-color: var(--color-primary-element);
	outline: none;
}

.client-filter-row {
	display: flex;
	gap: 6px;
}

.client-filter-row > * {
	flex: 1;
	min-width: 0;
}
</style>
