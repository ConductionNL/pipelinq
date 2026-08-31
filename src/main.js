// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import {
	buildManifest,
	CnPageRenderer,
	defaultPageTypes,
	mergeManifestDelta,
	registerBuiltinDashboardWidgets,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
// The `import/no-unresolved` disables on these subpath imports are gone: the
// flat config does not register that rule, so each comment was itself an
// error. Their note still holds — every `@conduction/nextcloud-vue/...`
// subpath below is resolved by a webpack alias, not by a Node resolver.
import { registerBuiltinIntegrations } from '@conduction/nextcloud-vue/integrations/builtin/index.js'
import { registerLeafIntegrations } from '@conduction/nextcloud-vue/integrations/builtin/leaves.js'
// Import the integration-registry functions from their DEFINITION modules
// (0 re-export hops) rather than the barrel: pipelinq splits the library into
// a separate `shared-nc-vue.js` chunk and its 9 entry points have no shared
// runtimeChunk, so the barrel's 3-hop re-exports of these functions resolve to
// `undefined` across the chunk boundary (components, used directly, are fine).
import { installIntegrationRegistry } from '@conduction/nextcloud-vue/integrations/registry.js'
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import {
	loadTranslations,
	translatePlural as n,
	translate as t,
} from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { setActivePinia } from 'pinia'
import { createApp, h, markRaw } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import App from './App.vue'
import appIcons from './icons.js'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import pinia from './pinia.js'
import registry from './registry.js'
import { initializeStores, registerObjectTypes } from './store/store.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
import '@conduction/nextcloud-vue/css/index.css'
// gridstack is a REQUIRED peer of @conduction/nextcloud-vue that no consumer
// declares, and the stylesheet is the silent half of it. Pipelinq ships
// `type: "dashboard"` manifest pages, and gridstack v12 sizes every grid item
// with `width: var(--gs-column-width)` — a custom property that only this
// stylesheet defines. Without it every dashboard item renders 0 px wide with
// NO console error: heights still look correct (those come from JS) while the
// widths silently collapse. nc-vue's own `css/index.css` does not bundle it.
import 'gridstack/dist/gridstack.min.css'
import './assets/app.css'

// Register the app's schema icons + lib translations once at bootstrap.
// Without this every schema `icon` name fails the CnIcon registry lookup
// and falls back to a help-circle (page headers, empty states).
registerIcons(appIcons)

// Register the library's built-in dashboard widgets (`stat`, `object-table`).
// nc-vue declares `sideEffects: ["**/*.css"]`, which lets webpack drop the
// library's own bare side-effect imports that perform this registration — so
// without this explicit call the manifest's 26 `stat` and 3 `object-table`
// widgets render "Widget not available". (`chart` survives regardless because
// it is registered inline; that asymmetry is exactly how this was identified
// on larpingapp.) Idempotent.
registerBuiltinDashboardWidgets()

// Pluggable integration registry (ADR-019 / Phase 7). Install the registry +
// register the built-in core/leaf integrations (xwiki / calendar / files /
// notes / …) so manifest `type: 'integration'` widgets resolve from the
// registry. Imported from the definition modules above (not the barrel) so the
// bindings resolve across pipelinq's split-out `shared-nc-vue.js` chunk.
installIntegrationRegistry()
registerBuiltinIntegrations()
registerLeafIntegrations()

try {
	registerTranslations()
} catch (e) {
	// Non-fatal — lib translations fall back to English source.
	console.warn(
		'[pipelinq] registerTranslations failed; falling back to English',
		e,
	)
}

// Fire-and-forget translation load. Some Nextcloud installs (including
// this repo's standard dev container) only allow the JS/CSS allowlist
// through Apache and rewrite everything else to index.php — there's no
// route for /custom_apps/<app>/l10n/<locale>.json so the request 404s.
// `loadTranslations` rejects on 404, so wrapping the Vue mount inside
// its callback meant boot silently failed when translations couldn't
// load. Strings just fall back to their English source on miss; boot
// MUST not depend on this resolving.
/**
 *
 */
