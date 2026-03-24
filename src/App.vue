<template>
	<NcContent app-name="pipelinq">
		<!-- OpenRegister not installed: show empty state -->
		<NcAppContent v-if="storesReady && !hasOpenRegisters" class="open-register-missing">
			<NcEmptyContent
				:name="t('pipelinq', 'OpenRegister is required')"
				:description="t('pipelinq', 'Pipelinq needs the OpenRegister app to store and manage data. Please install OpenRegister from the app store to get started.')">
				<template #icon>
					<img :src="appIcon" class="open-register-icon">
				</template>
				<template #action>
					<NcButton
						v-if="isAdmin"
						type="primary"
						:href="appStoreUrl">
						{{ t('pipelinq', 'Install OpenRegister') }}
					</NcButton>
					<p v-else class="open-register-admin-hint">
						{{ t('pipelinq', 'Ask your administrator to install the OpenRegister app.') }}
					</p>
				</template>
			</NcEmptyContent>
		</NcAppContent>

		<!-- App loaded normally -->
		<template v-else-if="storesReady && hasOpenRegisters">
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
			<CnObjectSidebar
				v-if="objectSidebarState.active && !sidebarState.active && !pipelineSidebarState.active"
				:title="objectSidebarState.title"
				:subtitle="objectSidebarState.subtitle"
				:object-type="objectSidebarState.objectType"
				:object-id="objectSidebarState.objectId"
				:register="objectSidebarState.register"
				:schema="objectSidebarState.schema"
				:hidden-tabs="objectSidebarState.hiddenTabs"
				:open="objectSidebarState.open"
				@update:open="objectSidebarState.open = $event" />
			<PipelineSidebar
				v-if="pipelineSidebarState.active && !sidebarState.active"
				:pipeline="pipelineSidebarState.pipeline"
				:open="pipelineSidebarState.open"
				@update:open="pipelineSidebarState.open = $event"
				@save="onPipelineSidebarSave" />
			<UserSettings :open.sync="showSettingsDialog" />
		</template>

		<!-- Loading -->
		<NcAppContent v-else>
			<div style="display: flex; justify-content: center; align-items: center; height: 100%;">
				<NcLoadingIcon :size="64" />
			</div>
		</NcAppContent>
	</NcContent>
</template>

<script>
import Vue from 'vue'
import { NcContent, NcAppContent, NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { CnIndexSidebar, CnObjectSidebar } from '@conduction/nextcloud-vue'
import { generateUrl, imagePath } from '@nextcloud/router'
import MainMenu from './navigation/MainMenu.vue'
import UserSettings from './views/settings/UserSettings.vue'
import PipelineSidebar from './views/pipeline/PipelineSidebar.vue'
import { initializeStores, useSettingsStore } from './store/store.js'

export default {
	name: 'App',
	components: {
		NcContent,
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		CnIndexSidebar,
		CnObjectSidebar,
		MainMenu,
		UserSettings,
		PipelineSidebar,
	},

	provide() {
		return {
			sidebarState: this.sidebarState,
			pipelineSidebarState: this.pipelineSidebarState,
			objectSidebarState: this.objectSidebarState,
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
			objectSidebarState: Vue.observable({
				active: false,
				open: true,
				objectType: '',
				objectId: '',
				title: '',
				subtitle: '',
				register: '',
				schema: '',
				hiddenTabs: [],
			}),
		}
	},

	computed: {
		hasOpenRegisters() {
			const settingsStore = useSettingsStore()
			return settingsStore.hasOpenRegisters
		},
		isAdmin() {
			const settingsStore = useSettingsStore()
			return settingsStore.getIsAdmin
		},
		appIcon() {
			return imagePath('pipelinq', 'app-dark.svg')
		},
		appStoreUrl() {
			return generateUrl('/settings/apps/integration/openregister')
		},
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

<style scoped>
.open-register-icon {
	width: 64px;
	height: 64px;
	filter: var(--background-invert-if-dark);
}

.open-register-admin-hint {
	color: var(--color-text-maxcontrast);
	text-align: center;
}
</style>
