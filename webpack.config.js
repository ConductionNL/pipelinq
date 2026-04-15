const path = require('path')
const fs = require('fs')
const webpack = require('webpack')
const webpackConfig = require('@nextcloud/webpack-vue-config')
const { VueLoaderPlugin } = require('vue-loader')
const NodePolyfillPlugin = require('node-polyfill-webpack-plugin')

const buildMode = process.env.NODE_ENV
const isDev = buildMode === 'development'
webpackConfig.devtool = isDev ? 'cheap-source-map' : 'source-map'

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
}

// Use local source when available (monorepo dev), otherwise fall back to npm package
const localLib = path.resolve(__dirname, '../nextcloud-vue/src')
const useLocalLib = fs.existsSync(localLib)

// Extend the base resolve config (preserves defaults from @nextcloud/webpack-vue-config)
webpackConfig.resolve = webpackConfig.resolve || {}
webpackConfig.resolve.modules = [path.resolve(__dirname, 'node_modules'), 'node_modules']
webpackConfig.resolve.alias = {
	...(webpackConfig.resolve.alias || {}),
	'@': path.resolve(__dirname, 'src'),
	...(useLocalLib ? { '@conduction/nextcloud-vue': localLib } : {}),
	vue$: path.resolve(__dirname, 'node_modules/vue'),
	pinia$: path.resolve(__dirname, 'node_modules/pinia'),
	'@nextcloud/vue$': path.resolve(__dirname, 'node_modules/@nextcloud/vue'),
	'@nextcloud/dialogs': path.resolve(__dirname, 'node_modules/@nextcloud/dialogs'),
}

webpackConfig.plugins = [
	new VueLoaderPlugin(),
	new NodePolyfillPlugin({
		additionalAliases: ['process'],
	}),
	new webpack.DefinePlugin({ appName: JSON.stringify(appId) }),
	new webpack.DefinePlugin({ appVersion: JSON.stringify(process.env.npm_package_version) }),
]

module.exports = webpackConfig
