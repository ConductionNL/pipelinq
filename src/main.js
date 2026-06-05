// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) 2026 Conduction B.V.

import Vue from 'vue'
import VueRouter from 'vue-router'
import { PiniaVuePlugin, setActivePinia } from 'pinia'
import { translate as t, translatePlural as n, loadTranslations } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	CnPageRenderer,
	defaultPageTypes,
	registerIcons,
	registerTranslations,
} from '@conduction/nextcloud-vue'
import pinia from './pinia.js'
import App from './App.vue'
import bundledManifest from './manifest.json'
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
 * Deep-merge an override object onto a base object. Plain objects merge
 * recursively; arrays concatenate (so fragment `pages`/`menu` entries are
 * appended to the bundled manifest); scalars from the override win.
 *
 * @param {object} base The base object (mutated and returned).
 * @param {object} override The override object.
 * @return {object} The merged base.
 */
function deepMergeManifest(base, override) {
	for (const key of Object.keys(override)) {
		const value = override[key]
		if (Array.isArray(value)) {
			base[key] = (Array.isArray(base[key]) ? base[key] : []).concat(value)
		} else if (value && typeof value === 'object') {
			base[key] = deepMergeManifest(
				(base[key] && typeof base[key] === 'object' && !Array.isArray(base[key])) ? base[key] : {},
				value,
			)
		} else {
			base[key] = value
		}
	}
	return base
}

/**
 * Merge modular manifest fragments (src/manifest.d/*.json) onto the bundled
 * manifest (ADR-037). Fragments let concurrent same-app builds add pages/menu
 * entries by dropping a new JSON file instead of editing the shared
 * manifest.json, eliminating merge conflicts. The `require.context` glob is
 * resolved at build time; a placeholder fragment guarantees ≥1 match.
 *
 * @param {object} manifest The bundled manifest.
 * @return {object} A new manifest with all fragments merged in.
 */
function mergeManifestFragments(manifest) {
	const merged = JSON.parse(JSON.stringify(manifest))
	try {
		const context = require.context('./manifest.d', false, /\.json$/)
		context.keys().sort().forEach((key) => {
			const fragment = context(key)
			deepMergeManifest(merged, (fragment && fragment.default) ? fragment.default : fragment)
		})
	} catch (e) {
		// Non-fatal — if the fragment dir is absent the bundled manifest stands.
		// eslint-disable-next-line no-console
		console.warn('[pipelinq] manifest fragment merge skipped', e)
	}
	return merged
}

const mergedManifest = mergeManifestFragments(bundledManifest)

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
	// Catch-all redirect to dashboard, preserving prior router behaviour.
	routes.push({ path: '*', redirect: '/' })
	return routes
}

const router = new VueRouter({
	mode: 'history',
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

// Register object types synchronously, before mount. Registration is static
// (slug-based) so it needs no app config; doing it up front means the object
// store registry is populated before the first view's onMounted fetchSchema()
// runs — even on a hard reload directly onto a list page. setActivePinia lets
// the store be used outside a component, before the Vue instance is created.
setActivePinia(pinia)
registerObjectTypes()

// Create and mount Vue instance immediately so the App renders.
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

// Load settings in the background — object types are already registered above.
initializeStores()
