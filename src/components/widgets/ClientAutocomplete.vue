<template>
	<div class="client-autocomplete">
		<NcTextField :value.sync="query"
			:label="label"
			:placeholder="placeholder"
			@input="onInput" />
		<div v-if="showDropdown && results.length > 0" class="autocomplete-dropdown">
			<button v-for="client in results"
				:key="client.id"
				class="autocomplete-item"
				@click="selectClient(client)">
				<span class="autocomplete-name">{{ client.name || t('pipelinq', 'Unnamed') }}</span>
				<span v-if="client.email" class="autocomplete-email">{{ client.email }}</span>
			</button>
		</div>
		<div v-if="selectedClient" class="selected-client">
			<span class="selected-name">{{ selectedClient.name }}</span>
			<NcButton type="tertiary"
				:aria-label="t('pipelinq', 'Clear selection')"
				@click="clearSelection">
				<template #icon>
					<Close :size="16" />
				</template>
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcButton } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'ClientAutocomplete',
	components: {
		NcTextField,
		NcButton,
		Close,
	},
	props: {
		value: {
			type: Object,
			default: null,
		},
		placeholder: {
			type: String,
			default() {
				return t('pipelinq', 'Search client...')
			},
		},
		label: {
			type: String,
			default() {
				return t('pipelinq', 'Client')
			},
		},
	},
	data() {
		return {
			query: '',
			results: [],
			showDropdown: false,
			selectedClient: this.value || null,
			config: null,
			debounceTimer: null,
		}
	},
	watch: {
		value(newVal) {
			this.selectedClient = newVal
		},
	},
	async mounted() {
		try {
			const { objectStore } = await initializeStores()
			this.config = objectStore.objectTypeRegistry
		} catch (err) {
			console.error('ClientAutocomplete: failed to load config', err)
		}
	},
	methods: {
		onInput() {
			if (this.selectedClient) {
				return
			}
			clearTimeout(this.debounceTimer)
			this.debounceTimer = setTimeout(() => {
				this.searchClients()
			}, 300)
		},
		async searchClients() {
			if (!this.config?.client || !this.query || this.query.length < 2) {
				this.results = []
				this.showDropdown = false
				return
			}

			try {
				const typeConfig = this.config.client
				const params = new URLSearchParams({
					_search: this.query,
					_limit: '10',
				})
				const url = '/apps/openregister/api/objects/'
					+ typeConfig.register + '/' + typeConfig.schema
					+ '?' + params.toString()

				const response = await fetch(url, {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})

				if (!response.ok) throw new Error('Search failed')
				const data = await response.json()
				this.results = data.results || data || []
				this.showDropdown = this.results.length > 0
			} catch (err) {
				console.error('ClientAutocomplete search error:', err)
				this.results = []
				this.showDropdown = false
			}
		},
		selectClient(client) {
			this.selectedClient = client
			this.query = ''
			this.results = []
			this.showDropdown = false
			this.$emit('input', client)
		},
		clearSelection() {
			this.selectedClient = null
			this.query = ''
			this.$emit('input', null)
		},
	},
}
</script>

<style scoped>
.client-autocomplete {
	position: relative;
}

.autocomplete-dropdown {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	box-shadow: 0 2px 8px var(--color-box-shadow);
	z-index: 100;
	max-height: 200px;
	overflow-y: auto;
}

.autocomplete-item {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	width: 100%;
	padding: 8px 12px;
	border: none;
	background: none;
	cursor: pointer;
	text-align: left;
}

.autocomplete-item:hover {
	background: var(--color-background-hover);
}

.autocomplete-name {
	font-weight: 500;
	color: var(--color-main-text);
}

.autocomplete-email {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.selected-client {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	background: var(--color-primary-element-light);
	border-radius: var(--border-radius);
	margin-top: 4px;
}

.selected-name {
	flex: 1;
	font-size: 13px;
	color: var(--color-main-text);
}
</style>
