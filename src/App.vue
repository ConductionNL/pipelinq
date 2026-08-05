<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Pipelinq app shell. Mounts CnAppRoot with the bundled manifest and the v2
 kind-tagged registry prop (ADR-036); provides `objectSidebarState` so detail
 pages drive a single host-rendered CnObjectSidebar via the #sidebar slot.
 App.vue renders no CnIndexSidebar itself — CnAppRoot auto-hoists CnIndexPage's
 sidebar, so rendering one here too caused a double sidebar on index pages.

 @spec openspec/changes/pipelinq-manifest-v1/tasks.md
-->
<template>
	<CnAppRoot
		:ai-companion="true"
		:manifest="manifest"
		:registry="registry"
		:cell-widgets="cellWidgets"
		:page-types="pageTypes"
		app-id="pipelinq"
		:translate="translateForApp"
		:permissions="permissions"
		:persist-manifest-delta="persistManifestDelta"
		:requires-apps="[]">
		<template #sidebar>
			<!--
				Host-rendered CnObjectSidebar. Detail pages declare their tabs in
				config.sidebar.tabs by component name; sidebarComponents resolves
				those names to the library's integration leaves. Passing `tabs` with
				:use-registry=false puts it in open-enum mode (manifest is the single
				source of truth, avoids the registry-vs-tabs warning).
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
import { reactive } from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
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
			// `reactive()` (Vue 3's replacement for `Vue.observable`) makes the
			// plain object reactive, so injected consumers track its mutations.
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
			objectSidebarState: reactive({
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
			sidebarState: reactive({
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
		 * Cell-widget registry for CnAppRoot, keyed by the `widget` id a
		 * manifest column references (ADR-036).
		 *
		 * @return {Record<string, object>}
		 * @spec openspec/changes/customer-360/tasks.md#task-6.1
		 * @spec openspec/changes/customer-360/tasks.md#task-6.2
		 */
		cellWidgets() {
			return {
				'lead-close-date': LeadCloseDateCell,
				'lead-probability': LeadProbabilityCell,
			}
		},
		/**
		 * Component registry for the host CnObjectSidebar, keyed by component
		 * name. Maps the library's integration tab/widget leaves so manifest
		 * `sidebar.tabs[].component` strings resolve.
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
		 * Persist an in-app manifest edit (ADR-041). Called by CnAppRoot's editor
		 * on Save with the minimal delta; PUTs it to OpenBuild's app-override
		 * store so the edit survives reload (loaded back in main.js bootstrap).
		 *
		 * @param {object} delta The minimal manifest delta from the editor.
		 * @return {Promise<void>}
		 */
		async persistManifestDelta(delta) {
			await axios.put(generateUrl('/apps/openbuild/api/app-overrides/pipelinq'), delta)
		},
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
