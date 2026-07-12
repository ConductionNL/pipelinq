const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const TerserPlugin = require('terser-webpack-plugin')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

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
// injected by PHP (Util::addScript) with the correct `/apps-extra/pipelinq/js/`
// webroot, but webpack's runtime loader uses output.publicPath for dynamically
// imported chunks (gridstack, apexcharts inside @conduction/nextcloud-vue), so
// those 404 against `/apps/pipelinq/js/`. 'auto' makes webpack derive the public
// path from the URL of the executing entry script at runtime, so lazy chunks
// load from wherever the app is actually mounted.
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

// Use local source when available (monorepo dev), otherwise fall back to npm package
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = false // build against the published node_modules dist (nc-vue beta.151+)

webpackConfig.resolve = {
	extensions: ['.vue', '.js'],
	alias: {
		'@': path.resolve(__dirname, 'src'),
		...(useLocalLib
			? { '@conduction/nextcloud-vue': localLib }
			// Published mode: the package's main entry is dist/, but src/main.js
			// imports the integration-registry helpers from their 0-hop definition
			// modules (`@conduction/nextcloud-vue/integrations/...`) rather than the
			// barrel — the barrel's multi-hop re-exports of these functions resolve
			// to `undefined` across pipelinq's split shared-nc-vue chunk. Those
			// subpaths exist only under the package's published `src/` tree, so map
			// the `integrations` subpath there. The registry installs onto the
			// `window.OCA.OpenRegister.integrations` global, so resolving these from
			// src/ shares the same singleton as the dist components (no dual instance).
			: { '@conduction/nextcloud-vue/integrations': path.resolve(__dirname, 'node_modules/@conduction/nextcloud-vue/src/integrations') }),
		// Deduplicate shared packages so the aliased library source uses
		// the same instances as the app (prevents dual-Pinia / dual-Vue bugs).
		// Pin the CONCRETE CommonJS runtime build, NOT the package dir nor the
		// `vue.runtime.common.js` switcher. webpack would otherwise pick vue's
		// `module` field (vue.runtime.esm.js), whose ESM namespace omits Vue's
		// static methods (`util`, `observable`, `defineComponent`, …). nc-vue's
		// dist + its inlined vue-demi (via vue-codemirror6/@vueuse) and the
		// consumer's pinia do `import Vue from 'vue'; Vue.<static>()`, compiled to
		// `require('vue').<static>` — undefined on the ESM namespace, BLANKING the
		// whole app at mount ("reading 'warn'" / "observable is not a function").
		// Point straight at the dev/prod build file (each ends `module.exports =
		// Vue`, the full constructor): the 173-byte `vue.runtime.common.js` switcher
		// (`module.exports = require('./prod')`) hides the constructor behind an
		// indirection webpack's default-import interop can't see through.
		'vue$': isDev
			? path.resolve(__dirname, 'node_modules/vue/dist/vue.runtime.common.dev.js')
			: path.resolve(__dirname, 'node_modules/vue/dist/vue.runtime.common.prod.js'),
		'pinia$': path.resolve(__dirname, 'node_modules/pinia'),
		'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
	},
}

// Keep the base module rules from @nextcloud/webpack-vue-config (VUE, CSS, SCSS, JS, ASSETS).
// Only replace plugins to avoid duplicate VueLoaderPlugin (base config also registers one).
webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
]

// Force all shared packages to resolve from pipelinq's own node_modules,
// preventing the nextcloud-vue submodule's nested deps (Vue 3) from leaking in.
webpackConfig.resolve.alias['@nextcloud/dialogs'] = path.resolve(__dirname, 'node_modules/@nextcloud/dialogs')

// Bypass @nextcloud/axios's `exports` field which only declares the `import`
// condition. @nextcloud/vue's CJS bundle still uses require('@nextcloud/axios')
// and webpack 5's CommonJS resolver fails the exports check with:
//   "." is not exported under the conditions ["require","module","webpack",...]
// Aliasing the bare specifier directly at the dist entry sidesteps the
// exports field gate. Use the $-suffixed exact-match form so subpath imports
// (e.g. @nextcloud/axios/dist/foo) keep their normal resolution. Mirrors
// decidesk's `ed34703c`.
// Shim re-exports @nextcloud/axios's `default` so `require('@nextcloud/axios')`
// yields the axios instance (with `.interceptors`) — see build/nextcloud-axios-default.cjs.
// A plain alias to dist/index.cjs handed over the ESM namespace `{ default, … }`,
// which nc-vue's interop double-wrapped so `.interceptors` was undefined →
// password-confirmation blanked the app at mount.
webpackConfig.resolve.alias['@nextcloud/axios$'] = path.resolve(__dirname, 'build/nextcloud-axios-default.cjs')

// nc-vue ≥165 (CnRelatedObjectsWidget → @nextcloud/files → @nextcloud/paths;
// notify_push) pulls these ESM-only packages that declare only the `import`
// export condition, so nc-vue's CJS bundle's require() fails webpack 5's
// CommonJS exports check ("." is not exported…). Alias to the ESM entry.
webpackConfig.resolve.alias['@nextcloud/paths$'] = path.resolve(__dirname, 'node_modules/@nextcloud/paths/dist/index.mjs')
webpackConfig.resolve.alias['@nextcloud/notify_push$'] = path.resolve(__dirname, 'node_modules/@nextcloud/notify_push/dist/index.js')

// Pin vue-demi to its Vue-2.7 variant. pinia + @vueuse (bundled by webpack from
// our own node_modules) import `vue-demi`, whose default `lib/index.mjs` does an
// unguarded `import Vue from 'vue'; Vue.util.warn` (+ observable/defineComponent)
// that blanks the app at mount. nc-vue fixes its OWN bundled copy the same way in
// rollup; mirror it for the consumer's copies. `lib/v2.7/index.mjs` is a static
// `import Vue from 'vue'; export * from 'vue'`, so every Vue-2.7 static resolves.
webpackConfig.resolve.alias['vue-demi$'] = path.resolve(__dirname, 'node_modules/vue-demi/lib/v2.7/index.mjs')

// @nextcloud/files (pulled transitively via @nextcloud/axios → @nextcloud/auth)
// references the Node core `stream` module, which webpack 5 does not polyfill for
// the browser. It is on a code path the app never hits, so provide an empty module.
webpackConfig.resolve.fallback = {
	...(webpackConfig.resolve.fallback || {}),
	stream: false,
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
