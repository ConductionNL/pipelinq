const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

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
const useLocalLib = fs.existsSync(localLib)

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
		'vue$': path.resolve(__dirname, 'node_modules/vue'),
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
webpackConfig.resolve.alias['@nextcloud/axios$'] = path.resolve(__dirname, 'node_modules/@nextcloud/axios/dist/index.cjs')

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

module.exports = webpackConfig
