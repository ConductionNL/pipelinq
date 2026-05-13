<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Pipelinq app shell. Mounts CnAppRoot with the bundled manifest and
 the customComponents registry; provides the `objectSidebarState`
 channel so detail pages (CnDetailPage) can drive a single
 host-rendered CnObjectSidebar through the #sidebar slot.

 The legacy `sidebarState` and `pipelineSidebarState` channels are
 preserved so the bespoke pipeline kanban (PipelineBoard.vue) and any
 other custom view that injects them keep working through the
 transition.

 @spec openspec/changes/pipelinq-manifest-v1/tasks.md
-->
<template>
	<CnAppRoot
		:manifest="manifest"
		:custom-components="customComponents"
		:page-types="pageTypes"
		app-id="pipelinq"
		:translate="translateForApp"
		:permissions="permissions">
		<template #sidebar>
			<CnObjectSidebar
				v-if="objectSidebarState.active"
				:title="objectSidebarState.title"
				:subtitle="objectSidebarState.subtitle"
				:object-type="objectSidebarState.objectType"
				:object-id="objectSidebarState.objectId"
				:register="objectSidebarState.register"
				:schema="objectSidebarState.schema"
				:hidden-tabs="objectSidebarState.hiddenTabs"
				:tabs="objectSidebarState.tabs"
				:open="objectSidebarState.open"
				@update:open="objectSidebarState.open = $event" />
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
		</template>
	</CnAppRoot>
</template>

<script>
import Vue from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import { CnAppRoot, CnIndexSidebar, CnObjectSidebar } from '@conduction/nextcloud-vue'
import PipelineSidebar from './views/pipeline/PipelineSidebar.vue'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		CnIndexSidebar,
		CnObjectSidebar,
		PipelineSidebar,
	},

	provide() {
		return {
			// Channel for CnDetailPage → host-rendered CnObjectSidebar.
			// Vue.observable makes the plain object reactive for Vue 2.
			objectSidebarState: this.objectSidebarState,
			// Legacy channels — kept so bespoke index views (CnIndexPage
			// wrappers) and the PipelineBoard's custom sidebar continue
			// to inject them. Migrating these to the lib's
			// objectSidebarState contract is a follow-up.
			sidebarState: this.sidebarState,
			pipelineSidebarState: this.pipelineSidebarState,
		}
	},

	props: {
		/**
		 * Manifest object — passed from main.js bootstrap. CnAppRoot reads
		 * `manifest.dependencies` for the dependency-check phase and
		 * `manifest.menu` for the default CnAppNav.
		 */
		manifest: {
			type: Object,
			required: true,
		},
		/**
		 * Registry of consumer-injected components used by:
		 *   - `type: "custom"` pages (`page.component`)
		 *   - `headerComponent` / `actionsComponent` slot overrides
		 *   - `pages[].config.sidebarTabs[].component` (detail tab tabs)
		 *   - `pages[].config.sections[].component` (settings rich sections)
		 */
		customComponents: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Page-type registry — `{ index, detail, dashboard, settings, ... }`.
		 * Wired through to descendant `CnPageRenderer` instances via
		 * provide/inject.
		 */
		pageTypes: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
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
				tabs: undefined,
			}),
			// Legacy channel for bespoke index views.
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
			// Legacy channel for the bespoke pipeline kanban sidebar.
			pipelineSidebarState: Vue.observable({
				active: false,
				open: true,
				pipeline: null,
				onSave: null,
			}),
		}
	},

	computed: {
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},
	},

	methods: {
		/**
		 * Translate function passed down to CnAppRoot / CnAppNav /
		 * CnPageRenderer. Closes over the Nextcloud `translate` import
		 * so the lib never has to know our app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 */
		translateForApp(key) {
			return ncT('pipelinq', key)
		},
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