function tryLoadTranslations() {
	try {
		const result = loadTranslations('pipelinq', () => {})
		if (result && typeof result.then === 'function') {
			result.then(
				() => {},
				() => {},
			)
		}
	} catch {
		// no-op
	}
}

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible (webpack ESM module records), and `markRaw` writes a
// `__v_skip` marker through `Object.defineProperty`, which throws against a
// frozen object. Cloning gives vue-router an extensible component-options
// object without altering the lib's internals; `markRaw` then keeps Vue 3 from
// making the component definition itself reactive inside the route record.
const RoutePageRenderer = markRaw({ ...CnPageRenderer })

/**
 * Seed the page-level app config onto every `type: "dashboard"` page's
 * `config.appConfig`. CnPageRenderer forwards each `config.*` key to the
 * dispatched page component's props, so this lands on CnDashboardPage's
 * `appConfig` prop — the source the library's `@config.<key>` token resolver
 * reads (via the `cnAppConfig` inject it provides to descendant stat widgets).
 * Backed by the `config` initial state the app's Application::boot() provides
 * (currently the reporting `currency` captured by the setup wizard, default
 * EUR). With this seed a manifest widget's `format: { style: "currency",
 * currency: "@config.currency" }` formats with the configured currency instead
 * of the literal EUR fallback. An explicit per-page `config.appConfig` (none
 * today) still wins.
 *
 * @param {object} manifest The merged manifest (with `pages[]`).
 * @return {object} The same manifest, with dashboard pages' appConfig seeded.
 */
function seedDashboardAppConfig(manifest) {
	const appConfig = loadState('pipelinq', 'config', {})
	for (const page of manifest.pages || []) {
		if (page.type === 'dashboard') {
			page.config = { appConfig, ...(page.config || {}) }
		}
	}
	return manifest
}

// `require.context` is a WEBPACK build-time API, not CommonJS `require`: the
// bundler rewrites this call at compile time and no `require` exists at
// runtime. eslint's browser globals therefore report `no-undef` correctly —
// the code is right and the linter is right. Scoped to this one identifier so
// a genuinely undefined name elsewhere in the file still fails.
/* global require */
const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx
	.keys()
	.sort()
	.map((key) => fragmentCtx(key))
const mergedManifest = seedDashboardAppConfig(
	buildManifest(bundledManifest, fragments, menuLayout),
)

/**
 * Build the vue-router config from the manifest. Each manifest page
 * becomes one route; the route's `name` IS `page.id` (per the lib's
 * manifest contract). Routes whose path declares a `:` parameter pass
 * `props: true` so the renderer receives params as props — generic,
 * schema-agnostic.
 *
 * @param {object} manifest The bundled manifest (with `pages[]`).
 * @return {Array<object>} vue-router 3 routes config.
 */
