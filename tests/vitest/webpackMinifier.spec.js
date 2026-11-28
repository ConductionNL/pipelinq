// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * The production minifier must drop what webpack marked unused. esbuild's
 * transform API does not, which shipped every library whole (all 7,447
 * @mdi/js icons in the main chunk and in each dashboard-widget entry). swc
 * does, and keeps the legal comments in the bundle.
 */
import { createRequire } from 'node:module'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)

/**
 * Load webpack.config.js as the production build does.
 *
 * @return {object} The config.
 */
function productionConfig() {
	const before = { ...process.env }
	process.env.NODE_ENV = 'production'
	process.env.npm_package_name = process.env.npm_package_name || 'pipelinq'
	process.env.npm_package_version = process.env.npm_package_version || '0.0.0'
	try {
		const path = require.resolve('../../webpack.config.js')
		delete require.cache[path]
		return require(path)
	} finally {
		process.env = before
	}
}

describe('webpack production minifier', () => {
	it('minifies with swc, which removes unused declarations, and keeps legal comments inline', () => {
		const TerserPlugin = require('terser-webpack-plugin')
		const config = productionConfig()
		const [minimizer] = config.optimization.minimizer
		expect(minimizer).toBeInstanceOf(TerserPlugin)
		expect(minimizer.options.minimizer.implementation).toBe(TerserPlugin.swcMinify)
		expect(minimizer.options.extractComments).toBe(false)
		expect(minimizer.options.minimizer.options.format.comments).toBe('some')
	})

	it('drops an unused declaration where esbuild keeps it', async () => {
		const { minify } = require('@swc/core')
		const src = '(function(){var used="KEEP_ME";var unused="DROP_ME";console.log(used)})()'
		const { code } = await minify(src, { compress: true, mangle: true })
		expect(code).toContain('KEEP_ME')
		expect(code).not.toContain('DROP_ME')
	})
})
