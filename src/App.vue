<template>
	<NcContent app-name="pipelinq">
		<template v-if="storesReady">
			<MainMenu @open-settings="showSettingsDialog = true" />
			<NcAppContent>
				<router-view />
			</NcAppContent>
			<CnIndexSidebar
				v-if="sidebarState.active"
				:schema="sidebarState.schema"
				:visible-columns="sidebarState.visibleColumns"
				:search-value="sidebarState.searchValue"
				:active-filters="sidebarState.activeFilters"
				:facet-data="sidebarState.facetData"
				:open="sidebarState.open"
				@update:open="sidebarState.open = $event"
				@search="onSidebarSearch"
				@columns-change="onSidebarColumnsChange"
				@filter-change="onSidebarFilterChange" />
			<PipelineSidebar
				v-if="pipelineSidebarState.active && !sidebarState.active"
				:pipeline="pipelineSidebarState.pipeline"
				:open="pipelineSidebarState.open"
				@update:open="pipelineSidebarState.open = $event"
				@save="onPipelineSidebarSave" />
			<UserSettings :open.sync="showSettingsDialog" />
		</template>
		<NcAppContent v-else>
			<div style="display: flex; justify-content: center; align-items: center; height: 100%;">
				<NcLoadingIcon :size="64" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import Vue from 'vue'
import { NcContent, NcAppContent, NcLoadingIcon } from '@nextcloud/vue'
import { CnIndexSidebar } from '@conduction/nextcloud-vue'
import MainMenu from './navigation/MainMenu.vue'
import UserSettings from './views/settings/UserSettings.vue'
import PipelineSidebar from './views/pipeline/PipelineSidebar.vue'
import { initializeStores } from './store/store.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcLoadingIcon,
		CnIndexSidebar,
		MainMenu,
		UserSettings,
		PipelineSidebar,
	},

	provide() {
		return {
			sidebarState: this.sidebarState,
			pipelineSidebarState: this.pipelineSidebarState,
		}
	},

	data() {
		return {
			storesReady: false,
			showSettingsDialog: false,
			sidebarState: Vue.observable({
				active: false,
				open: true,
				schema: null,
				visibleColumns: null,
				searchValue: '',
				activeFilters: {},
				facetData: {},
				onSearch: null,
				onColumnsChange: null,
				onFilterChange: null,
			}),
			pipelineSidebarState: Vue.observable({
				active: false,
				open: true,
				pipeline: null,
				onSave: null,
			}),
		}
	},

	async created() {
		await initializeStores()
		this.storesReady = true
	},

	methods: {
		onSidebarSearch(value) {
			this.sidebarState.searchValue = value
			if (typeof this.sidebarState.onSearch === 'function') {
				this.sidebarState.onSearch(value)
			}
		},
		onSidebarColumnsChange(columns) {
			this.sidebarState.visibleColumns = columns
			if (typeof this.sidebarState.onColumnsChange === 'function') {
				this.sidebarState.onColumnsChange(columns)
			}
		},
		onSidebarFilterChange(filter) {
			if (typeof this.sidebarState.onFilterChange === 'function') {
				this.sidebarState.onFilterChange(filter)
			}
		},
		onPipelineSidebarSave(pipelineData) {
			if (typeof this.pipelineSidebarState.onSave === 'function') {
				this.pipelineSidebarState.onSave(pipelineData)
			}
		},
	},
}
</script>
