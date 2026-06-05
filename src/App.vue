<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Pipelinq app shell. Mounts CnAppRoot with the bundled manifest and
 the v2 kind-tagged registry prop (ADR-036); provides the
 `objectSidebarState` channel so detail pages (CnDetailPage) can drive
 a single host-rendered CnObjectSidebar through the #sidebar slot.

 The deprecated `customComponents` prop has been replaced by the v2
 `registry` prop (ADR-036). Each page/widget component is wrapped as
 `{ kind, component }` in src/registry.js and resolved by CnPageRenderer
 at render time. See nextcloud-vue#458 and openregister#1988.

 The legacy `sidebarState` channel is still provided as a no-op
 surface for any straggler view that injects it (notably the dead
 `views/clients/ClientList.vue`), but App.vue no longer renders a
 CnIndexSidebar — the lib's CnAppRoot auto-hoists CnIndexPage's
 sidebar at NcContent level, so rendering one here too produced the
 double-sidebar bug visible on every index page.

 The bespoke PipelineSidebar was removed with the pipeline board migration
 to the OpenRegister deck leaf. See openspec/changes/migrate-pipeline-to-deck-leaf/.

 @spec openspec/changes/pipelinq-manifest-v1/tasks.md
-->
<template>
	<CnAppRoot
		:manifest="manifest"
		:registry="registry"
		:page-types="pageTypes"
		app-id="pipelinq"
		:translate="translateForApp"
		:permissions="permissions"
		:requires-apps="[]">
		<template #sidebar>
			<!--
				When active, this renders CnObjectSidebar's default tabs (Files,
				Notes, Tags, Tasks, Audit trail). The detail pages ClientDetail,
				RequestDetail and LeadDetail previously declared Flow/Deck/Time-tracker
				sidebar tabs + body cards in the manifest (component:
				"CnFlowTab"/"CnDeckTab"/"CnTimeTrackerTab" + matching *Card widgets).
				Those were removed because the components do not exist: they are meant
				to be OpenRegister integration "leaf" components surfaced via the
				pluggable integration registry, but the leaf code — and even
				registerLeafIntegrations — was never implemented in any repo, so they
				rendered iconless tabs with empty panels. With the custom tabs gone,
				the default tabs aren't wanted there either, so those three pages now
				set config.sidebar.enabled: false (no sidebar at all).
				To bring the integrations back once the leaves ship: re-enable the
				sidebar, register the integrations on window.OCA.OpenRegister.integrations,
				and set :use-registry="true" here (NOT the manifest `component:` string
				path). See openspec/changes/migrate-automation-to-flow-leaf,
				migrate-pipeline-to-deck-leaf, and archive/2026-05-31-time-entry-core.
				Verified absent on the development branch of pipelinq, openregister,
				and @conduction/nextcloud-vue (2026-06-03).
			-->
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
		</template>
	</CnAppRoot>
</template>

<script>
import Vue from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import { CnAppRoot, CnObjectSidebar } from '@conduction/nextcloud-vue'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		CnObjectSidebar,
	},

	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-app-shell/tasks.md#task-3
	 */
	provide() {
		return {
			// Channel for CnDetailPage → host-rendered CnObjectSidebar.
			// Vue.observable makes the plain object reactive for Vue 2.
			objectSidebarState: this.objectSidebarState,
			// Legacy channel — kept so bespoke index views (CnIndexPage
			// wrappers) continue to inject it.
			sidebarState: this.sidebarState,
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
		 * V2 component registry (ADR-036) — maps string keys from
		 * `manifest.pages[].component` to `{ kind, component }` entries.
		 * Page components use `kind: "page"`; dashboard widget/header
		 * overrides use `kind: "widget"`. Passed through to CnAppRoot for
		 * v2 renderer resolution. See nextcloud-vue#458 and openregister#1988.
		 */
		registry: {
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
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-app-shell/tasks.md#task-2
		 */
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
		 * @spec openspec/changes/reverse-2026-05-26-fe-app-shell/tasks.md#task-4
		 */
		translateForApp(key) {
			return ncT('pipelinq', key)
		},
	},
}
</script>
