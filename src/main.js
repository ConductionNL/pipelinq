// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin, setActivePinia } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { loadState } from '@nextcloud/initial-state'
import {
	buildManifest,
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
// Import the integration-registry functions from their DEFINITION modules
// (0 re-export hops) rather than the barrel: pipelinq splits the library into
// a separate `shared-nc-vue.js` chunk and its 9 entry points have no shared
// runtimeChunk, so the barrel's 3-hop re-exports of these functions resolve to
// `undefined` across the chunk boundary (components, used directly, are fine).
// eslint-disable-next-line import/no-unresolved -- subpath resolved by webpack alias
import { installIntegrationRegistry } from '@conduction/nextcloud-vue/integrations/registry.js'
// eslint-disable-next-line import/no-unresolved -- subpath resolved by webpack alias
import { registerBuiltinIntegrations } from '@conduction/nextcloud-vue/integrations/builtin/index.js'
// eslint-disable-next-line import/no-unresolved -- subpath resolved by webpack alias
import { registerLeafIntegrations } from '@conduction/nextcloud-vue/integrations/builtin/leaves.js'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
import menuLayout from './menu-layout.json'
import registry from './registry.js'
import appIcons from './icons.js'
import { initializeStores, registerObjectTypes } from './store/store.js'

// Library CSS — must be explicit import (webpack tree-shakes side-effect imports from aliased packages)
// eslint-disable-next-line import/no-unresolved -- CSS subpath resolved by webpack alias, not ESLint's resolver
import '@conduction/nextcloud-vue/css/index.css'
import './assets/app.css'

Vue.mixin({ methods: { t, n } })
Vue.use(PiniaVuePlugin)
Vue.use(VueRouter)

// Register the app's schema icons + lib translations once at bootstrap.
// Without this every schema `icon` name fails the CnIcon registry lookup
// and falls back to a help-circle (page headers, empty states).
registerIcons(appIcons)

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
	// eslint-disable-next-line no-console
	console.warn('[pipelinq] registerTranslations failed; falling back to English', e)
}

// Fire-and-forget translation load. Some Nextcloud installs (including
// this repo's standard dev container) only allow the JS/CSS allowlist
// through Apache and rewrite everything else to index.php — there's no
// route for /custom_apps/<app>/l10n/<locale>.json so the request 404s.
// `loadTranslations` rejects on 404, so wrapping the Vue mount inside
// its callback meant boot silently failed when translations couldn't
// load. Strings just fall back to their English source on miss; boot
// MUST not depend on this resolving.
function tryLoadTranslations() {
	try {
		const result = loadTranslations('pipelinq', () => {})
		if (result && typeof result.then === 'function') {
			result.then(() => {}, () => {})
		}
	} catch {
		// no-op
	}
}

// Shallow-clone CnPageRenderer because the lib's barrel exports are
// non-extensible (webpack ESM module records). Vue 2's `Vue.extend()`
// adds an internal `_Ctor` cache to the component definition; mutating
// a non-extensible export throws "Cannot add property _Ctor, object is
// not extensible". Cloning gives Vue Router an extensible
// component-options object without altering the lib's internals.
const RoutePageRenderer = { ...CnPageRenderer }

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

const fragmentCtx = require.context('./manifest.d/', false, /\.json$/)
const fragments = fragmentCtx.keys().sort().map((key) => fragmentCtx(key))
const mergedManifest = seedDashboardAppConfig(buildManifest(bundledManifest, fragments, menuLayout))

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
	routes.push({ path: '*', redirect: '/' })
	return routes
}

const router = new VueRouter({
	mode: 'hash',
	base: generateUrl('/apps/pipelinq'),
	routes: routesFromManifest(mergedManifest),
})

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

/** Mount the Vue instance onto #content. */
function mountApp() {
	new Vue({
		pinia,
		router,
		render: (h) => h(App, {
			props: {
				manifest: mergedManifest,
				registry: registryProp,
				pageTypes: pageTypesProp,
			},
		}),
	}).$mount('#content')
}

// Gate the mount on initializeStores() so types are registered and settings
// loaded before the first view fetches. A settings failure still mounts the
// shell (catch/finally) — views then degrade to their own empty/retry state.
initializeStores().catch(() => {}).finally(() => {
	mountApp()
})
