// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const TerserPlugin = require('terser-webpack-plugin')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

// ⚠️ `node-polyfill-webpack-plugin` and `terser-webpack-plugin` are BUILD-TIME
// requirements of `@nextcloud/webpack-vue-config@7` that this app must declare
// itself. The first is only a `peerDependency` of that package (and `.npmrc`
// sets `legacy-peer-deps=true`, so peers are not auto-installed); the second is
// `require()`d without being declared at all. Neither is referenced anywhere in
// `src/`, so both look like dead dependencies — dropping either fails the build
// immediately with `Cannot find module …` from inside node_modules.
const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
// Production builds disable source maps entirely. The full `source-map` devtool
// (and Terser's own source-map generation) added significant memory and time on
// top of compilation, and emitted ~77 MB of .map files into js/. Dropping them
// keeps the output minified while lowering peak memory. Dev keeps cheap, fast
// line-level maps.
webpackConfig.devtool = isDev ? 'cheap-source-map' : false

// The base @nextcloud/webpack-vue-config hardcodes
//   output.publicPath = '/apps/<appName>/js/'
// which is wrong when the app lives in `apps-extra/`. The entry scripts are
// injected by PHP (Util::addScript) with the correct `/custom_apps/pipelinq/js/`
// webroot, but webpack's runtime loader uses output.publicPath for dynamically
// imported chunks, so those resolve against `/apps/pipelinq/js/`.
//
// ⚠️ That wrong path does NOT 404 — Nextcloud's PHP router answers 200 with
// `text/html` (the SPA shell), so the browser reports a MIME refusal and a
// ChunkLoadError rather than a missing file. Vue 2 never surfaced this because
// it emitted no async chunks; the Vue 3 dependency set splits @nextcloud/dialogs,
// @nextcloud/files, @nextcloud/paths and @mdi/js into dozens.
//
// 'auto' makes webpack derive the public path from the URL of the executing
// entry script at runtime, so lazy chunks load from wherever the app is
// actually mounted.
webpackConfig.output = {
	...webpackConfig.output,
	publicPath: 'auto',
}

webpackConfig.stats = {
	colors: true,
	modules: false,
}

const appId = 'pipelinq'
webpackConfig.entry = {
	main: {
		import: path.join(__dirname, 'src', 'main.js'),
		filename: appId + '-main.js',
	},
	adminSettings: {
		import: path.join(__dirname, 'src', 'settings.js'),
		filename: appId + '-settings.js',
	},
	dealsOverviewWidget: {
		import: path.join(__dirname, 'src', 'dealsOverviewWidget.js'),
		filename: appId + '-dealsOverviewWidget.js',
	},
	myLeadsWidget: {
		import: path.join(__dirname, 'src', 'myLeadsWidget.js'),
		filename: appId + '-myLeadsWidget.js',
	},
	recentActivitiesWidget: {
		import: path.join(__dirname, 'src', 'recentActivitiesWidget.js'),
		filename: appId + '-recentActivitiesWidget.js',
	},
	findClientWidget: {
		import: path.join(__dirname, 'src', 'findClientWidget.js'),
		filename: appId + '-findClientWidget.js',
	},
	startRequestWidget: {
		import: path.join(__dirname, 'src', 'startRequestWidget.js'),
		filename: appId + '-startRequestWidget.js',
	},
	createLeadWidget: {
		import: path.join(__dirname, 'src', 'createLeadWidget.js'),
		filename: appId + '-createLeadWidget.js',
	},
	portal: {
		import: path.join(__dirname, 'src', 'portal.js'),
		filename: appId + '-portal.js',
	},
}

// Use local source when available (monorepo dev), otherwise fall back to the
// published npm package.
//
// ⚠️ `USE_LOCAL_LIB` is opt-OUT across the fleet and the shared
// `apps-extra/nextcloud-vue` checkout sits on the Vue 2 (beta.*) line, so a
// default-on local build would silently compile Vue 2 library sources into this
// Vue 3 app. Opt IN explicitly (USE_LOCAL_LIB=true) and hard-fail if the local
// tree is not on the Vue 3 major.
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
let useLocalLib = process.env.USE_LOCAL_LIB === 'true' && fs.existsSync(localLib)
if (useLocalLib) {
	// The peer-vue test this replaces asked the wrong question. The sibling's
	// `peerDependencies.vue` is ^3.5.0 — it IS a Vue 3 library — so the check
	// passed, while the sibling was still 2.0.5 against a declared ^2.3.0. Being
	// on the Vue 3 line and being the version this app asked for are different
	// things, and only the second one is safe to alias in.
	//
	// Fail CLOSED: if the check cannot run, the sibling is refused.
	let localVersion = 'unreadable'
	let satisfied = false
	try {
		// eslint-disable-next-line n/no-extraneous-require
		const semver = require('semver')
		const required =
			require('./package.json').dependencies['@conduction/nextcloud-vue']
		localVersion = String(
			JSON.parse(
				fs.readFileSync(
					path.resolve(__dirname, '../nextcloud-vue/package.json'),
					'utf8',
				),
			).version || '',
		)
		satisfied = semver.satisfies(localVersion, required, {
			includePrerelease: true,
		})
	} catch (e) {
		satisfied = false
	}

	if (!satisfied) {
		// Warn rather than throw: refusing the sibling still produces a complete
		// build against the pinned npm package, so there is nothing to repair
		// before the build can proceed.
		// eslint-disable-next-line no-console
		console.warn(
			`[pipelinq] IGNORING sibling @conduction/nextcloud-vue@${localVersion} — `
				+ "it does not satisfy this app's declared range. Building against the npm dist.",
		)
		useLocalLib = false
	}
}

