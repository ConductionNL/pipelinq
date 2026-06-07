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
		:custom-components="customComponents"
		:cell-widgets="cellWidgets"
		:page-types="pageTypes"
		app-id="pipelinq"
		:translate="translateForApp"
		:permissions="permissions"
		:requires-apps="[]">
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
		</template>
	</CnAppRoot>
</template>

<script>
import Vue from 'vue'
import { translate as ncT } from '@nextcloud/l10n'
import { CnAppRoot, CnObjectSidebar } from '@conduction/nextcloud-vue'
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
		 * Flattened `{ name: component }` map derived from the v2 `registry`
		 * prop, passed to CnAppRoot as the legacy `customComponents` prop.
		 *
		 * The monorepo dev build aliases @conduction/nextcloud-vue to the
		 * local `../nextcloud-vue/src`, which may be an older version that
		 * predates the ADR-036 v2 `registry` prop and resolves custom page
		 * components only via `customComponents`. Without this, every
		 * `type: "custom"` page renders blank ("[CnPageRenderer] Custom
		 * component X not found in registry"). A v2-capable library still
		 * prefers `registry` and treats `customComponents` as a fallback,
		 * so passing both is safe regardless of the resolved lib version.
		 */
		customComponents() {
			return Object.fromEntries(
				Object.entries(this.registry)
					.filter(([, entry]) => entry && entry.component)
					.map(([name, entry]) => [name, entry.component]),
			)
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