function routesFromManifest(manifest) {
	const routes = manifest.pages.map((page) => ({
		name: page.id,
		path: page.route,
		component: RoutePageRenderer,
		props: page.route.includes(':'),
	}))
	// vue-router matches in declaration order, so a param route like `/pos/:id`
	// would otherwise swallow a static sibling like `/pos/tender-types` on a
	// direct (non-SPA) load. Manifest page order isn't guaranteed — manifest.d
	// fragments are appended last — so order routes by parameter count ascending
	// (static before parameterised). Array.sort is stable, so each group keeps
	// its original relative order.
	const paramCount = (path) => (path.match(/:/g) || []).length
	routes.sort((a, b) => paramCount(a.path) - paramCount(b.path))
	// Catch-all redirect to dashboard, preserving prior router behaviour.
	//
	// vue-router 4 REMOVED the bare `path: '*'` wildcard. It does not error —
	// it simply matches nothing, so every unmatched route would render the app
	// shell with an empty <main> and no console output. `/:pathMatch(.*)*` is
	// its v4 replacement.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

/**
 * Load the persisted buildiq app-override delta and merge it over the
 * build-time manifest (ADR-041 round-trip: App.vue's persistManifestDelta PUTs
 * edits to this store; this loader brings them back at boot). The GET returns
 * the LAYERED delta (shared admin delta ⊕ the calling user's own delta), or
 * `{}` when no override exists. Fail-soft: any error, buildiq not
 * installed, endpoint unreachable, malformed delta — falls back to the
 * build-time manifest, so an override can never prevent the app from booting.
 *
 * @param {object} manifest The build-time merged manifest.
 * @return {Promise<object>} The manifest with persisted overrides applied.
 *
 * @spec exclude Bug fix closing the ADR-041 persist/load round-trip; the
 *               delta contract is owned by buildiq's
 *               layered-versioned-app-deltas specs.
 */
async function loadPersistedOverrides(manifest) {
	try {
		const { data } = await axios.get(
			generateUrl('/apps/buildiq/api/app-overrides/pipelinq'),
			{ timeout: 8000 },
		)
		if (
			data !== null
			&& typeof data === 'object'
			&& !Array.isArray(data)
			&& Object.keys(data).length > 0
		) {
			const { manifest: merged, orphanedDeltaPaths } = mergeManifestDelta(
				manifest,
				data,
			)
			if (orphanedDeltaPaths.length > 0) {
				console.warn(
					'[pipelinq] Manifest override has orphaned delta paths (base changed since the edit):',
					orphanedDeltaPaths,
				)
			}
			return merged
		}
	} catch (error) {
		console.warn(
			'[pipelinq] Could not load persisted manifest overrides — using the bundled manifest.',
			error,
		)
	}
	return manifest
}

tryLoadTranslations()

// Pass shallow copies of the registry maps to CnAppRoot. The lib exports
// `defaultPageTypes` and `registry` as frozen module objects in some bundle
// shapes — Vue 2's `Vue.extend()` mutates component definitions to attach an
// internal `_Ctor` cache, which throws "Cannot add property _Ctor, object is
// not extensible" against a frozen source map. Cloning here yields extensible
// objects without changing the values the lib resolves at render time.
// Shipped lib-side as part of @conduction/nextcloud-vue@1.0.0-beta.12;
// defence-in-depth here.
const pageTypesProp = { ...defaultPageTypes }
const registryProp = { ...registry }

// Register object types synchronously before mount, so the store registry is
// populated before any view's onMounted fetchSchema() runs. setActivePinia lets
// the store be used before the Vue instance exists.
setActivePinia(pinia)
registerObjectTypes()

/**
 * Mount the Vue instance onto #content. The router is built here — not at
 * module scope — because persisted overrides can add or remove pages, and
 * routes must reflect the final manifest.
 *
 * @param {object} manifest The final manifest (overrides applied).
 */
function mountApp(manifest) {
	const router = createRouter({
		// vue-router 4 replaces `mode: 'hash'` + `base` with a history object.
		history: createWebHashHistory(generateUrl('/apps/pipelinq')),
		routes: routesFromManifest(manifest),
	})
	// Vue 3: `createApp(...).mount()` replaces `new Vue(...).$mount()`, and
	// `h()` takes props as a FLAT second argument — the Vue 2 `{ props: { … } }`
	// nesting is silently ignored, which would leave CnAppRoot with no manifest
	// at all (blank shell, no error).
	const app = createApp({
		render: () =>
			h(App, {
				manifest,
				registry: registryProp,
				pageTypes: pageTypesProp,
			}),
	})
	// Vue 3 has no global `Vue.mixin` / `Vue.use`; both are per-app-instance.
	// Pinia is a normal plugin now — `PiniaVuePlugin` was Vue-2 only.
	app.mixin({ methods: { t, n } })
	app.use(pinia)
	app.use(router)
	// Mount on the app's OWN host element (templates/index.php), never
	// `#content`: Nextcloud core's layout.user.php already owns a
	// `<div id="content">` that this template renders inside, and Vue 3
	// `mount()` renders INSIDE the match (Vue 2 `$mount()` REPLACED it).
	app.mount('#pipelinq-app')
}

// Gate the mount on initializeStores() (types registered and settings loaded
// before the first view fetches; a settings failure still mounts the shell —
// views then degrade to their own empty/retry state) and on the persisted
// override load (both fail-soft, so the slower of the two bounds boot time).
Promise.all([
	initializeStores().catch(() => {}),
	loadPersistedOverrides(mergedManifest),
]).then(([, manifest]) => {
	mountApp(manifest)
})