webpackConfig.resolve = {
	extensions: ['.vue', '.js', '.mjs'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib
			? { '@conduction/nextcloud-vue': localLib }
			: // Published mode: the package's main entry is dist/, but src/main.js
				// imports the integration-registry helpers from their 0-hop definition
				// modules (`@conduction/nextcloud-vue/integrations/...`) rather than the
				// barrel — the barrel's multi-hop re-exports of these functions resolve
				// to `undefined` across pipelinq's split shared-nc-vue chunk. Those
				// subpaths exist only under the package's published `src/` tree, so map
				// the `integrations` subpath there. The registry installs onto the
				// `window.OCA.OpenRegister.integrations` global, so resolving these from
				// src/ shares the same singleton as the dist components (no dual instance).
				{
					'@conduction/nextcloud-vue/integrations': path.resolve(
						__dirname,
						'node_modules/@conduction/nextcloud-vue/src/integrations',
					),
				}),
		// Deduplicate shared packages so the aliased library source uses the same
		// instances as the app (prevents dual-Pinia / dual-Vue / dual-router bugs).
		//
		// ⚠️ An alias that resolves to a package DIRECTORY makes webpack fall back
		// to `main`/`mainFields` and skip the package's `exports` map entirely.
		// `@nextcloud/vue@9`, `@nextcloud/dialogs@7` and `vue-router@5` ship NO
		// `main` and NO `module` — they are reachable only through their exports
		// map — so a directory alias for those resolves to NOTHING and produces one
		// `Can't resolve '<pkg>'` error per importing module (234 on petstore).
		// Point singleton aliases at a concrete entry FILE. (`vue` and `pinia`
		// still declare `main`, so a directory alias resolves for them.)
		vue$: path.resolve(__dirname, 'node_modules/vue'),
		pinia$: path.resolve(__dirname, 'node_modules/pinia'),
		'@nextcloud/vue$': path.resolve(
			__dirname,
			'node_modules/@nextcloud/vue/dist/index.mjs',
		),
		// @nextcloud/vue@9 hard-depends on vue-router ^5.1.0 while the app is on
		// vue-router 4, so a DUAL COPY is inevitable and the router injection keys
		// (module-local Symbols) would not match across it — `useRoute()` inside
		// library components would return undefined. Force one copy.
		'vue-router$': path.resolve(
			__dirname,
			'node_modules/vue-router/dist/vue-router.mjs',
		),
	},
}

// Keep the base module rules from @nextcloud/webpack-vue-config (VUE, CSS, SCSS, JS, ASSETS).
// Only replace plugins to avoid duplicate VueLoaderPlugin (base config also registers one).
webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({
		appVersion: JSON.stringify(process.env.npm_package_version),
	}),
]

// The former `@nextcloud/dialogs` + `@nextcloud/dialogs/style.css` directory
// aliases are GONE.
//
// They existed to stop "the nextcloud-vue submodule's nested deps (Vue 3)"
// leaking into a Vue 2 app — a rationale this migration inverts. Worse, they
// were the same landmine as the `@nextcloud/vue` alias above: dialogs v7 ships
// NO `main`, only `exports`, so aliasing the bare specifier to the package
// DIRECTORY makes every `import … from '@nextcloud/dialogs'` unresolvable. The
// companion `style.css$` alias is likewise unnecessary — v7's exports map
// publishes `./style.css` directly.

// The former `@nextcloud/axios$` shim (build/nextcloud-axios-default.cjs) is
// GONE. It existed because @nextcloud/vue@8's CJS bundle did
// `require('@nextcloud/axios')`, which failed webpack 5's exports check, and
// then double-wrapped the ESM namespace so `.interceptors` came back undefined.
// @nextcloud/vue@9 is ESM-only, so the bare `import` condition resolves
// normally and no shim is needed.

