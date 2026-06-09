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
		:cell-widgets="cellWidgets"
		:page-types="pageTypes"
		app-id="pipelinq"
		:translate="translateForApp"
		:permissions="permissions"
		:requires-apps="[]">
		<template #sidebar>
			<!--
				Host-rendered CnObjectSidebar driven by the manifest. The detail
				pages ClientDetail, RequestDetail and LeadDetail declare their
				integration sidebar tabs (Deck/Flow/Time-tracker/XWiki) in
				config.sidebar.tabs via `component: "CnDeckTab"` etc. The matching
				leaf components now ship in @conduction/nextcloud-vue's integration
				registry (src/integrations/builtin/*), so `sidebarComponents` below
				registers them by name as this sidebar's customComponents and the
				lib's open-enum `tabs[].component` resolution mounts them.
				Passing `tabs` puts the sidebar in open-enum mode; :use-registry is
				false so the manifest is the single source of truth for the tab set
				(and the registry-vs-tabs console.warn is avoided). Pages with no
				tabs simply set config.sidebar.enabled: false and render no sidebar.
				Note: body integration *cards* (CnDeckCard/…) are NOT wired here —
				CnDetailPage renders those as type:"integration" grid widgets via the
				integration registry, a separate mechanism.
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
				:custom-components="sidebarComponents"
				:use-registry="false"
				:open="objectSidebarState.open"
				@update:open="objectSidebarState.open = $event" />
		</template>
	</CnAppRoot>
</template>

<script>
import Vue from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import { CnAppRoot, CnObjectSidebar, builtinIntegrations } from '@conduction/nextcloud-vue'
import LeadCloseDateCell from './views/leads/cells/LeadCloseDateCell.vue'
import LeadProbabilityCell from './views/leads/cells/LeadProbabilityCell.vue'

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
		/**
		 * Cell-widget registry passed to CnAppRoot — every entry must be
		 * referenced from a manifest column's `widget` id. The lead-list
		 * close-date + probability columns reach this registry via
		 * `pages[Leads].config.columns[].widget` per ADR-036.
		 *
		 * @return {Record<string, object>}
		 *
		 * @spec openspec/changes/klantbeeld-360/tasks.md#task-6.1
		 * @spec openspec/changes/klantbeeld-360/tasks.md#task-6.2
		 */
		cellWidgets() {
			return {
				'lead-close-date': LeadCloseDateCell,
				'lead-probability': LeadProbabilityCell,
			}
		},
		/**
		 * Component registry for the host CnObjectSidebar, keyed by component
		 * name (CnDeckTab, CnFlowTab, …). Built from the library's integration
		 * descriptors so the detail pages' manifest `sidebar.tabs[].component`
		 * strings resolve to the real integration leaf components. Both tab and
		 * widget components are mapped; only the tab components are referenced
		 * today (open-enum tabs), the widgets are mapped for forward use.
		 *
		 * @return {Record<string, object>}
		 */
		sidebarComponents() {
			const map = {}
			for (const i of builtinIntegrations) {
				if (i.tab && i.tab.name) map[i.tab.name] = i.tab
				if (i.widget && i.widget.name) map[i.widget.name] = i.widget
			}
			return map
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
