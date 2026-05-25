<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Pipelinq app shell. Mounts CnAppRoot with the bundled manifest and
 the customComponents registry; provides the `objectSidebarState`
 channel so detail pages (CnDetailPage) can drive a single
 host-rendered CnObjectSidebar through the #sidebar slot.

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
		:custom-components="customComponents"
		:registry="registry"
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
		 * V2 component registry — maps string keys from `manifest.pages[].component`
		 * to `{ kind, component }` entries. Passed through to CnAppRoot for v2
		 * renderer resolution. The v2 renderer emits a one-shot deprecation
		 * warning when both `registry` and `customComponents` are present and the
		 * manifest declares `$schema` as the v2 URL.
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