// The former `vue-demi$` pin to `lib/v2.7/index.mjs` is GONE — that variant is
// the Vue 2 shim by construction. vue-demi picks its shim in a `postinstall`,
// which npm does NOT re-run for an already-present version, so the switch is
// verified explicitly in CI/locally rather than pinned here (see the check in
// the migration notes: node_modules/vue-demi/lib/index.mjs must contain
// `import * as Vue` and `isVue2 = false`).

// nc-vue pulls ESM-only @nextcloud packages that declare only the `import`
// export condition; alias to the ESM entry so a CJS `require()` inside a
// transitive dependency still resolves.
webpackConfig.resolve.alias['@nextcloud/paths$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/paths/dist/index.mjs',
)
webpackConfig.resolve.alias['@nextcloud/notify_push$'] = path.resolve(
	__dirname,
	'node_modules/@nextcloud/notify_push/dist/index.js',
)

// @nextcloud/files (pulled transitively via @nextcloud/axios → @nextcloud/auth)
// references the Node core `stream` module, which webpack 5 does not polyfill for
// the browser. It is on a code path the app never hits, so provide an empty module.
// Same story for `path`: @nextcloud/dialogs v7 drags in a FilePicker chunk that
// imports it, and the app only uses the toast APIs (showError/showSuccess/
// showWarning), so the FilePicker code path never executes.
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	stream: false,
	path: false,
}

// Share Vue + @nextcloud/vue + pinia + icons + @conduction/nextcloud-vue
// across every entry-point so each widget bundle no longer inlines its own
// ~5 MB framework copy. Stable filenames (no contenthash in the JS name)
// mean each widget's `Util::addScript` PHP call can reference the chunk
// directly without a manifest. The vendor chunk is loaded once and cached
// across every widget/page in the app.
webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	splitChunks: {
		...(webpackConfig.optimization?.splitChunks || {}),
		chunks: 'all',
		cacheGroups: {
			default: false,
			defaultVendors: false,
			ncVue: {
				name: appId + '-shared-nc-vue',
				// Initial chunks ONLY. The outer `chunks: 'all'` would also match
				// modules the library imports dynamically, hoisting them into this
				// eager chunk and destroying the code-splitting they exist for —
				// nc-vue's RVO icon set (~1.9 MB) landed here in full, loaded on
				// every page, instead of being fetched when its picker tab is
				// opened. 'initial' leaves async imports in their own chunks.
				chunks: 'initial',
				// Matches both node_modules entries AND the monorepo-dev alias
				// `../nextcloud-vue/src/...` which webpack resolves outside
				// node_modules when @conduction/nextcloud-vue is aliased to it.
				test: /[\\/]node_modules[\\/](@nextcloud[\\/]vue|@conduction[\\/]nextcloud-vue)[\\/]|[\\/]nextcloud-vue[\\/]src[\\/]/,
				priority: 30,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-nc-vue.js',
			},
			vendor: {
				name: appId + '-shared-vendor',
				test: /[\\/]node_modules[\\/](vue|vue-router|vue-i18n|pinia|vue-material-design-icons|@vueuse|core-js|axios)[\\/]/,
				priority: 20,
				reuseExistingChunk: true,
				enforce: true,
				filename: appId + '-shared-vendor.js',
			},
		},
	},
}

if (!isDev) {
	// Minify with esbuild instead of Terser in production. Terser parses every
	// chunk into a full JS AST held in the Node heap and runs `CPU cores - 1`
	// parallel workers; across these large entrypoints the build peaked at ~12 GB
	// in ~33s. esbuild minifies in native (Go) code with a tiny heap footprint and
	// is ~10-100x faster, at the cost of only ~1-2% larger output. We reuse
	// terser-webpack-plugin purely as the wiring and swap its engine to the
	// built-in esbuild minifier.
	webpackConfig.optimization.minimizer = [
		new TerserPlugin({
			minify: TerserPlugin.esbuildMinify,
			// esbuild parallelizes internally (in Go), so terser-webpack-plugin's
			// default `cpus-1` Node worker processes only add memory overhead.
			// Disabling them lowers peak build RAM with no real speed cost.
			parallel: false,
			// esbuild minify options (NOT terserOptions). Keep legal/license
			// comments at end-of-file so MIT/AGPL attribution required by our
			// deps survives minification. (esbuild's sidecar-emitting 'linked'
			// mode is unavailable here — terser-webpack-plugin drives esbuild via
			// its transform API, which rejects 'linked'/'external'.)
			terserOptions: {
				legalComments: 'eof',
			},
		}),
	]

	// The base config keeps an in-memory cache (`cache: true`) in production.
	// A one-shot build never reuses it, so it only inflates the webpack main
	// process — the dominant memory consumer here. Disable it for the build.
	webpackConfig.cache = false
}

module.exports = webpackConfig
